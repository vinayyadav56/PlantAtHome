<?php

namespace Marvel\Console;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes product imagery from the catalog: the `image` / `gallery` references first, and — as a
 * SEPARATE, deliberately later step — the underlying S3 objects.
 *
 * The two halves are split because only one of them can be undone. Clearing the columns is fully
 * reversible: every row is snapshotted into `pah_product_image_backup` before it is touched, and
 * --rollback replays it. Deleting the objects is not reversible at all — the media bucket has NO
 * versioning and no lifecycle rules, so an object removed here is gone for good. Running the two
 * in one pass would mean the catalog and the files disappear together, with no window in which to
 * look at a storefront full of placeholders and change your mind.
 *
 * Ordering matters for a different reason too: staging and production SHARE the media bucket
 * (both write to plantathome-media-prod). Deleting files while either environment still points at
 * them shows broken <img> to real customers. Clearing every environment's columns FIRST means the
 * storefront degrades to the brand placeholder instead.
 *
 *   php artisan plantathome:purge-product-images --dry-run     # counts + manifest, no writes
 *   php artisan plantathome:purge-product-images               # clear columns, snapshot, keep files
 *   php artisan plantathome:purge-product-images --rollback    # put the references back
 *   php artisan plantathome:purge-product-images --purge-files # DESTROY the S3 objects
 *
 * --purge-files reads the manifest recorded by the clearing pass, so it can only ever delete
 * objects this command has already accounted for.
 */
class PurgeProductImagesCommand extends Command
{
    protected $signature = 'plantathome:purge-product-images
        {--dry-run : Report what would change and record nothing}
        {--type= : Limit to one type_id (default: every product in the catalog)}
        {--rollback : Restore image/gallery from the snapshot table}
        {--purge-files : Delete the S3 objects named in the manifest. IRREVERSIBLE}
        {--force : Skip the interactive confirmation (for CI)}';

    protected $description = 'Clear product image/gallery references, and optionally destroy the S3 objects behind them.';

    private const BACKUP_TABLE = 'pah_product_image_backup';
    private const LIBRARY_BACKUP = 'pah_image_library_backup';

    /**
     * The stores that hold product imagery, beyond the products table itself.
     *
     * `products.image` / `products.gallery` are a DERIVED CACHE, not the source: `product_images`
     * is the library, and .railway/start.sh runs `plantathome:sync-image-columns` on every staging
     * boot, which rebuilt all 4,294 products' columns from it minutes after they were first
     * cleared. Purging the cache alone is undone by the next deploy — and had the files been
     * deleted in between, the rebuild would have pointed every product at a 404.
     *
     * Deliberately NOT listed, though they reference the same bucket: categories.image /
     * banner_image, nursery_nurseries.logo / cover_image, delivery_partners.live_photo. Those are
     * not product imagery and must survive. pah_product_image_backup is this command's own
     * rollback data and is likewise left alone.
     */
    private const LIBRARIES = [
        ['table' => 'product_images',            'columns' => ['url', 'thumbnail_url']],
        ['table' => 'catalog_product_media',     'columns' => ['url']],
        ['table' => 'instant_images',            'columns' => ['public_url']],
        ['table' => 'image_generation_results',  'columns' => ['public_url']],
        ['table' => 'pah_plant_image_backup',    'columns' => ['image', 'gallery']],
    ];

    /**
     * The two layouts product imagery actually uses, as an allowlist. Anything else in the bucket
     * — backups/, shop-assets/, the site logo, category banners — must survive, so a key matching
     * neither is refused rather than skipped quietly: a manifest containing one means the collector
     * is wrong, and deleting the safe-looking remainder would hide that.
     *
     *   plants/{slug}/{n}.jpg        the image pipeline — 7,230 of 7,240 objects in the live catalog
     *   {media.id}/{file}            spatie media library, incl. its conversions/ subfolder
     *
     * The numeric form was assumed to be the only one until a dry-run showed otherwise; the guard
     * refused the whole purge, which is exactly what it is for.
     */
    private const PRODUCT_KEY_PATTERNS = [
        '/^plants\/[^\/]+\/.+/',
        '/^\d+\/.+/',
    ];

    private function isProductKey(string $key): bool
    {
        foreach (self::PRODUCT_KEY_PATTERNS as $re) {
            if (preg_match($re, $key)) {
                return true;
            }
        }
        return false;
    }

    public function handle(): int
    {
        $this->ensureBackupTable();

        if ($this->option('rollback')) {
            return $this->rollback();
        }
        if ($this->option('purge-files')) {
            return $this->purgeFiles();
        }
        return $this->clearReferences();
    }

