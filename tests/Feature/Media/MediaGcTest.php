<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\MediaItemVersion;

/**
 * media:gc — the only scheduled path that deletes objects, and strictly
 * own-env: staging clears its old rejected drafts, production purges retired
 * versions past retention, and neither may ever touch the other side's keys.
 */
final class MediaGcTest extends MediaTestCase
{
    public function test_staging_gc_deletes_old_rejected_versions_and_spares_everything_else(): void
    {
        $old = $this->makeVersion(MediaItemVersion::REJECTED, 'staging');
        $oldKeys = $old->allKeys();
        DB::table('media_item_versions')->where('id', $old->id)->update(['updated_at' => now()->subDays(8)]);

        $fresh = $this->makeVersion(MediaItemVersion::REJECTED, 'staging'); // updated today
        $prodRetired = $this->makeVersion(MediaItemVersion::RETIRED, 'production', [
            'retired_at' => now()->subDays(90),
        ]);

        $this->runGcToleratingTheOrphanSweep();

        foreach ($oldKeys as $key) {
            Storage::disk('s3')->assertMissing($key);
        }
        $this->assertDatabaseMissing('media_item_versions', ['id' => $old->id]);

        $this->assertDatabaseHas('media_item_versions', ['id' => $fresh->id]);
        Storage::disk('s3')->assertExists($fresh->original_key);

        // Prod-origin rows are NEVER staging's to touch, however lapsed.
        $this->assertDatabaseHas('media_item_versions', ['id' => $prodRetired->id, 'purged_at' => null]);
        foreach ($prodRetired->allKeys() as $key) {
            Storage::disk('s3')->assertExists($key);
        }
    }

    public function test_production_gc_purges_only_media_p_retired_past_retention(): void
    {
        config(['media.env' => 'production', 'media.retired_retention_days' => 30]);

        $purgeable = $this->makeVersion(MediaItemVersion::RETIRED, 'production', [
            'retired_at' => now()->subDays(40),
        ]);
        $purgeableKeys = $purgeable->allKeys();
        $adopted = $this->makeVersion(MediaItemVersion::RETIRED, 'production', [
            'retired_at' => now()->subDays(40),
            'original_key' => 'plants/ficus/1.jpg',
            'variants' => null,
        ]);
        $recent = $this->makeVersion(MediaItemVersion::RETIRED, 'production', [
            'retired_at' => now()->subDays(5),
        ]);
        $stagingRetired = $this->makeVersion(MediaItemVersion::RETIRED, 'staging', [
            'retired_at' => now()->subDays(90),
        ]);

        Artisan::call('media:gc');

        foreach ($purgeableKeys as $key) {
            Storage::disk('s3')->assertMissing($key);
        }
        $this->assertNotNull($purgeable->refresh()->purged_at);
        // The row is the audit trail — only the objects go.
        $this->assertDatabaseHas('media_item_versions', ['id' => $purgeable->id]);

        // Adopted legacy key (outside media/p/) is default-skipped…
        $this->assertNull($adopted->refresh()->purged_at);
        Storage::disk('s3')->assertExists('plants/ficus/1.jpg');

        // …until --include-adopted lifts the guard.
        Artisan::call('media:gc', ['--include-adopted' => true]);
        $this->assertNotNull($adopted->refresh()->purged_at);
        Storage::disk('s3')->assertMissing('plants/ficus/1.jpg');

        // Within retention, and foreign-env rows: untouched by both runs.
        foreach ([$recent, $stagingRetired] as $version) {
            $this->assertNull($version->refresh()->purged_at);
            foreach ($version->allKeys() as $key) {
                Storage::disk('s3')->assertExists($key);
            }
        }
    }

    public function test_dry_run_deletes_nothing_in_production(): void
    {
        config(['media.env' => 'production']);
        $purgeable = $this->makeVersion(MediaItemVersion::RETIRED, 'production', [
            'retired_at' => now()->subDays(90),
        ]);

        Artisan::call('media:gc', ['--dry-run' => true]);

        $this->assertNull($purgeable->refresh()->purged_at);
        foreach ($purgeable->allKeys() as $key) {
            Storage::disk('s3')->assertExists($key);
        }
    }

    /**
     * The staging orphan sweep builds a raw S3Client inline — not fakeable at
     * this level. A bucket-less config makes the SDK's client-side parameter
     * validation throw BEFORE any network I/O, and part (a) (the
     * rejected-version cleanup under test) has already committed by then.
     */
    private function runGcToleratingTheOrphanSweep(): void
    {
        config(['filesystems.disks.s3.bucket' => null, 'filesystems.disks.s3.region' => null]);
        try {
            Artisan::call('media:gc');
            $this->fail('the orphan sweep was expected to abort client-side');
        } catch (\Throwable $e) {
            // Which parameter trips first depends on the machine's AWS env.
            $this->assertMatchesRegularExpression('/Bucket|region/', $e->getMessage());
        }
    }
}
