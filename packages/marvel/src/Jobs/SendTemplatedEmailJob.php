<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Marvel\Services\EmailService;

/**
 * Delivers one rendered email and keeps its email_logs row truthful:
 * attempts++ per try, sent + provider_message_id on success, failed + error
 * after the ladder is exhausted. Backoff = 1m/5m/15m/1h/24h (EmailService).
 */
class SendTemplatedEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries;

    public function __construct(
        public ?int $logId,
        public array $recipients,
        public string $subject,
        public string $html,
        public ?string $text,
        int $tries = 5,
    ) {
        $this->tries = max(1, min(5, $tries));
    }

    public function backoff(): array
    {
        return EmailService::backoff();
    }

    public function handle(): void
    {
        $this->bumpAttempts();

        $sent = Mail::html($this->html, function ($message) {
            $message->to($this->recipients)->subject($this->subject);
            if ($this->text !== null && $this->text !== '') {
                // Illuminate\Mail\Message forwards to Symfony Email::text() — sets the plain part.
                $message->text($this->text);
            }
            if ($this->logId !== null) {
                EmailService::tagMessage($message, $this->logId);
            }
        });

        $this->updateLog([
            'status' => 'sent',
            'provider' => config('mail.default'),
            'provider_message_id' => $sent?->getMessageId(),
            'error' => null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->updateLog([
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 2000),
        ]);
    }

    private function bumpAttempts(): void
    {
        if ($this->logId === null) {
            return;
        }
        try {
            DB::table('email_logs')->where('id', $this->logId)->increment('attempts', 1, ['updated_at' => now()]);
        } catch (\Throwable) {
        }
    }

    private function updateLog(array $fields): void
    {
        if ($this->logId === null) {
            return;
        }
        try {
            DB::table('email_logs')->where('id', $this->logId)->update($fields + ['updated_at' => now()]);
        } catch (\Throwable) {
        }
    }
}
