<?php

namespace Marvel\Console;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\MediaItemVersion;

/**
 * The ONLY scheduled path that physically deletes media objects. Own-env only:
 * a version whose origin_env differs from config('media.env') is never touched,
 * which is what keeps the shared bucket safe from either side.
 *
 * Production: purge retired versions past retired_retention_days — but adopted
 * legacy keys (outside media/p/) are default-skipped: they may still be served
 * by rows this system never saw. --include-adopted lifts that once the URL
 * rewrite has landed and media:audit-refs is clean.
 *
 * Staging: delete rejected staging versions older than 7 days (rows AND
 * objects — staging history is disposable), then sweep media/s/ for objects no
 * version row references, older than orphan_min_age_days (in-flight upload guard).
 */
class MediaGcCommand extends Command
{
    protected $signature = 'media:gc
        {--dry-run : Report what would be purged and delete nothing}
        {--include-adopted : Production — also purge retired versions on adopted legacy keys (outside media/p/)}';

    protected $description = 'Purge S3 objects of lapsed media versions owned by THIS environment.';

    public function handle(): int
    {
        $env = config('media.env');
        if ($env === 'production') {
            return $this->gcProduction();
        }
        if ($env === 'staging') {
            return $this->gcStaging();
        }
        $this->error("Unknown media env '{$env}' — refusing to delete anything.");
        return self::FAILURE;
    }

    private function gcProduction(): int
    {
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays((int) config('media.retired_retention_days', 30));

        $q = MediaItemVersion::query()
            ->where('origin_env', 'production')
            ->where('status', MediaItemVersion::RETIRED)
            ->whereNull('purged_at')
            ->whereNotNull('retired_at')
            ->where('retired_at', '<', $cutoff);
        if (!$this->option('include-adopted')) {
            $q->where('original_key', 'like', 'media/p/%');
        }

        $purged = 0;
        $objects = 0;
        $failed = 0;
        $q->orderBy('id')->chunkById(100, function ($versions) use ($dry, &$purged, &$objects, &$failed) {
            foreach ($versions as $version) {
                $keys = $this->stripSharedKeys($version, $version->allKeys());
                if ($dry) {
                    if ($purged < 5) {
                        $this->line("  would purge version {$version->id}: " . ($keys[0] ?? '(no objects)'));
                    }
                    $purged++;
                    $objects += count($keys);
                    continue;
                }
                try {
                    if ($keys && !Storage::disk('s3')->delete($keys)) {
                        $this->warn("Version {$version->id}: s3 delete reported failure — retried next run");
                        $failed++;
                        continue;
                    }
                } catch (\Throwable $e) {
                    $this->warn("Version {$version->id}: s3 delete failed — {$e->getMessage()}");
                    $failed++;
                    continue;
                }
                $version->forceFill(['purged_at' => now()])->save();
                $purged++;
                $objects += count($keys);
            }
        });

        $this->table(['metric', 'count'], [
            ['retired versions ' . ($dry ? 'purgeable' : 'purged'), $purged],
            ['s3 objects', $objects],
            ['failed (will retry)', $failed],
        ]);
        if ($dry) {
            $this->comment('--dry-run: nothing deleted.');
        }
        return self::SUCCESS;
    }