    // ── phase 1: references ──────────────────────────────────────────────────────────────────

    private function clearReferences(): int
    {
        $dry = (bool) $this->option('dry-run');
        $q = DB::table('products')->select('id', 'type_id', 'image', 'gallery');
        if ($type = $this->option('type')) {
            $q->where('type_id', (int) $type);
        }

        $rows = 0;
        $withImage = 0;
        $withGallery = 0;
        $keys = [];
        $external = [];
        $snapshot = [];

        $q->orderBy('id')->chunk(500, function ($chunk) use (&$rows, &$withImage, &$withGallery, &$keys, &$external, &$snapshot) {
            foreach ($chunk as $r) {
                $hasImage = $this->filled($r->image);
                $hasGallery = $this->filled($r->gallery);
                if (!$hasImage && !$hasGallery) {
                    continue;
                }
                $rows++;
                $withImage += $hasImage ? 1 : 0;
                $withGallery += $hasGallery ? 1 : 0;
                $this->collectKeys($r->image, $keys, $external);
                $this->collectKeys($r->gallery, $keys, $external);
                $snapshot[] = ['product_id' => $r->id, 'image' => $r->image, 'gallery' => $r->gallery];
            }
        });

        // The libraries are the SOURCE; the product columns above are a cache built from them.
        $library = $this->scanLibraries($keys, $external);

        $keys = array_keys($keys);
        sort($keys);

        $this->line('');
        $this->info('Product image references');
        $this->line("  products carrying imagery : {$rows}");
        $this->line("    with image              : {$withImage}");
        $this->line("    with gallery            : {$withGallery}");
        $this->line('  distinct S3 objects       : ' . count($keys));
        $this->line('  image libraries (the SOURCE the cache is rebuilt from):');
        foreach ($library as $t => $n) {
            $this->line('    ' . str_pad($t, 26) . $n . ' row(s)');
        }
        if ($external) {
            // Not ours to delete and not on our bucket. Named rather than silently ignored: the
            // reference goes away with the column either way, and someone should know the count
            // does not reconcile with the object count.
            $this->line('  external URLs (left alone): ' . count($external) . ' — ' . implode(', ', array_slice(array_keys($external), 0, 3)));
        }

        if ($dry) {
            // Sample keys are printed so they can be checked against the bucket BEFORE anything is
            // destroyed. The stored URL is percent-encoded and the S3 key is not, and a mismatched
            // key is invisible at delete time: DeleteObjects reports success for an object that was
            // never there, so a silent no-op looks exactly like a clean purge.
            $this->line('');
            $this->line('  sample keys:');
            foreach (array_slice($keys, 0, 5) as $k) {
                $this->line("    {$k}");
            }
            $this->line('');
            $this->comment('--dry-run: nothing written.');
            return self::SUCCESS;
        }
        if (!$rows) {
            $this->comment('Nothing to clear.');
            return self::SUCCESS;
        }
        if (!$this->confirmed("Clear image/gallery on {$rows} products? Files are NOT touched by this step.")) {
            return self::FAILURE;
        }

        // Snapshot BEFORE the update, in one transaction with it, so a crash cannot leave the
        // columns cleared with no way back.
        DB::transaction(function () use ($snapshot, $keys) {
            $stamp = now();
            foreach (array_chunk($snapshot, 200) as $batch) {
                DB::table(self::BACKUP_TABLE)->insert(array_map(fn ($s) => $s + [
                    'manifest'   => null,
                    'created_at' => $stamp,
                ], $batch));
            }
            // One manifest row carries the key list for --purge-files. Kept in the database rather
            // than a file on disk because the two environments run on different boxes and the
            // production one is reached only through a workflow.
            DB::table(self::BACKUP_TABLE)->insert([
                'product_id' => 0,
                'image'      => null,
                'gallery'    => null,
                'manifest'   => json_encode($keys),
                'created_at' => $stamp,
            ]);

            $ids = array_column($snapshot, 'product_id');
            foreach (array_chunk($ids, 500) as $batch) {
                DB::table('products')->whereIn('id', $batch)->update(['image' => null, 'gallery' => null]);
            }

            $this->clearLibraries();
        });

        Cache::flush();

        $this->line('');
        $this->info("Cleared {$rows} products. Snapshot saved to " . self::BACKUP_TABLE . '.');
        $this->line('  manifest objects recorded : ' . count($keys));
        $this->line('  library rows removed      : ' . array_sum($library));
        $this->comment('Files still exist. Run with --purge-files to destroy them, or --rollback to undo.');
        return self::SUCCESS;
    }

