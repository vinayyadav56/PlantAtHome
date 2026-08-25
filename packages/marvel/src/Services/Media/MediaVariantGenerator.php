<?php

namespace Marvel\Services\Media;

use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\MediaItemVersion;
use Spatie\Image\Image;

/**
 * Renders the webp derivatives for one version. Every derivative lives beside
 * the original under the same immutable v{n}/ prefix; a variant key is written
 * once and never regenerated in place.
 */
class MediaVariantGenerator
{
    /** @return array<string,string> variant name => S3 key */
    public function generate(MediaItemVersion $version): array
    {
        if (!$version->original_key || !$this->isRasterImage($version->mime)) {
            return []; // external or non-image (pdf) — original only
        }

        $disk = Storage::disk('s3');
        $tmpDir = sys_get_temp_dir() . '/media-' . $version->id . '-' . getmypid();
        @mkdir($tmpDir, 0755, true);
        $src = $tmpDir . '/original';
        file_put_contents($src, $disk->get($version->original_key));

        $variants = [];
        try {
            $prefix = preg_replace('/\/original\.[^.\/]+$/', '', $version->original_key);
            foreach ((array) config('media.variants') as $name => $width) {
                // Never upscale beyond the source.
                $targetWidth = $version->width ? min((int) $width, (int) $version->width) : (int) $width;
                $out = "{$tmpDir}/{$name}.webp";
                Image::load($src)->width($targetWidth)->format('webp')->save($out);
                $key = "{$prefix}/{$name}.webp";
                if (!$disk->exists($key)) {
                    $disk->put($key, file_get_contents($out), [
                        'CacheControl' => 'public, max-age=31536000, immutable',
                        'ContentType' => 'image/webp',
                    ]);
                }
                $variants[$name] = $key;
                @unlink($out);
            }
        } finally {
            @unlink($src);
            @rmdir($tmpDir);
        }

        return $variants;
    }

    private function isRasterImage(?string $mime): bool
    {
        return $mime !== null
            && str_starts_with($mime, 'image/')
            && !in_array($mime, ['image/svg+xml'], true);
    }
}
