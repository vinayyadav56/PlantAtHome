<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Infrastructure\Models\NotificationLog;
use App\Modules\Notifications\Infrastructure\Models\Template;
use App\Shared\Events\IntegrationMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Renders per-event templates and records deliveries. Driven by outbox
 * subscribers, so notification work never runs in the request path. Real
 * channel delivery (email/SMS/WhatsApp) would be a queued job per log row; here
 * the row is written and marked sent.
 */
class NotificationService
{
    public function createTemplate(array $data): Template
    {
        return Template::updateOrCreate(
            ['event_name' => $data['event_name'], 'channel' => $data['channel']],
            ['subject' => $data['subject'] ?? null, 'body' => $data['body'], 'is_active' => $data['is_active'] ?? true],
        );
    }

    /** Fan a domain event out to every active template for it. Idempotent per (event_id, channel). */
    public function notifyFromEvent(IntegrationMessage $message): void
    {
        $templates = Template::where('event_name', $message->eventName)->where('is_active', true)->get();
        $payload = $message->payload;
        $recipient = $payload['customer_uuid'] ?? $payload['nursery_id'] ?? null;

        foreach ($templates as $template) {
            if (NotificationLog::where('event_id', $message->eventId)->where('channel', $template->channel)->exists()) {
                continue; // idempotent across at-least-once redelivery
            }

            NotificationLog::create([
                'event_id'   => $message->eventId,
                'event_name' => $message->eventName,
                'channel'    => $template->channel,
                'recipient'  => $recipient,
                'subject'    => $template->subject ? $this->render($template->subject, $payload) : null,
                'body'       => $this->render($template->body, $payload),
                'status'     => 'sent',   // a real driver would enqueue + update this
                'sent_at'    => Carbon::now(),
            ]);
        }
    }

    /** Replace {{ dot.path }} placeholders from the event payload. */
    private function render(string $template, array $payload): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($payload) {
            $value = Arr::get($payload, $m[1]);

            return is_scalar($value) ? (string) $value : json_encode($value);
        }, $template);
    }
}
