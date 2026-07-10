<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Domain\Events\HealthPinged;
use App\Shared\Events\EventPublisher;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform health + a demo ping. `show()` is a cheap, side-effect-free health
 * probe returning the standard envelope (safe for load balancers). `ping()`
 * exercises the transactional outbox from a real HTTP endpoint: it emits a
 * HealthPinged domain event inside a DB transaction; the outbox relay later
 * delivers it to subscribers (Section 8/9 flow, in miniature).
 */
final class HealthController extends ApiController
{
    /** GET /api/v1/health */
    public function show(): JsonResponse
    {
        $db = 'unknown';
        try {
            DB::connection()->getPdo();
            $db = 'connected';
        } catch (\Throwable $e) {
            $db = 'unavailable';
        }

        return $this->ok([
            'status'  => 'ok',
            'service' => 'plantathome-api (v2 modular)',
            'db'      => $db,
            'env'     => config('app.env'),
            'time'    => Carbon::now()->toIso8601String(),
        ]);
    }

    /** POST /api/v1/platform/ping — emits a domain event through the outbox. */
    public function ping(Request $request, EventPublisher $events): JsonResponse
    {
        $note = (string) $request->input('note', '');

        $event = new HealthPinged(source: 'http', note: $note);

        // The event is written to the outbox INSIDE this transaction, so it is
        // persisted iff the (here trivial) business work commits.
        DB::transaction(function () use ($events, $event) {
            $events->publish($event);
        });

        return $this->ok([
            'accepted' => true,
            'event_id' => $event->eventId(),
            'event'    => $event->eventName(),
        ], status: 202);
    }
}
