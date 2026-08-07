<?php

namespace Marvel\Services;

use App\Modules\Marketing\Domain\VariableMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Marvel\Database\Models\Settings;
use Marvel\Jobs\SendTemplatedEmailJob;
use Symfony\Component\Mailer\Header\MetadataHeader;

/**
 * The single door for transactional email. Every send goes:
 *
 *   send(eventKey) → event registry (enabled? template?) → render {{vars}}
 *   → sanitize → email_logs row (queued) → SendTemplatedEmailJob on the
 *   event's queue → sent/failed on the log → SendGrid webhook advances
 *   delivered/opened/clicked/bounced.
 *
 * Design rules:
 *  - NEVER throws to the caller — a broken template must not break checkout.
 *  - An event with no template runs the caller's legacy fallback, so wiring
 *    EmailService can never silence an email that used to send.
 *  - Variable VALUES are HTML-escaped into the body (stored-XSS defense —
 *    same rule as marketing's VariableMapper); the template itself is
 *    authored HTML and renders as-is (super-admin authored, still passed
 *    through a script/event-handler strip as defense in depth).
 *  - Sensitive data keys are redacted before landing in email_logs.meta;
 *    rows whose redaction removed keys are marked non-retryable.
 */
class EmailService
{
    /**
     * Data keys never persisted to email_logs.meta. Substring match — catches
     * reset_token, temp_password, api_key… 'code' stays exact so coupon_code
     * style values survive for retries.
     */
    private const REDACT_CONTAINS = ['password', 'token', 'otp', 'secret'];
    private const REDACT_EXACT = ['code', 'api_key'];

    private const RETRY_BACKOFF = [60, 300, 900, 3600, 86400];

    /**
     * Send a registered event's email.
     * Returns: the email_logs id when the engine queued it, TRUE when the
     * legacy fallback handled it (e.g. pre-migration environments), null when
     * nothing was sent. Callers wanting "was an email handed off?" should
     * truthy-check the result, not compare to null.
     */
    public function send(string $eventKey, string|array $to, array $data = [], array $opts = []): int|bool|null
    {
        try {
            $recipients = $this->recipients($to);
            if (empty($recipients)) {
                return null;
            }

            $event = $this->event($eventKey);
            if ($event === null) {
                Log::warning("EmailService: unregistered event '{$eventKey}'");
                return $this->runFallback($opts);
            }
            if (!$event->enabled) {
                return $this->log($eventKey, $recipients[0], null, null, 'skipped', ['reason' => 'event disabled']);
            }

            $template = $event->template_id ? $this->template((int) $event->template_id) : null;
            if ($template === null || $template->status !== 'active') {
                // No usable template: the legacy Mailable path still delivers.
                $this->runFallback($opts);
                return $this->log($eventKey, $recipients[0], null, null, 'queued', ['legacy' => true]);
            }

            $vars = array_merge($this->globalVars(), $data);
            $subject = VariableMapper::render((string) $template->subject, $vars);
            $html = $this->sanitize(VariableMapper::render((string) $template->html_body, $vars, true));
            $text = $template->text_body ? VariableMapper::render((string) $template->text_body, $vars) : null;

            $safeData = $this->redact($data);
            $logId = $this->log($eventKey, implode(',', $recipients), $template->slug, $subject, 'queued', [
                'data' => $safeData['data'],
                'retryable' => $safeData['clean'],
            ]);

            SendTemplatedEmailJob::dispatch($logId, $recipients, $subject, $html, $text, (int) $event->tries)
                ->onQueue((string) ($event->queue ?: 'default'));

            return $logId;
        } catch (\Throwable $e) {
            Log::error('EmailService::send failed: ' . $e->getMessage(), ['event' => $eventKey]);
            return $this->runFallback($opts);
        }
    }

