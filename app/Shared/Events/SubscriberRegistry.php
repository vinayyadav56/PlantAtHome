<?php

namespace App\Shared\Events;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Maps an event name to the subscriber classes that react to it. Modules
 * register their subscribers here (in their service providers) — the producer
 * never knows who consumes. Subscribers are resolved from the container and
 * invoked with an IntegrationMessage.
 *
 * A subscriber is any class with a `handle(IntegrationMessage $message): void`
 * method (or an __invoke). Per-subscriber isolation and idempotency (exactly-
 * once processing per event) are enforced by the OutboxRelay via a durable
 * processed-events ledger — subscribers no longer need their own dedupe. This
 * is the in-process bus; swapping it for RabbitMQ later means changing only the
 * relay's transport, not this registry.
 */
final class SubscriberRegistry
{
    /** @var array<string, string[]> event name => subscriber class names */
    private array $subscribers = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function listen(string $eventName, string $subscriberClass): void
    {
        $this->subscribers[$eventName][] = $subscriberClass;
    }

    /** @return string[] distinct subscriber class names for an event, in registration order */
    public function subscribersFor(string $eventName): array
    {
        return array_values(array_unique($this->subscribers[$eventName] ?? []));
    }

    /**
     * Resolve a subscriber and deliver one message to it. Fails loudly on a
     * misregistration (a class exposing neither handle() nor __invoke) rather
     * than silently dropping the event.
     */
    public function invoke(string $subscriberClass, IntegrationMessage $message): void
    {
        $subscriber = $this->container->make($subscriberClass);

        if (method_exists($subscriber, 'handle')) {
            $subscriber->handle($message);

            return;
        }

        if (is_callable($subscriber)) {
            $subscriber($message);

            return;
        }

        throw new RuntimeException(sprintf(
            'Subscriber [%s] for event [%s] exposes neither handle() nor __invoke().',
            $subscriberClass,
            $message->eventName,
        ));
    }
}
