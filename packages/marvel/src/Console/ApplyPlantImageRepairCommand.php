<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;

/**
 * Repairs plant product images. Each plant's `image`/`gallery` columns were
 * seeded with Unsplash URLs that are mostly dead (404). Real images live on S3
 * at plants/{slug}/{n}.jpg. This points each plant at its S3 photo(s) where
 * they exist, and CLEARS the dead Unsplash URL otherwise (the storefront falls
 * back to the brand house-mark for an empty image).
 *
 * Existence is checked via a public HTTP HEAD on the S3 URL (the bucket is
 * public-read) — no S3 credentials needed; it tests exactly what the browser
 * <img> will load. Snapshots originals to pah_plant_image_backup first;
 * --rollback restores them. Idempotent. --dry-run / --limit for staged rollout.
 *
 *   php artisan plantathome:repair-plant-images --dry-run
 *   php artisan plantathome:repair-plant-images --limit=10
 *   php artisan plantathome:repair-plant-images
 *   php artisan plantathome:repair-plant-images --rollback
 */
class ApplyPlantImageRepairCommand extends Command
{
    protected $signature = 'plantathome:repair-plant-images
        {--dry-run : Print the planned changes without writing}
        {--limit=0 : Only process N plants (0 = all)}
        {--rollback : Restore image/gallery columns from the backup table}';

    protected $description = 'Point plant images at S3 where they exist; remove dead Unsplash URLs otherwise. Reversible.';

    private const MAX_GALLERY = 5;

    public function handle(): int
    {
        $type = Type::where('slug', 'plants')->where('language', 'en')->first();
        if (!$type) {
            $this->error('Plants type not found.');
            return self::FAILURE;
        }

        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $bucket = env('AWS_BUCKET', 'plantathome-media-prod');
        $region = env('AWS_DEFAULT_REGION', 'ap-south-1');
        $base = "https://{$bucket}.s3.{$region}.amazonaws.com";

        $fromS3 = 0;
        $cleared = 0;
        $skipped = 0;
        $processed = 0;
        $samples = [];

        Product::where('type_id', $type->id)->where('language', 'en')->orderBy('id')
            ->chunkById(200, function ($plants) use (&$fromS3, &$cleared, &$skipped, &$processed, &$samples, $dry, $limit, $base) {
                foreach ($plants as $p) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }
                    $slug = (string) $p->slug;

                    // Collect contiguous S3 images 1.jpg, 2.jpg, … (stop at first gap).
                    $urls = [];
                    $uncertain = false;
                    for ($n = 1; $n <= self::MAX_GALLERY; $n++) {
                        $url = "{$base}/plants/{$slug}/{$n}.jpg";
                        try {
                            $ok = $this->s3Has($url);
                        } catch (\Throwable $e) {
                            if ($n === 1) {
                                $uncertain = true; // couldn't verify — don't risk clearing
                            }
                            break;
                        }
                        if ($ok) {
                            $urls[] = $url;
                        } else {
                            break;
                        }
                    }
                    if ($uncertain) {
                        $skipped++;
                        continue;
                    }

                    if ($urls) {
                        $image = ['id' => null, 'original' => $urls[0], 'thumbnail' => $urls[0]];
                        $gallery = array_map(fn ($u) => ['id' => null, 'original' => $u, 'thumbnail' => $u], $urls);
                        $action = 's3(' . count($urls) . ')';
                    } else {
                        $image = null;
                        $gallery = null;
                        $action = 'clear';
                    }

                    // Idempotent: skip if the column already matches the target.
                    $curOrig = is_array($p->image) ? ($p->image['original'] ?? null) : null;
                    $wantOrig = $image['original'] ?? null;
                    if ($curOrig === $wantOrig) {
                        $skipped++;
                        continue;
                    }

                    $processed++;
                    $action === 'clear' ? $cleared++ : $fromS3++;
                    if (count($samples) < 8) {
                        $samples[] = sprintf('%-28s → %s', $slug, $action);
                    }
                    if (!$dry) {
                        $this->backupOnce($p);
                        $p->forceFill(['image' => $image, 'gallery' => $gallery])->saveQuietly();
                    }
                }
                return true;
            });

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Plants — set-from-S3: {$fromS3}, cleared (Unsplash removed): {$cleared}, already-ok: {$skipped}");
        foreach ($samples as $s) {
            $this->line('   ' . $s);
        }
        if (!$dry) {
            Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
            $this->info('Done. Product cache busted.');
        } else {
            $this->warn('Dry-run only — nothing written.');
        }
        return self::SUCCESS;
    }

    /** Public HEAD on the S3 URL — 2xx = exists. No S3 credentials needed. */
    private function s3Has(string $url): bool
    {
        return Http::timeout(8)->head($url)->successful();
    }

    private function backupOnce(Product $p): void
    {
        if (DB::table('pah_plant_image_backup')->where('product_id', $p->id)->exists()) {
            return;
        }
        DB::table('pah_plant_image_backup')->insert([
            'product_id'   => $p->id,
            'image'        => $p->image !== null ? json_encode($p->image) : null,
            'gallery'      => $p->gallery !== null ? json_encode($p->gallery) : null,
            'backed_up_at' => now(),
        ]);
    }

    private function rollback(): int
    {
        $rows = DB::table('pah_plant_image_backup')->get();
        if ($rows->isEmpty()) {
            $this->warn('No backup rows — nothing to roll back.');
            return self::SUCCESS;
        }
        $n = 0;
        foreach ($rows as $b) {
            $p = Product::find($b->product_id);
            if (!$p) {
                continue;
            }
            $p->forceFill([
                'image'   => $b->image !== null ? json_decode($b->image, true) : null,
                'gallery' => $b->gallery !== null ? json_decode($b->gallery, true) : null,
            ])->saveQuietly();
            $n++;
        }
        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
        $this->info("Restored image/gallery for {$n} plants from backup.");
        return self::SUCCESS;
    }
}
