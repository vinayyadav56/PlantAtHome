<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\MediaAttachment;
use Marvel\Database\Models\MediaItem;
use Marvel\Database\Models\MediaItemVersion;
use Marvel\Database\Models\Product;

/**
 * Adopts legacy product_images rows into the centralized media system: one
 * media_item + a LIVE v1 version pointing at the EXISTING S3 object (nothing
 * is copied or re-uploaded), a media_attachment mirroring is_primary/sort_order,
 * and product_images.media_item_id backfilled so the row is recognisably owned.
 *
 * Keys are adopted verbatim — media:gc default-skips them (outside media/p/),
 * so adoption alone can never make an object purgeable.
 *
 *   php artisan media:adopt-existing --dry-run    # counts + samples, no writes
 *   php artisan media:adopt-existing --force      # adopt
 *   php artisan media:adopt-existing --rollback   # remove adopted rows, un-link
 */
class AdoptExistingMediaCommand extends Command
{
    protected $signature = 'media:adopt-existing
        {--dry-run : Report what would be adopted and write nothing}
        {--rollback : Delete adopted media rows and null product_images.media_item_id}
        {--force : Skip the interactive confirmation (for CI)}
        {--scope=products : Entity scope (only "products" exists today)}';

    protected $description = 'Backfill media_items/versions/attachments from legacy product_images rows.';

    /**
     * The bucket layouts legacy product imagery actually uses, adopted verbatim
     * as original_key. A bucket-host URL matching NONE of these refuses the
     * whole run (purge-command precedent): an unknown layout means the
     * derivation is wrong, and adopting the safe-looking remainder would hide it.
     */
    private const ADOPTABLE_KEY_PATTERNS = [
        '/^plants\/[^\/]+\/.+/',
        '/^\d+\/.+/',
        '/^ai-batches\/.+/',
        '/^ai-instant\/.+/',
        '/^media\/.+/',
    ];

    private ?array $hosts = null;

    public function handle(): int
    {
        if ($this->option('scope') !== 'products') {
            $this->error("Unknown scope '{$this->option('scope')}' — only 'products' exists.");
            return self::FAILURE;
        }
        if ($this->option('rollback')) {
            return $this->rollback();
        }
        return $this->adopt();
    }

    private function adopt(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Pass 1: classify every un-adopted row BEFORE writing anything, so an
        // unrecognized layout refuses the run instead of surfacing mid-write.
        $rows = 0;
        $bucket = 0;
        $external = 0;
        $unrecognized = [];
        $samples = [];
        DB::table('product_images')->whereNull('media_item_id')
            ->orderBy('id')->chunkById(500, function ($chunk) use (&$rows, &$bucket, &$external, &$unrecognized, &$samples) {
                foreach ($chunk as $r) {
                    $rows++;
                    $c = $this->classify($r->url);
                    if ($c['external']) {
                        $external++;
                    } elseif ($c['key'] !== null) {
                        $bucket++;
                        if (count($samples) < 5) {
                            $samples[] = $c['key'];
                        }
                    } else {
                        $unrecognized[(string) $r->url] = true;
                    }
                }
            });

        $this->line('');
        $this->info('Legacy product_images adoption');
        $this->line("  un-adopted rows           : {$rows}");
        $this->line("  bucket-hosted (adoptable) : {$bucket}");
        $this->line("  foreign-host (external)   : {$external}");
        $this->line('  unrecognized layout       : ' . count($unrecognized));
        if ($samples) {
            $this->line('  sample keys:');
            foreach ($samples as $k) {
                $this->line("    {$k}");
            }
        }
        if ($unrecognized) {
            // Refuse, do not skip — a bucket-host URL outside every known layout
            // means classify() is wrong for this dataset.
            $this->error('Bucket-host URLs outside every known layout; refusing to adopt anything:');
            foreach (array_slice(array_keys($unrecognized), 0, 10) as $u) {
                $this->line("  {$u}");
            }
            if (!$dry) {
                return self::FAILURE;
            }
        }
        if ($dry) {
            $this->comment('--dry-run: nothing written.');
            return self::SUCCESS;
        }
        if (!$rows) {
            $this->comment('Nothing to adopt.');
            return self::SUCCESS;
        }
        if (!$this->confirmed("Adopt {$rows} product image row(s) into media_items?")) {
            return self::FAILURE;
        }

        $morph = (new Product())->getMorphClass();
        $adopted = 0;
        $failed = 0;
        $productIds = DB::table('product_images')->whereNull('media_item_id')
            ->distinct()->orderBy('product_id')->pluck('product_id');

        foreach ($productIds as $pid) {
            try {
                DB::transaction(function () use ($pid, $morph, &$adopted) {
                    $imageRows = DB::table('product_images')
                        ->where('product_id', $pid)->whereNull('media_item_id')
                        ->orderBy('sort_order')->get();
                    foreach ($imageRows as $r) {
                        $c = $this->classify($r->url);
                        if ($c['key'] === null && !$c['external']) {
                            continue; // appeared after the pass-1 refusal window
                        }
                        $thumb = $this->classify($r->thumbnail_url)['key'];

                        $item = MediaItem::create([
                            'entity_hint' => 'products',
                            'origin_env' => 'production',
                            'meta' => ['source' => 'adoption'],
                        ]);
                        // status is guarded on the model — forceFill, like MediaService.
                        $version = new MediaItemVersion();
                        $version->forceFill([
                            'media_item_id' => $item->id,
                            'version_number' => 1,
                            'status' => MediaItemVersion::LIVE,
                            'origin_env' => 'production',
                            'original_key' => $c['key'],
                            'external_url' => $c['external'] ? $r->url : null,
                            'variants' => $thumb ? ['thumbnail' => $thumb] : null,
                            'published_at' => now(),
                        ])->save();
                        $item->forceFill(['live_version_id' => $version->id])->save();

                        MediaAttachment::create([
                            'media_item_id' => $item->id,
                            'attachable_type' => $morph,
                            'attachable_id' => $pid,
                            'role' => $r->is_primary ? 'main' : 'gallery',
                            'position' => (int) $r->sort_order,
                        ]);
                        DB::table('product_images')->where('id', $r->id)
                            ->update(['media_item_id' => $item->id]);
                        $adopted++;
                    }
                });
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Product {$pid}: adoption failed — {$e->getMessage()}");
            }
        }

        $this->line('');
        $this->info("Adopted {$adopted} row(s) across {$productIds->count()} product(s).");
        if ($failed) {
            $this->warn("{$failed} product(s) failed — re-run to retry (adoption is per-product atomic).");
        }
        return self::SUCCESS;
    }

