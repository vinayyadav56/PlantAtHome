<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductImage;

/**
 * Fetches plant images free-first (iNaturalist → Wikimedia Commons → Pixabay),
 * uploads them to S3 keyed by slug (plants/{slug}/{n}.jpg) and records
 * product_images rows. Idempotent + slug-keyed so staging/prod share the files.
 */
class PlantImageFetcher
{
    private int $target;
    private const OK_LICENSES = ['cc0', 'cc-by', 'cc-by-sa', 'pd', 'cc-by-4.0', 'cc-by-sa-4.0'];
    private const UA = 'PlantAtHome/1.0 (https://plantathome.in)';

    public function __construct(int $target = 5)
    {
        $this->target = $target;
    }

    /**
     * Ensure the product has up to `target` images. Returns the ordered images.
     * @return \Illuminate\Support\Collection<ProductImage>
     */
    public function fetchFor(Product $product): \Illuminate\Support\Collection
    {
        $slug = $product->slug;
        $existing = $product->images()->count();
        if ($existing >= $this->target) {
            return $product->images()->get();
        }

        // Reuse: if S3 already has objects for this slug, relink without re-downloading.
        $existingKeys = $this->existingS3Keys($slug);
        if (!empty($existingKeys) && $existing === 0) {
            $this->linkExisting($product, $existingKeys);
            if ($product->images()->count() >= $this->target) {
                return $product->images()->get();
            }
        }

        $sci  = optional($product->plantAttribute)->scientific_name;
        $name = $product->name;

        $candidates = $this->gather($name, $sci);

        $n = $product->images()->count();
        foreach ($candidates as $cand) {
            if ($n >= $this->target) break;
            try {
                $resp = Http::withHeaders(['User-Agent' => self::UA])->timeout(20)->get($cand['url']);
                if (!$resp->ok()) continue;
                $bytes = $resp->body();
                if (strlen($bytes) < 3000) continue; // skip tiny/broken

                $key = "plants/{$slug}/" . ($n + 1) . ".jpg";
                Storage::disk('s3')->put($key, $bytes); // no ACL — bucket policy is public
                $url = $this->publicUrl($key);

                $product->images()->create([
                    'url'         => $url,
                    'alt'         => $name,
                    'sort_order'  => $n,
                    'is_primary'  => $n === 0,
                    'source'      => $cand['source'],
                    'attribution' => $cand['attribution'],
                ]);
                $n++;
            } catch (\Throwable $e) {
                // skip this candidate
            }
        }

        $product->syncImageColumns();
        return $product->images()->get();
    }

    // ── sources ────────────────────────────────────────────────────────────
    private function gather(string $name, ?string $sci): array
    {
        $out = [];
        $seen = [];
        $push = function (array $list) use (&$out, &$seen) {
            foreach ($list as $c) {
                $k = strtok($c['url'], '?');
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $out[] = $c;
                if (count($out) >= $this->target) return true;
            }
            return false;
        };
        if ($push($this->fromInaturalist($sci))) return $out;
        if ($push($this->fromWikimedia($sci ?: $name))) return $out;
        if ($push($this->fromPixabay($name))) return $out;
        return $out;
    }

    private function fromInaturalist(?string $sci): array
    {
        if (!$sci) return [];
        try {
            $r = Http::withHeaders(['User-Agent' => self::UA])->timeout(20)
                ->get('https://api.inaturalist.org/v1/taxa', ['q' => $sci, 'rank' => 'species', 'per_page' => 1]);
            $results = $r->json('results') ?? [];
            if (empty($results)) return [];
            $out = [];
            foreach (($results[0]['taxon_photos'] ?? []) as $tp) {
                $photo = $tp['photo'] ?? [];
                $lic = strtolower($photo['license_code'] ?? '');
                if (!in_array($lic, self::OK_LICENSES, true)) continue;
                $url = $photo['medium_url'] ?? str_replace('square', 'medium', $photo['url'] ?? '');
                if ($url) {
                    $out[] = ['url' => $url, 'source' => 'inaturalist',
                        'attribution' => 'iNaturalist / ' . substr($photo['attribution'] ?? '', 0, 200)];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function fromWikimedia(string $q): array
    {
        try {
            $r = Http::withHeaders(['User-Agent' => self::UA])->timeout(20)
                ->get('https://commons.wikimedia.org/w/api.php', [
                    'action' => 'query', 'generator' => 'search', 'gsrsearch' => $q,
                    'gsrnamespace' => 6, 'gsrlimit' => 6, 'prop' => 'imageinfo',
                    'iiprop' => 'url', 'iiurlwidth' => 900, 'format' => 'json',
                ]);
            $pages = $r->json('query.pages') ?? [];
            $out = [];
            foreach ($pages as $p) {
                foreach (($p['imageinfo'] ?? []) as $ii) {
                    $thumb = $ii['thumburl'] ?? null;
                    if ($thumb && preg_match('/\.(jpg|jpeg|png)$/i', $thumb)) {
                        $out[] = ['url' => $thumb, 'source' => 'wikimedia', 'attribution' => 'Wikimedia Commons'];
                    }
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function fromPixabay(string $name): array
    {
        $key = config('services.pixabay.key');
        if (!$key) return [];
        try {
            $r = Http::withHeaders(['User-Agent' => self::UA])->timeout(20)
                ->get('https://pixabay.com/api/', [
                    'key' => $key, 'q' => $name . ' plant', 'image_type' => 'photo',
                    'safesearch' => 'true', 'per_page' => 8,
                ]);
            $out = [];
            foreach (($r->json('hits') ?? []) as $h) {
                $u = $h['largeImageURL'] ?? $h['webformatURL'] ?? null;
                if ($u) $out[] = ['url' => $u, 'source' => 'pixabay', 'attribution' => 'Pixabay'];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── S3 helpers ─────────────────────────────────────────────────────────
    private function existingS3Keys(string $slug): array
    {
        try {
            return Storage::disk('s3')->files("plants/{$slug}");
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function linkExisting(Product $product, array $keys): void
    {
        sort($keys);
        $i = 0;
        foreach ($keys as $key) {
            $product->images()->create([
                'url'        => $this->publicUrl($key),
                'alt'        => $product->name,
                'sort_order' => $i,
                'is_primary' => $i === 0,
                'source'     => 'reused',
            ]);
            $i++;
            if ($i >= $this->target) break;
        }
        $product->syncImageColumns();
    }

    private function publicUrl(string $key): string
    {
        $bucket = env('AWS_BUCKET', 'plantathome-media-prod');
        $region = env('AWS_DEFAULT_REGION', 'ap-south-1');
        return "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}";
    }
}