    /** Render a template against sample data — the admin preview. */
    public function renderPreview(string $slug, array $sampleData = []): ?array
    {
        $t = DB::table('email_templates')->where('slug', $slug)->first();
        if ($t === null) {
            return null;
        }
        // Order matters: auto "[var]" samples fill only what globals/sampleData
        // don't provide — real company values must never be masked by samples.
        $vars = array_merge($this->sampleFor($t), $this->globalVars(), $sampleData);
        return [
            'subject' => VariableMapper::render((string) $t->subject, $vars),
            'html' => $this->sanitize(VariableMapper::render((string) $t->html_body, $vars, true)),
            'text' => $t->text_body ? VariableMapper::render((string) $t->text_body, $vars) : null,
            'variables' => VariableMapper::extract((string) $t->subject, (string) $t->html_body, (string) ($t->text_body ?? '')),
        ];
    }

    /**
     * Synchronous test send — the admin wants the provider's answer NOW.
     * Returns [ok, message, provider_message_id].
     */
    public function sendTest(string $slug, string $to, array $sampleData = []): array
    {
        $rendered = $this->renderPreview($slug, $sampleData);
        if ($rendered === null) {
            return ['ok' => false, 'message' => 'Template not found', 'provider_message_id' => null];
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Invalid email address', 'provider_message_id' => null];
        }
        try {
            $sent = Mail::html($rendered['html'], function ($message) use ($to, $rendered) {
                $message->to($to)->subject('[TEST] ' . $rendered['subject']);
            });
            $id = $sent?->getMessageId();
            $this->log('test.send', $to, $slug, $rendered['subject'], 'sent', ['test' => true], $id);
            return ['ok' => true, 'message' => 'Delivered to the provider (mailer: ' . config('mail.default') . ')', 'provider_message_id' => $id];
        } catch (\Throwable $e) {
            $this->log('test.send', $to, $slug, $rendered['subject'], 'failed', ['test' => true, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => $e->getMessage(), 'provider_message_id' => null];
        }
    }

    /** Re-dispatch a failed log row (admin retry). Returns false when not retryable. */
    public function retry(int $logId): bool
    {
        $row = DB::table('email_logs')->where('id', $logId)->first();
        if ($row === null || !in_array($row->status, ['failed', 'bounced'], true)) {
            return false;
        }
        $meta = json_decode((string) $row->meta, true) ?: [];
        if (($meta['retryable'] ?? true) === false || empty($row->template_slug)) {
            return false;
        }
        $t = DB::table('email_templates')->where('slug', $row->template_slug)->first();
        if ($t === null) {
            return false;
        }
        $event = $this->event($row->event_key);
        $vars = array_merge($this->globalVars(), (array) ($meta['data'] ?? []));
        $subject = VariableMapper::render((string) $t->subject, $vars);
        $html = $this->sanitize(VariableMapper::render((string) $t->html_body, $vars, true));
        $text = $t->text_body ? VariableMapper::render((string) $t->text_body, $vars) : null;

        DB::table('email_logs')->where('id', $logId)->update(['status' => 'queued', 'error' => null, 'updated_at' => now()]);
        SendTemplatedEmailJob::dispatch($logId, explode(',', $row->recipient), $subject, $html, $text, (int) ($event->tries ?? 3))
            ->onQueue((string) ($event->queue ?? 'default'));
        return true;
    }

    /** The retry ladder shared with SendTemplatedEmailJob. */
    public static function backoff(): array
    {
        return self::RETRY_BACKOFF;
    }

    /** Attach the log id as a SendGrid custom arg so the event webhook can correlate. */
    public static function tagMessage($message, int $logId): void
    {
        try {
            $message->getHeaders()->add(new MetadataHeader('email_log_id', (string) $logId));
        } catch (\Throwable) {
            // non-symfony transport (log/array driver) — fine without correlation
        }
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function recipients(string|array $to): array
    {
        $list = is_array($to) ? $to : [$to];
        return array_values(array_filter(array_map('trim', $list), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    private function event(string $key): ?object
    {
        return Cache::remember("email_event:{$key}", 60, function () use ($key) {
            try {
                return DB::table('email_events')->where('event_key', $key)->first();
            } catch (\Throwable) {
                return null; // pre-migration — fallback path handles it
            }
        });
    }

    private function template(int $id): ?object
    {
        return Cache::remember("email_template:{$id}", 60, function () use ($id) {
            try {
                return DB::table('email_templates')->where('id', $id)->first();
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /** Company-wide variables available to every template. */
    public function globalVars(): array
    {
        return Cache::remember('email_global_vars', 300, function () {
            $options = [];
            try {
                $options = (array) (Settings::first()->options ?? []);
            } catch (\Throwable) {
            }
            $contact = (array) ($options['contactDetails'] ?? []);
            $logo = (array) ($options['logo'] ?? []);
            return [
                'company_name' => (string) ($options['siteTitle'] ?? config('app.name', 'PlantAtHome')),
                'company_email' => (string) ($contact['emailAddress'] ?? config('mail.from.address', '')),
                'company_phone' => (string) ($contact['contact'] ?? ''),
                'company_address' => is_string($contact['location'] ?? null) ? $contact['location'] : (string) (($contact['location']['formattedAddress'] ?? '') ?: ''),
                'company_logo' => (string) ($logo['original'] ?? ''),
                'current_year' => (string) now()->year,
                'login_url' => rtrim((string) config('shop.shop_url', ''), '/') . '/signin',
                'support_link' => rtrim((string) config('shop.shop_url', ''), '/') . '/contact',
            ];
        });
    }

    /**
     * Defense-in-depth strip of active content from the FINAL html. Templates
     * are super-admin authored; this guards against a pasted payload, not the
     * author. Values were already entity-escaped by VariableMapper.
     */
    private function sanitize(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*/i', '$1=$2#', $html) ?? $html;
        return $html;
    }

    /** @return array{data: array, clean: bool} clean=false when keys were redacted (row not retryable) */
    private function redact(array $data): array
    {
        $clean = true;
        $out = [];
        foreach ($data as $k => $v) {
            $key = strtolower((string) $k);
            $sensitive = in_array($key, self::REDACT_EXACT, true)
                || array_filter(self::REDACT_CONTAINS, fn ($frag) => str_contains($key, $frag));
            if ($sensitive) {
                $clean = false;
                continue;
            }
            $out[$k] = is_scalar($v) || $v === null ? $v : json_decode(json_encode($v), true);
        }
        return ['data' => $out, 'clean' => $clean];
    }

    /** Auto sample data for previews: every declared variable → "[variable]". */
    private function sampleFor(object $template): array
    {
        $declared = json_decode((string) ($template->variables ?? '[]'), true) ?: [];
        $found = VariableMapper::extract((string) $template->subject, (string) $template->html_body);
        $sample = [];
        foreach (array_unique(array_merge($declared, $found)) as $v) {
            $sample[$v] = '[' . $v . ']';
        }
        return $sample;
    }

    private function log(string $eventKey, string $recipient, ?string $slug, ?string $subject, string $status, array $meta = [], ?string $messageId = null): ?int
    {
        try {
            return (int) DB::table('email_logs')->insertGetId([
                'event_key' => $eventKey,
                'template_slug' => $slug,
                'recipient' => mb_substr($recipient, 0, 191),
                'subject' => $subject !== null ? mb_substr($subject, 0, 500) : null,
                'status' => $status,
                'provider' => config('mail.default'),
                'provider_message_id' => $messageId,
                'meta' => json_encode($meta),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            return null; // pre-migration or DB hiccup — never break the caller
        }
    }

    /** Runs the caller's legacy path. TRUE = it executed cleanly (mail handed off). */
    private function runFallback(array $opts): ?bool
    {
        if (isset($opts['fallback']) && is_callable($opts['fallback'])) {
            try {
                ($opts['fallback'])();
                return true;
            } catch (\Throwable $e) {
                Log::warning('EmailService legacy fallback failed: ' . $e->getMessage());
            }
        }
        return null;
    }
}
