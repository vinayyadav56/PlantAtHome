<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Swaps the raw S3 base URL for the CDN base (filesystems.disks.s3.url) across
 * every stored media reference — URL columns and JSON image blobs alike.
 * The mechanic is one string replacement, applied in BOTH encodings a column
 * can hold: raw ("https://…") and json_encode-escaped ("https:\/\/…") — MySQL
 * JSON columns normalize to raw, text columns keep the escaped form.
 *
 * Every changed value is snapshotted into pah_media_url_backup first (created
 * on demand, purge-command precedent) so --rollback can replay it.
 *
 *   php artisan media:rewrite-urls --dry-run    # per-table change counts
 *   php artisan media:rewrite-urls --force      # rewrite
 *   php artisan media:rewrite-urls --rollback   # restore snapshots
 */
class RewriteMediaUrlsCommand extends Command
{
    protected $signature = 'media:rewrite-urls
        {--dry-run : Count what would change and write nothing}
        {--rollback : Restore every column from pah_media_url_backup}
        {--force : Skip the interactive confirmation (for CI)}';

    protected $description = 'Rewrite raw S3 media URLs to the configured CDN base across every known column.';

    private const BACKUP_TABLE = 'pah_media_url_backup';

    /**
     * Every column that stores media URLs — bare-URL and JSON-blob columns
     * share one registry because the replacement mechanic is identical.
     * Absent tables/columns are skipped at runtime (nursery_nurseries and
     * cms_banners exist only on some environments). AuditMediaRefsCommand
     * reads this same registry.
     */
    public const REGISTRY = [
        'product_images' => ['url', 'thumbnail_url'],
        'instant_images' => ['public_url'],
        'image_generation_results' => ['public_url'],
        'catalog_product_media' => ['url'],
        'cms_banners' => ['image_url'],
        'products' => ['image', 'gallery'],
        'categories' => ['image', 'banner_image'],
        'shops' => ['logo', 'cover_image'],
        'banners' => ['image'],
        'coupons' => ['image'],
        'tags' => ['image'],
        'authors' => ['image', 'cover_image'],
        'manufacturers' => ['image', 'cover_image'],
        'flash_sales' => ['image', 'cover_image'],
        'variation_options' => ['image'],
        'user_profiles' => ['avatar'],
        'reviews' => ['photos'],
        'refunds' => ['images'],
        'nursery_nurseries' => ['logo', 'cover_image'],
    ];

