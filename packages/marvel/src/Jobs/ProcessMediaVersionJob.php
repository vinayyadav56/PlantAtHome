<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\MediaItemVersion;
use Marvel\Services\Media\MediaService;

/**
 * Variant generation for one media version: draft → processing → ready.
 * Rides the existing `images` queue (config media.queue) — both environments
 * already run a worker for it.
 */
class ProcessMediaVersionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public int $versionId)
    {
    }

    public function handle(MediaService $media): void
    {
        $version = MediaItemVersion::find($this->versionId);
        if (!$version) {
            return; // draft deleted before the worker got to it
        }
        $media->process($version);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('media: version processing failed', [
            'version_id' => $this->versionId,
            'error' => $e->getMessage(),
        ]);
    }
}
