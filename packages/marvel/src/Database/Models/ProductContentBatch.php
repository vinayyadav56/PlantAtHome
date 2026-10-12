<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One bulk AI product-content generation run (from the admin product listing)
 * + its audit trail. Progress counters are mutated only via atomic increments.
 */
class ProductContentBatch extends Model
{
    public const STATUS_PENDING               = 'pending';
    public const STATUS_PROCESSING            = 'processing';
    public const STATUS_COMPLETED             = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATUS_FAILED                = 'failed';
    public const STATUS_CANCELLED             = 'cancelled';

    /** options.mode of a "generate missing plant content" run (server-selected plants). */
    public const MODE_MISSING = 'missing';

    /** Statuses where background work is still running / expected. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
    ];

    /** Statuses retry-failed accepts. */
    public const RETRYABLE_STATUSES = [
        self::STATUS_COMPLETED_WITH_ERRORS,
        self::STATUS_FAILED,
    ];

    protected $table = 'product_content_batches';

    public $guarded = [];

    protected $casts = [
        'options'            => 'json',
        'row_errors'         => 'json',
        'estimated_cost'     => 'float',
        'last_dispatched_at' => 'datetime',
        'cancelled_at'       => 'datetime',
        'completed_at'       => 'datetime',
    ];

    protected $appends = ['display_id', 'progress'];

    public function getDisplayIdAttribute(): string
    {
        return sprintf('CONTENT%06d', $this->id);
    }

    /** 0–100, from counters only (no row scans). */
    public function getProgressAttribute(): int
    {
        $total = (int) $this->total_rows;
        if ($total < 1) {
            return in_array($this->status, self::ACTIVE_STATUSES, true) ? 0 : 100;
        }

        return (int) min(100, floor((int) $this->processed_count * 100 / $total));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * The terminal status a drained run gets, from its rows (never the counters).
     * A missing-content run in which every row failed (quota, bad key…) is a failed
     * run; a manual run keeps completed_with_errors. Shared by the job's finalize
     * and the sweeper's, so the two can't disagree.
     */
    public function finalStatus(): string
    {
        $counts = ProductContentJob::where('batch_id', $this->id)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');
        $failed = (int) ($counts[ProductContentJob::STATUS_FAILED] ?? 0);

        if ($failed === 0) {
            return self::STATUS_COMPLETED;
        }

        return $this->isMissingMode() && $failed === (int) $counts->sum()
            ? self::STATUS_FAILED
            : self::STATUS_COMPLETED_WITH_ERRORS;
    }

    public function isMissingMode(): bool
    {
        return ($this->options['mode'] ?? null) === self::MODE_MISSING;
    }

    /** The missing-content run still in flight, if any (one at a time). */
    public static function activeMissingRun(): ?self
    {
        return static::where('options->mode', self::MODE_MISSING)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    public static function latestMissingRun(): ?self
    {
        return static::where('options->mode', self::MODE_MISSING)->latest('id')->first();
    }

    /**
     * Live run summary, aggregated from the rows (never from the counters, so
     * it can't drift): status counts, progress, field/token/cost metrics,
     * recent throughput + ETA, and the top error reasons. Works for both modes.
     */
    public function summary(): array
    {
        $groups = $this->jobs()->toBase()
            ->selectRaw('status, count(*) as n, sum(fields_missing) as fm, sum(fields_generated) as fg, sum(prompt_tokens) as pt, sum(completion_tokens) as ct, sum(ai_ms) as ms, sum(case when ai_ms > 0 then 1 else 0 end) as timed, sum(attempts) as att, max(updated_at) as last_at')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        // SUM() comes back as a string (or null) on MySQL.
        $count = fn (string $status) => (int) ($groups[$status]->n ?? 0);
        $sum   = fn (string $col) => (int) $groups->sum(fn ($g) => (int) $g->{$col});

        $total     = $sum('n');
        $open      = array_sum(array_map($count, ProductContentJob::OPEN_STATUSES));
        $processed = $total - $open;
        $failed    = $count(ProductContentJob::STATUS_FAILED);
        $prompt    = $sum('pt');
        $output    = $sum('ct');
        $timed     = $sum('timed');
        $last      = $groups->max('last_at');
        $price     = (array) config('content-batches.token_price_per_million', []);

        // Throughput over the last 10 minutes (or the run's lifetime if younger) while it
        // runs; once it has ended, over the run's own span, so the figure doesn't decay.
        $end     = $this->isActive() ? now() : ($this->completed_at ?? $this->cancelled_at ?? now());
        $since   = max($this->created_at ?? $end, $end->copy()->subMinutes(10));
        $seconds = $end->diffInSeconds($since);
        $recent  = $this->jobs()->whereNotIn('status', ProductContentJob::OPEN_STATUSES)->where('updated_at', '>=', $since)->count();
        $rate    = $seconds > 0 ? round($recent * 60 / $seconds, 1) : null;

        $errors = $this->jobs()->toBase()
            ->where('status', ProductContentJob::STATUS_FAILED)
            ->whereNotNull('last_error')
            ->selectRaw('substr(last_error, 1, 120) as message, count(*) as n')
            ->groupBy('message')
            ->orderByDesc('n')
            ->limit(5)
            ->get()
            ->map(fn ($e) => ['message' => (string) $e->message, 'count' => (int) $e->n])
            ->all();

        return [
            'total'                    => $total,
            'pending'                  => $count(ProductContentJob::STATUS_PENDING),
            'retrying'                 => $count(ProductContentJob::STATUS_RETRYING),
            'processing'               => $count(ProductContentJob::STATUS_PROCESSING),
            'completed'                => $count(ProductContentJob::STATUS_COMPLETED),
            'needs_review'             => $count(ProductContentJob::STATUS_NEEDS_REVIEW),
            'failed'                   => $failed,
            'skipped'                  => $count(ProductContentJob::STATUS_SKIPPED),
            'processed'                => $processed,
            'progress'                 => $total > 0 ? (int) floor($processed * 100 / $total) : 100,
            'fields_initially_missing' => (int) ($this->options['fields_missing_initial'] ?? $sum('fm')),
            'fields_generated'         => $sum('fg'),
            // Each row's fields_missing is re-counted when it finishes.
            'fields_still_missing'     => $sum('fm'),
            // attempts counts failures; a row's final failure isn't a retry.
            'retries'                  => max(0, $sum('att') - $failed),
            'last_activity_at'         => $last ? Carbon::parse($last)->toJSON() : null,
            'tokens'                   => ['prompt' => $prompt, 'completion' => $output],
            'cost_usd'                 => round(($prompt * (float) ($price['input'] ?? 0) + $output * (float) ($price['output'] ?? 0)) / 1_000_000, 6),
            'avg_ai_ms'                => $timed > 0 ? (int) round($sum('ms') / $timed) : null,
            'rows_per_minute'          => $rate,
            'eta_seconds'              => $this->isActive() && $rate > 0 ? (int) ceil($open * 60 / $rate) : null,
            'errors'                   => $errors,
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ProductContentJob::class, 'batch_id');
    }
}