    private function rollback(): int
    {
        $rows = DB::table(self::BACKUP_TABLE)->where('product_id', '>', 0)->get();
        if ($rows->isEmpty()) {
            $this->error('No snapshot to restore.');
            return self::FAILURE;
        }
        if (!$this->confirmed("Restore image/gallery on {$rows->count()} products?")) {
            return self::FAILURE;
        }
        foreach ($rows as $r) {
            DB::table('products')->where('id', $r->product_id)
                ->update(['image' => $r->image, 'gallery' => $r->gallery]);
        }

        // The libraries too. Restoring only the cache would leave the columns correct until the
        // next sync-image-columns rebuilt them from an empty library and blanked them again —
        // a rollback that silently undoes itself on the following deploy.
        $restored = [];
        if (Schema::hasTable(self::LIBRARY_BACKUP)) {
            DB::table(self::LIBRARY_BACKUP)->orderBy('id')->chunk(500, function ($chunk) use (&$restored) {
                foreach ($chunk as $b) {
                    $payload = json_decode($b->payload, true);
                    if (!is_array($payload) || !Schema::hasTable($b->source_table)) {
                        continue;
                    }
                    DB::table($b->source_table)->insertOrIgnore($payload);
                    $restored[$b->source_table] = ($restored[$b->source_table] ?? 0) + 1;
                }
            });
        }

        Cache::flush();
        $this->info("Restored {$rows->count()} products.");
        foreach ($restored as $t => $n) {
            $this->line("  {$t}: {$n} row(s)");
        }
        $this->comment('References only. Any S3 object already purged stays gone — those rows now point at 404s.');
        return self::SUCCESS;
    }

    // ── phase 2: the files ───────────────────────────────────────────────────────────────────