    private function rollback(): int
    {
        $ids = MediaItem::query()
            ->where(fn ($q) => $q->where('meta->source', 'adoption')
                ->orWhere('meta', 'like', '%"source":"adoption"%'))
            ->pluck('id');
        if ($ids->isEmpty()) {
            $this->comment('No adopted media items found.');
            return self::SUCCESS;
        }
        // Anyone who uploaded a v2 onto an adopted item has built on it — its
        // history is now real and must survive.
        $built = MediaItemVersion::query()->whereIn('media_item_id', $ids)
            ->where('version_number', '>', 1)->distinct()->pluck('media_item_id');
        if ($built->isNotEmpty()) {
            $this->error("Refusing rollback: {$built->count()} adopted item(s) carry versions beyond v1:");
            foreach ($built->take(10) as $id) {
                $this->line("  media_item {$id}");
            }
            return self::FAILURE;
        }
        if (!$this->confirmed("Delete {$ids->count()} adopted media item(s) and un-link their product_images rows? S3 objects are NOT touched.")) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids->chunk(500) as $batch) {
                // Un-link first, then children before parents (versions FK is restrictOnDelete).
                DB::table('product_images')->whereIn('media_item_id', $batch)->update(['media_item_id' => null]);
                MediaAttachment::query()->whereIn('media_item_id', $batch)->delete();
                MediaItemVersion::query()->whereIn('media_item_id', $batch)->delete();
                MediaItem::query()->whereIn('id', $batch)->delete();
            }
        });

        $this->info("Rolled back {$ids->count()} adopted item(s). S3 objects untouched.");
        return self::SUCCESS;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * ['key' => derived-S3-key|null, 'external' => bool]. key null + external
     * false = unrecognized (empty URL, no host, or unknown bucket layout).
     */
    private function classify(?string $url): array
    {
        $out = ['key' => null, 'external' => false];
        if ($url === null || trim($url) === '') {
            return $out;
        }
        $host = parse_url($url, PHP_URL_HOST);
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (!$host || $path === '') {
            return $out;
        }
        if (!in_array($host, $this->bucketHosts(), true)) {
            $out['external'] = true;
            return $out;
        }
        // urldecode: the stored URL is percent-encoded but the S3 KEY is literal.
        $key = urldecode($path);
        foreach (self::ADOPTABLE_KEY_PATTERNS as $re) {
            if (preg_match($re, $key)) {
                $out['key'] = $key;
                return $out;
            }
        }
        return $out;
    }

    /** Both hosts a bucket URL may carry: the raw S3 endpoint and the CDN (AWS_URL). */
    private function bucketHosts(): array
    {
        if ($this->hosts === null) {
            $this->hosts = [
                config('filesystems.disks.s3.bucket') . '.s3.' . config('filesystems.disks.s3.region') . '.amazonaws.com',
            ];
            if ($h = parse_url((string) config('filesystems.disks.s3.url'), PHP_URL_HOST)) {
                $this->hosts[] = $h;
            }
            $this->hosts = array_values(array_unique($this->hosts));
        }
        return $this->hosts;
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
}