    private function gcStaging(): int
    {
        $dry = (bool) $this->option('dry-run');

        // (a) rejected staging versions older than 7 days: objects AND rows.
        $rejected = 0;
        $failed = 0;
        MediaItemVersion::query()
            ->where('origin_env', 'staging')
            ->where('status', MediaItemVersion::REJECTED)
            ->where('updated_at', '<', now()->subDays(7))
            ->orderBy('id')->chunkById(100, function ($versions) use ($dry, &$rejected, &$failed) {
                foreach ($versions as $version) {
                    if ($dry) {
                        if ($rejected < 5) {
                            $this->line("  would delete rejected version {$version->id}: " . ($version->original_key ?? '(external)'));
                        }
                        $rejected++;
                        continue;
                    }
                    try {
                        $keys = $this->stripSharedKeys($version, $version->allKeys());
                        if ($keys) {
                            Storage::disk('s3')->delete($keys);
                        }
                        $version->delete();
                        $rejected++;
                    } catch (\Throwable $e) {
                        $this->warn("Version {$version->id}: cleanup failed — {$e->getMessage()}");
                        $failed++;
                    }
                }
            });

        // (b) orphan sweep under media/s/. No LIKE prefilter on the DB side:
        // sqlite stores variants JSON with escaped slashes, and a prefilter
        // that misses a row turns its keys into "orphans" — collect from every
        // version row instead.
        $prefix = 'media/' . config('media.env_prefixes.staging', 's') . '/';
        $referenced = [];
        MediaItemVersion::query()->select('id', 'original_key', 'variants')->cursor()
            ->each(function ($version) use (&$referenced, $prefix) {
                foreach ($version->allKeys() as $k) {
                    if (str_starts_with($k, $prefix)) {
                        $referenced[$k] = true;
                    }
                }
            });

        $bucket = config('filesystems.disks.s3.bucket');
        $s3 = new S3Client([
            'version'     => 'latest',
            'region'      => config('filesystems.disks.s3.region'),
            'credentials' => [
                'key'    => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);

        $minAge = now()->subDays((int) config('media.orphan_min_age_days', 7))->getTimestamp();
        $orphans = [];
        foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => $bucket, 'Prefix' => $prefix]) as $page) {
            foreach (($page['Contents'] ?? []) as $obj) {
                $key = $obj['Key'];
                if (isset($referenced[$key]) || $obj['LastModified']->getTimestamp() >= $minAge) {
                    continue;
                }
                $orphans[] = $key;
            }
        }

        $deleted = 0;
        if ($dry) {
            foreach (array_slice($orphans, 0, 5) as $k) {
                $this->line("  would delete orphan: {$k}");
            }
        } else {
            // 1000 is the DeleteObjects hard limit.
            foreach (array_chunk($orphans, 1000) as $batch) {
                try {
                    $res = $s3->deleteObjects([
                        'Bucket' => $bucket,
                        'Delete' => ['Objects' => array_map(fn ($k) => ['Key' => $k], $batch), 'Quiet' => false],
                    ]);
                    $deleted += count($res['Deleted'] ?? []);
                    $failed += count($res['Errors'] ?? []);
                } catch (\Throwable $e) {
                    $this->warn('Orphan batch delete failed — ' . $e->getMessage());
                    $failed += count($batch);
                }
            }
        }

        $this->table(['metric', 'count'], [
            ['rejected versions ' . ($dry ? 'deletable' : 'deleted'), $rejected],
            ['orphan objects found', count($orphans)],
            ['orphan objects deleted', $dry ? 0 : $deleted],
            ['failed (will retry)', $failed],
        ]);
        if ($dry) {
            $this->comment('--dry-run: nothing deleted.');
        }
        return self::SUCCESS;
    }

    /**
     * Keys still referenced by ANY other un-purged version. Adoption can point
     * several versions at ONE shared legacy object ('reused' images) — deleting
     * it for one version would break the survivors. Variant keys live under
     * their original's v{n}/ prefix, so original_key equality is the complete
     * sharing test.
     */
    private function stripSharedKeys(\Marvel\Database\Models\MediaItemVersion $version, array $keys): array
    {
        if (!$keys) {
            return $keys;
        }
        $shared = \Marvel\Database\Models\MediaItemVersion::query()
            ->where('id', '!=', $version->id)
            ->whereNull('purged_at')
            ->whereIn('original_key', $keys)
            ->pluck('original_key')->all();
        if (!$shared) {
            return $keys;
        }
        $sharedPrefixes = array_map(fn ($k) => preg_replace('/\/original\.[^.\/]+$/', '/', $k), $shared);
        return array_values(array_filter($keys, function ($key) use ($shared, $sharedPrefixes) {
            if (in_array($key, $shared, true)) {
                return false;
            }
            foreach ($sharedPrefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return false;
                }
            }
            return true;
        }));
    }
}
