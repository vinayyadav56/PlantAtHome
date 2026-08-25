<?php

namespace Marvel\Services\Media;

use Marvel\Database\Models\MediaItem;
use Marvel\Database\Models\MediaItemVersion;

/**
 * The ONE place media URLs come from. Base = filesystems.disks.s3.url
 * (AWS_URL → CloudFront) with the raw S3 host as fallback, so flipping the
 * CDN on/off is an env change, never a code change.
 */
class MediaUrlService
{
    public function base(): string
    {
        $configured = config('filesystems.disks.s3.url');
        if ($configured) {
            return rtrim($configured, '/');
        }
        $bucket = config('filesystems.disks.s3.bucket');
        $region = config('filesystems.disks.s3.region', 'ap-south-1');

        return "https://{$bucket}.s3.{$region}.amazonaws.com";
    }

    /**
     * URL for one variant of a version. Falls back to the original when the
     * variant doesn't exist (e.g. still processing), and to external_url for
     * adopted foreign-host images.
     */
    public function url(MediaItemVersion $version, string $variant = 'original'): ?string
    {
        if ($variant !== 'original') {
            $key = $version->variants[$variant] ?? null;
            if ($key) {
                return $this->base() . '/' . $this->encodeKey($key);
            }
        }
        if ($version->original_key) {
            return $this->base() . '/' . $this->encodeKey($version->original_key);
        }

        return $version->external_url ?: null;
    }

    /**
     * The legacy `{id, original, thumbnail}` attachment payload for an item's
     * live version — byte-compatible with what every frontend already reads.
     * `id` is the media item id (stable within an environment's DB).
     */
    public function attachmentPayload(MediaItem $item): ?array
    {
        $live = $item->liveVersion;
        if (!$live) {
            return null;
        }
        $original = $this->url($live, 'large') ?? $this->url($live);
        if (!$original) {
            return null;
        }

        return [
            'id' => $item->id,
            'original' => $original,
            'thumbnail' => $this->url($live, 'thumbnail') ?? $original,
        ];
    }

    /**
     * Immutable storage key for a new version file:
     * media/{env-segment}/{entity_hint}/{media_uuid}/v{n}/{filename}
     */
    public function storageKey(MediaItem $item, int $versionNumber, string $filename): string
    {
        $env = config('media.env');
        $segment = config("media.env_prefixes.{$env}", 's');
        $hint = $item->entity_hint ?: 'misc';

        return "media/{$segment}/{$hint}/{$item->uuid}/v{$versionNumber}/{$filename}";
    }

    /**
     * S3 keys may contain characters that must be percent-encoded in a URL,
     * but slashes are path separators and stay literal. (Historic gotcha:
     * encoded-vs-raw mismatches made deletes silent no-ops.)
     */
    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
