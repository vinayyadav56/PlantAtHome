<?php

namespace Tests\Feature\Shared;

use App\Shared\Domain\AbstractDomainEvent;
use App\Shared\Events\IntegrationMessage;
use App\Shared\Events\OutboxEventPublisher;
use App\Shared\Events\OutboxRelay;
use App\Shared\Events\OutboxStatus;
use App\Shared\Events\SubscriberRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 0 acceptance: a domain event published inside a DB transaction reaches
 * a subscriber via the transactional outbox relay — and rolls back with its
 * transaction (atomicity), and is delivered at-least-once but processed once
 * (idempotency). Runs on an isolated in-memory sqlite connection so it never
 * touches the real database.
 */
class OutboxEventBusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolated in-memory DB — one shared connection for publisher + relay.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('outbox', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('event_id')->unique();
            $table->string('event_name');
            $table->unsignedSmallInteger('version')->default(1);
            $table->json('payload')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        SpySubscriber::$received = [];
        FlakySubscriber::$calls = 0;
    }

    private function registry(): SubscriberRegistry
    {
        return $this->app->make(SubscriberRegistry::class);
    }

    public function test_event_in_a_committed_transaction_is_delivered_to_its_subscriber(): void
    {
        $this->registry()->listen('test.ping', SpySubscriber::class);
        $publisher = new OutboxEventPublisher(DB::connection());
        $event = new TestPingEvent('hello');

        DB::transaction(fn () => $publisher->publish($event));

        // Written to outbox, but NOT yet delivered (delivery is async via relay).
        $this->assertDatabaseHas('outbox', ['event_id' => $event->eventId(), 'status' => OutboxStatus::PENDING]);
        $this->assertSame([], SpySubscriber::$received);

        $delivered = (new OutboxRelay(DB::connection(), $this->registry(), logger()))->relay();

        $this->assertSame(1, $delivered);
        $this->assertSame([$event->eventId()], SpySubscriber::$received);
        $this->assertDatabaseHas('outbox', ['event_id' => $event->eventId(), 'status' => OutboxStatus::PUBLISHED]);
    }

    public function test_event_rolls_back_with_a_failed_transaction(): void
    {
        $publisher = new OutboxEventPublisher(DB::connection());
        $event = new TestPingEvent('doomed');

        try {
            DB::transaction(function () use ($publisher, $event) {
                $publisher->publish($event);
                throw new RuntimeException('business rule failed');
            });
        } catch (RuntimeException) {
            // expected
        }

        // No phantom event: the outbox row rolled back with the transaction.
        $this->assertDatabaseMissing('outbox', ['event_id' => $event->eventId()]);
    }

    public function test_relay_is_idempotent_across_passes(): void
    {
        $this->registry()->listen('test.ping', SpySubscriber::class);
        $publisher = new OutboxEventPublisher(DB::connection());
        $event = new TestPingEvent('once');

        DB::transaction(fn () => $publisher->publish($event));

        $relay = new OutboxRelay(DB::connection(), $this->registry(), logger());
        $relay->relay();
        $relay->relay(); // second pass must NOT re-deliver

        $this->assertSame([$event->eventId()], SpySubscriber::$received);
    }

    public function test_a_failing_subscriber_leaves_the_event_pending_for_retry(): void
    {
        $this->registry()->listen('test.flaky', FlakySubscriber::class);
        $publisher = new OutboxEventPublisher(DB::connection());
        $event = new TestFlakyEvent();

        DB::transaction(fn () => $publisher->publish($event));

        $relay = new OutboxRelay(DB::connection(), $this->registry(), logger());
        // First pass: subscriber throws → row stays pending, attempts incremented.
        $this->assertSame(0, $relay->relay());
        $this->assertDatabaseHas('outbox', ['event_id' => $event->eventId(), 'status' => OutboxStatus::PENDING, 'attempts' => 1]);

        // Second pass: subscriber succeeds → delivered.
        $this->assertSame(1, $relay->relay());
        $this->assertDatabaseHas('outbox', ['event_id' => $event->eventId(), 'status' => OutboxStatus::PUBLISHED]);
    }
}

/* ── test doubles ─────────────────────────────────────────────────────────── */

class TestPingEvent extends AbstractDomainEvent
{
    public function __construct(private readonly string $note = '')
    {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'test.ping';
    }

    public function payload(): array
    {
        return ['note' => $this->note];
    }
}

class TestFlakyEvent extends AbstractDomainEvent
{
    public function eventName(): string
    {
        return 'test.flaky';
    }

    public function payload(): array
    {
        return [];
    }
}

class SpySubscriber
{
    public static array $received = [];

    public function handle(IntegrationMessage $message): void
    {
        self::$received[] = $message->eventId;
    }
}

class FlakySubscriber
{
    public static int $calls = 0;

    public function handle(IntegrationMessage $message): void
    {
        self::$calls++;
        if (self::$calls === 1) {
            throw new RuntimeException('transient failure');
        }
    }
}
