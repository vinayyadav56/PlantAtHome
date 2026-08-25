<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\MediaItemVersion;

/**
 * Regression: adoption can point SEVERAL versions at ONE shared legacy object
 * ('reused' images). GC purging one retired owner must not delete a key any
 * other un-purged version still references (MediaGcCommand::stripSharedKeys).
 */
final class SharedKeyGcReproTest extends MediaTestCase
{
    public function test_gc_include_adopted_spares_a_key_still_owned_by_a_live_version(): void
    {
        config(['media.env' => 'production']);

        $sharedKey = 'plants/x/1.jpg';
        Storage::disk('s3')->put($sharedKey, 'bytes');

        // Two adopted items, both v1 pointing at the SAME legacy object
        // (adopted versions carry no variants).
        $shared = ['original_key' => $sharedKey, 'variants' => null];
        $retired = $this->makeVersion(MediaItemVersion::RETIRED, 'production', $shared);
        $retired->forceFill(['retired_at' => now()->subDays(90)])->save();
        $live = $this->makeVersion(MediaItemVersion::LIVE, 'production', $shared);

        Artisan::call('media:gc', ['--include-adopted' => true]);

        $this->assertTrue(
            Storage::disk('s3')->exists($sharedKey),
            'GC deleted a shared object a LIVE version still references'
        );
        // The retired owner is still marked purged — its claim on the object is over.
        $this->assertNotNull($retired->fresh()->purged_at);
        $this->assertNull($live->fresh()->purged_at);
    }
}