    public function handle(): int
    {
        $this->ensureBackupTable();

        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $newBase = rtrim((string) config('filesystems.disks.s3.url'), '/');
        if ($newBase === '') {
            $this->error('filesystems.disks.s3.url (AWS_URL) is empty — nothing to rewrite TO. Set the CDN URL first.');
            return self::FAILURE;
        }
        $oldBase = 'https://' . config('filesystems.disks.s3.bucket')
            . '.s3.' . config('filesystems.disks.s3.region') . '.amazonaws.com';
        if ($newBase === $oldBase) {
            $this->error('filesystems.disks.s3.url IS the raw bucket URL — rewriting would be a no-op.');
            return self::FAILURE;
        }
        $oldHost = parse_url($oldBase, PHP_URL_HOST);
        // Both encodings of each base — see class docblock.
        $search  = [$oldBase, str_replace('/', '\/', $oldBase)];
        $replace = [$newBase, str_replace('/', '\/', $newBase)];

        $dry = (bool) $this->option('dry-run');
        $counts = [];
        $total = 0;

        foreach (self::REGISTRY as $table => $columns) {
            $cols = $this->presentColumns($table, $columns);
            if (!$cols) {
                continue;
            }
            $changed = 0;
            DB::table($table)->select(array_merge(['id'], $cols))
                ->where(function ($q) use ($cols, $oldHost) {
                    foreach ($cols as $c) {
                        $q->orWhere($c, 'like', "%{$oldHost}%");
                    }
                })
                ->orderBy('id')->chunkById(500, function ($rows) use ($table, $cols, $search, $replace, $dry, &$changed) {
                    $backup = [];
                    $updates = [];
                    $stamp = now();
                    foreach ($rows as $r) {
                        $rowUpdates = [];
                        foreach ($cols as $c) {
                            $old = $r->$c;
                            if ($old === null) {
                                continue;
                            }
                            $new = str_replace($search, $replace, $old);
                            if ($new !== $old) {
                                $rowUpdates[$c] = $new;
                                $backup[] = [
                                    'source_table' => $table,
                                    'row_id'       => $r->id,
                                    'column_name'  => $c,
                                    'old_value'    => $old,
                                    'new_value'    => $new,
                                    'created_at'   => $stamp,
                                ];
                            }
                        }
                        if ($rowUpdates) {
                            $updates[$r->id] = $rowUpdates;
                            $changed++;
                        }
                    }
                    if ($dry || !$updates) {
                        return;
                    }
                    // Snapshot BEFORE the update, in one transaction with it.
                    DB::transaction(function () use ($table, $backup, $updates) {
                        DB::table(self::BACKUP_TABLE)->insert($backup);
                        foreach ($updates as $id => $vals) {
                            DB::table($table)->where('id', $id)->update($vals);
                        }
                    });
                });
            if ($changed) {
                $counts[] = [$table, $changed];
                $total += $changed;
            }
        }

        $this->table(['table', 'rows ' . ($dry ? 'would change' : 'rewritten')], $counts ?: [['—', 0]]);
        if ($dry) {
            $this->comment('--dry-run: nothing written.');
            return self::SUCCESS;
        }
        if (!$total) {
            $this->comment('Nothing referenced the raw S3 base.');
            return self::SUCCESS;
        }
        Cache::flush();
        $this->info("Rewrote {$total} row(s). Snapshots in " . self::BACKUP_TABLE . ' — --rollback restores them.');
        return self::SUCCESS;
    }

    private function rollback(): int
    {
        $count = DB::table(self::BACKUP_TABLE)->count();
        if (!$count) {
            $this->error('No snapshots to restore.');
            return self::FAILURE;
        }
        if (!$this->confirmed("Restore {$count} column snapshot(s)?")) {
            return self::FAILURE;
        }

        $restored = 0;
        $skipped = 0;
        $present = [];
        // Newest-first so after repeated forward runs the OLDEST snapshot of a
        // cell is applied last and wins.
        DB::table(self::BACKUP_TABLE)->orderByDesc('id')->chunk(500, function ($rows) use (&$restored, &$skipped, &$present) {
            foreach ($rows as $b) {
                $key = "{$b->source_table}.{$b->column_name}";
                $present[$key] ??= Schema::hasTable($b->source_table)
                    && Schema::hasColumn($b->source_table, $b->column_name);
                if (!$present[$key]) {
                    $skipped++;
                    continue;
                }
                // Restore ONLY if the cell still holds what the rewrite wrote —
                // a value changed since (new upload, admin edit) must survive.
                $touched = DB::table($b->source_table)->where('id', $b->row_id)
                    ->where($b->column_name, $b->new_value)
                    ->update([$b->column_name => $b->old_value]);
                $touched ? $restored++ : $skipped++;
            }
        });

        Cache::flush();
        $this->info("Restored {$restored} snapshot(s)." . ($skipped ? " {$skipped} skipped (absent table/column or value changed since rewrite)." : ''));
        return self::SUCCESS;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function presentColumns(string $table, array $columns): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }
        return array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
    }

    private function confirmed(string $question): bool
    {
        if ($this->option('force')) {
            return true;
        }
        if ($this->confirm($question)) {
            return true;
        }
        $this->comment('Aborted.');
        return false;
    }

    private function ensureBackupTable(): void
    {
        if (Schema::hasTable(self::BACKUP_TABLE)) {
            return;
        }
        Schema::create(self::BACKUP_TABLE, function ($t) {
            $t->bigIncrements('id');
            $t->string('source_table', 64)->index();
            $t->unsignedBigInteger('row_id');
            $t->string('column_name', 64);
            $t->longText('old_value');
            $t->longText('new_value');
            $t->timestamp('created_at')->nullable();
        });
    }
}