    private function purgeFiles(): int
    {
        // EVERY manifest, not just the newest. The clearing pass can legitimately run more than
        // once — an environment cleared before the library layer was understood recorded only the
        // product-column keys, and a later run records the library's. Reading the last row alone
        // would leave the earlier set on S3 while reporting a complete purge.
        $rows = DB::table(self::BACKUP_TABLE)->whereNotNull('manifest')->orderBy('id')->pluck('manifest');
        if ($rows->isEmpty()) {
            $this->error('No manifest found — run the clearing pass first so there is a record of what is being destroyed.');
            return self::FAILURE;
        }
        $merged = [];
        foreach ($rows as $json) {
            foreach ((json_decode($json, true) ?: []) as $k) {
                $merged[$k] = true;
            }
        }
        $keys = array_keys($merged);
        sort($keys);
        if (!$keys) {
            $this->error('Manifest is empty.');
            return self::FAILURE;
        }
        $this->line('  manifests merged: ' . $rows->count());

        // Refuse, do not skip. A non-product key in the manifest means the collector is wrong, and
        // the right response to that is to stop rather than to delete the safe-looking remainder.
        $foreign = array_values(array_filter($keys, fn ($k) => !$this->isProductKey($k)));
        if ($foreign) {
            $this->error('Manifest contains keys outside the product-attachment layout; refusing to delete anything:');
            foreach (array_slice($foreign, 0, 10) as $k) {
                $this->line("  {$k}");
            }
            return self::FAILURE;
        }

        $bucket = config('filesystems.disks.s3.bucket');
        $this->line('');
        $this->error('THIS CANNOT BE UNDONE. The bucket has no versioning.');
        $this->line('  bucket  : ' . $bucket);
        $this->line('  objects : ' . count($keys));

        if ($this->option('dry-run')) {
            foreach (array_slice($keys, 0, 5) as $k) {
                $this->line("  would delete: {$k}");
            }
            $this->comment('--dry-run: nothing deleted.');
            return self::SUCCESS;
        }
        if (!$this->confirmed('Permanently delete ' . count($keys) . " objects from {$bucket}?")) {
            return self::FAILURE;
        }

        $s3 = new S3Client([
            'version'     => 'latest',
            'region'      => config('filesystems.disks.s3.region'),
            'credentials' => [
                'key'    => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);

        $deleted = 0;
        $failed = [];
        // 1000 is the DeleteObjects hard limit; a larger batch is rejected wholesale.
        foreach (array_chunk($keys, 1000) as $i => $batch) {
            $res = $s3->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => ['Objects' => array_map(fn ($k) => ['Key' => $k], $batch), 'Quiet' => false],
            ]);
            $deleted += count($res['Deleted'] ?? []);
            foreach (($res['Errors'] ?? []) as $e) {
                $failed[] = ($e['Key'] ?? '?') . ': ' . ($e['Message'] ?? '?');
            }
            $this->line('  batch ' . ($i + 1) . " — {$deleted} deleted so far");
        }

        $purged = $this->purgeMediaRecords($keys);
        Cache::flush();

        $this->line('');
        $this->info("Deleted {$deleted} objects.");
        $this->line("  media rows removed       : {$purged['media']}");
        $this->line("  attachments rows removed : {$purged['attachments']}");
        if ($failed) {
            $this->warn(count($failed) . ' failed:');
            foreach (array_slice($failed, 0, 10) as $f) {
                $this->line("  {$f}");
            }
        }
        return self::SUCCESS;
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

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

    private function filled($v): bool
    {
        return $v !== null && trim((string) $v) !== '' && trim((string) $v) !== 'null';
    }

    /**
     * Pulls S3 object keys out of an image/gallery column. Both shapes appear: `image` is one
     * object, `gallery` an array of them, and each carries `original` plus `thumbnail` — which are
     * two DIFFERENT objects on S3 ({id}/file.png and {id}/conversions/file-thumbnail.jpg), so both
     * must be collected or the conversions are orphaned.
     */
    private function collectKeys($json, array &$keys, array &$external): void
    {
        if (!$this->filled($json)) {
            return;
        }
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return;
        }
        $host = parse_url((string) config('filesystems.disks.s3.url'), PHP_URL_HOST)
            ?: config('filesystems.disks.s3.bucket') . '.s3.' . config('filesystems.disks.s3.region') . '.amazonaws.com';

        $rows = (isset($d['original']) || isset($d['thumbnail'])) ? [$d] : $d;
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            foreach (['original', 'thumbnail'] as $field) {
                $url = $r[$field] ?? null;
                if (!$url) {
                    continue;
                }
                $h = parse_url($url, PHP_URL_HOST);
                $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
                if ($path === '') {
                    continue;
                }
                if ($h === $host) {
                    // urldecode: the stored URL is percent-encoded ("Jun-9%2C") but the S3 KEY is
                    // the literal filename. Deleting the encoded form silently matches nothing —
                    // DeleteObjects reports success for a key that was never there.
                    $keys[urldecode($path)] = true;
                } else {
                    $external[$h ?: 'unknown'] = true;
                }
            }
        }
    }

    /**
     * Removes the media-library rows that described the objects just deleted.
     *
     * Deliberately part of phase 2, not phase 1: a row describing a file should die WITH the file,
     * and phase 1 has to stay something --rollback can fully undo. Left behind, these are 2.5k
     * entries in the admin picker whose thumbnails 404.
     *
     * The join is the S3 layout itself — spatie stores each file at `{media.id}/{file_name}`, so
     * the numeric prefix of a manifest key IS the media id. Only prefixes the manifest actually
     * names are removed, which is what keeps the site logo, category banners and shop covers (all
     * in the same table and the same bucket) intact.
     *
     * `attachments` is vestigial here — its url column is empty on every row — but the rows are
     * still what `media.model_id` points at, so they go too rather than becoming orphans.
     */
    private function purgeMediaRecords(array $keys): array
    {
        if (!Schema::hasTable('media')) {
            return ['media' => 0, 'attachments' => 0];
        }
        // Only the spatie layout has media rows; plants/{slug}/ files were written by the
        // image pipeline and were never in the media library.
        $prefixes = [];
        foreach ($keys as $k) {
            $p = strtok($k, '/');
            if (ctype_digit((string) $p)) {
                $prefixes[(int) $p] = true;
            }
        }
        $prefixes = array_keys($prefixes);
        if (!$prefixes) {
            return ['media' => 0, 'attachments' => 0];
        }

        $attachmentIds = [];
        $mediaCount = 0;
        foreach (array_chunk($prefixes, 500) as $batch) {
            $rows = DB::table('media')->whereIn('id', $batch)
                ->where('model_type', 'like', '%Attachment')
                ->pluck('model_id', 'id');
            $mediaCount += $rows->count();
            foreach ($rows as $modelId) {
                $attachmentIds[(int) $modelId] = true;
            }
            DB::table('media')->whereIn('id', $batch)
                ->where('model_type', 'like', '%Attachment')
                ->delete();
        }

        $attachmentIds = array_keys($attachmentIds);
        $detached = 0;
        if (Schema::hasTable('attachments')) {
            foreach (array_chunk($attachmentIds, 500) as $batch) {
                $detached += DB::table('attachments')->whereIn('id', $batch)->delete();
            }
        }
        return ['media' => $mediaCount, 'attachments' => $detached];
    }


    /**
     * Counts the library rows and folds their S3 keys into the manifest.
     *
     * These are what actually has to go: a purge that clears only products.image leaves the
     * library intact, and the next `sync-image-columns` rebuilds every column from it.
     */
    private function scanLibraries(array &$keys, array &$external): array
    {
        $out = [];
        foreach (self::LIBRARIES as $src) {
            if (!Schema::hasTable($src['table'])) {
                continue;
            }
            $cols = array_values(array_filter(
                $src['columns'],
                fn ($c) => Schema::hasColumn($src['table'], $c),
            ));
            if (!$cols) {
                continue;
            }
            $n = 0;
            // cursor(), not chunk(): chunking needs an orderable primary key and these tables do
            // not all have one — pah_plant_image_backup has no `id` at all, which made a
            // schema-agnostic sweep fail on a column that was never selected explicitly.
            DB::table($src['table'])->select($cols)->cursor()
                ->each(function ($row) use ($cols, &$keys, &$external, &$n) {
                        $touched = false;
                        foreach ($cols as $c) {
                            $v = $row->$c ?? null;
                            if (!$this->filled($v)) {
                                continue;
                            }
                            $touched = true;
                            // These columns hold a BARE url on some tables and an image JSON blob
                            // on others (pah_plant_image_backup mirrors products.image), so both
                            // shapes are read.
                            if (str_starts_with(ltrim((string) $v), '{') || str_starts_with(ltrim((string) $v), '[')) {
                                $this->collectKeys($v, $keys, $external);
                            } else {
                                $this->collectUrl((string) $v, $keys, $external);
                            }
                        }
                        if ($touched) {
                            $n++;
                        }
                });
            $out[$src['table']] = $n;
        }
        return $out;
    }

    /** Snapshots each library row, then deletes it. Runs inside the caller's transaction. */
    private function clearLibraries(): void
    {
        foreach (self::LIBRARIES as $src) {
            if (!Schema::hasTable($src['table'])) {
                continue;
            }
            $stamp = now();
            $buffer = [];
            $flush = function () use (&$buffer) {
                if ($buffer) {
                    DB::table(self::LIBRARY_BACKUP)->insert($buffer);
                    $buffer = [];
                }
            };
            DB::table($src['table'])->cursor()->each(function ($row) use ($src, $stamp, &$buffer, $flush) {
                $buffer[] = [
                    'source_table' => $src['table'],
                    // 0 when the table has no id — the payload is the restore, not this column.
                    'row_id'       => (int) ($row->id ?? 0),
                    'payload'      => json_encode($row),
                    'created_at'   => $stamp,
                ];
                if (count($buffer) >= 500) {
                    $flush();
                }
            });
            $flush();
            DB::table($src['table'])->delete();
        }
    }

    /** A bare URL column, as opposed to the image JSON blob collectKeys() reads. */
    private function collectUrl(string $url, array &$keys, array &$external): void
    {
        $host = parse_url((string) config('filesystems.disks.s3.url'), PHP_URL_HOST)
            ?: config('filesystems.disks.s3.bucket') . '.s3.' . config('filesystems.disks.s3.region') . '.amazonaws.com';
        $h = parse_url($url, PHP_URL_HOST);
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return;
        }
        if ($h === $host) {
            $keys[urldecode($path)] = true;
        } else {
            $external[$h ?: 'unknown'] = true;
        }
    }

    private function ensureBackupTable(): void
    {
        if (!Schema::hasTable(self::LIBRARY_BACKUP)) {
            Schema::create(self::LIBRARY_BACKUP, function ($t) {
                $t->bigIncrements('id');
                $t->string('source_table', 64)->index();
                $t->unsignedBigInteger('row_id');
                $t->longText('payload');
                $t->timestamp('created_at')->nullable();
            });
        }
        if (Schema::hasTable(self::BACKUP_TABLE)) {
            return;
        }
        Schema::create(self::BACKUP_TABLE, function ($t) {
            $t->bigIncrements('id');
            // 0 marks the manifest row rather than a product snapshot.
            $t->unsignedBigInteger('product_id')->index();
            $t->longText('image')->nullable();
            $t->longText('gallery')->nullable();
            $t->longText('manifest')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }
}
