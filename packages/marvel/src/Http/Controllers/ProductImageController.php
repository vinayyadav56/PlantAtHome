<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductImage;
use Marvel\Database\Models\Type;
use Marvel\Services\PlantImageFetcher;
use Symfony\Component\Process\Process;

/**
 * Admin management of a product's gallery images (the product_images table).
 * Image files live on S3; this manages the URL rows + their display order.
 */
class ProductImageController extends CoreController
{
    /** GET /products/{id}/images — ordered list. */
    public function index($id)
    {
        $product = Product::findOrFail($id);
        return $product->images()->get();
    }

    /** POST /products/{id}/images — add an image by URL (appended at the end). */
    public function store(Request $request, $id)
    {
        $request->validate([
            'url'           => ['required', 'string', 'max:2048'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'alt'           => ['nullable', 'string', 'max:255'],
            'source'        => ['nullable', 'string', 'max:30'],
            'attribution'   => ['nullable', 'string', 'max:500'],
        ]);

        $product = Product::findOrFail($id);
        $nextOrder = (int) $product->images()->max('sort_order') + 1;
        $isFirst = $product->images()->count() === 0;

        $image = $product->images()->create([
            'url'           => $request->input('url'),
            'thumbnail_url' => $request->input('thumbnail_url'),
            'alt'           => $request->input('alt'),
            'sort_order'    => $nextOrder,
            'is_primary'    => $isFirst,
            'source'        => $request->input('source', 'manual'),
            'attribution'   => $request->input('attribution'),
        ]);

        $product->syncImageColumns();

        return $image;
    }

    /** PATCH /products/{id}/images/reorder — body { ids: [...] } sets sort_order by index. */
    public function reorder(Request $request, $id)
    {
        $request->validate(['ids' => ['required', 'array']]);
        $product = Product::findOrFail($id);

        foreach (array_values($request->input('ids')) as $index => $imageId) {
            ProductImage::where('product_id', $product->id)
                ->where('id', $imageId)
                ->update(['sort_order' => $index]);
        }

        $product->syncImageColumns();

        return $product->images()->get();
    }

    /** PATCH /products/{id}/images/{image}/primary — set the hero (featured) image. */
    public function setPrimary($id, $imageId)
    {
        $product = Product::findOrFail($id);
        ProductImage::where('product_id', $product->id)->update(['is_primary' => false]);
        // The featured photo must be published, so mark it in_gallery too.
        ProductImage::where('product_id', $product->id)->where('id', $imageId)
            ->update(['is_primary' => true, 'in_gallery' => true]);

        $product->syncImageColumns();

        return $product->images()->get();
    }

    /**
     * PATCH /products/{id}/images/{image}/gallery — publish/unpublish a library
     * photo. body { in_gallery: bool }. Unpublishing keeps the row in the
     * library (the plant's pictures) but drops it from the product gallery.
     */
    public function setGalleryFlag(Request $request, $id, $imageId)
    {
        $request->validate(['in_gallery' => ['required', 'boolean']]);
        $inGallery = (bool) $request->input('in_gallery');

        $product = Product::findOrFail($id);
        $image = ProductImage::where('product_id', $product->id)->findOrFail($imageId);
        $image->update(['in_gallery' => $inGallery]);

        // If we just unpublished the featured photo, hand the crown to another
        // published photo so the product keeps a featured image when possible.
        if (!$inGallery && $image->is_primary) {
            $image->update(['is_primary' => false]);
            $next = $product->images()->where('in_gallery', true)->first();
            if ($next) {
                $next->update(['is_primary' => true]);
            }
        }

        $product->syncImageColumns();

        return $product->images()->get();
    }

    /** DELETE /products/{id}/images/{image} — remove the row (+ S3 object if it's ours). */
    public function destroy($id, $imageId)
    {
        $product = Product::findOrFail($id);
        $image = ProductImage::where('product_id', $product->id)->findOrFail($imageId);

        $wasPrimary = $image->is_primary;
        $this->deleteFromS3IfOwned($image->url);
        $image->delete();

        // promote a new primary if needed
        if ($wasPrimary) {
            $first = $product->images()->first();
            if ($first) {
                $first->update(['is_primary' => true]);
            }
        }

        $product->syncImageColumns();

        return ['success' => true];
    }

    /** POST /products/{id}/images/fetch — auto-fetch images for ONE plant (synchronous). */
    public function fetch($id)
    {
        $product = Product::findOrFail($id);
        $images = (new PlantImageFetcher())->fetchFor($product);
        return [
            'success' => true,
            'count'   => $images->count(),
            'images'  => $images,
        ];
    }

    /** POST /plant-images/fetch-missing — kick off the bulk fetch in the background. */
    public function fetchMissing()
    {
        $cmd = 'nohup php artisan plantathome:fetch-plant-images --missing '
            . '>> storage/logs/plant-image-fetch.log 2>&1 &';
        $process = Process::fromShellCommandline($cmd, base_path());
        $process->disableOutput();
        $process->setTimeout(null);
        $process->start();

        return [
            'success' => true,
            'started' => true,
            'message' => 'Bulk image fetch started in the background. Re-download the coverage report in a few minutes to track progress.',
        ];
    }

    /** GET /plant-images/coverage-summary — quick counts for the admin. */
    public function coverageSummary()
    {
        [$summary] = $this->coverage();
        return $summary;
    }

    /** GET /plant-images/coverage-report — CSV of every plant + image count + status. */
    public function coverageReport()
    {
        [, $rows] = $this->coverage();
        $csv = '';
        foreach ($rows as $r) {
            $csv .= implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $r)) . "\n";
        }
        $filename = 'plant-image-coverage-' . now()->format('Ymd-His') . '.csv';
        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * GET /plant-images/list — paginated list of plants with their image
     * count + status, for the admin "Plant Images" management screen.
     * Query: status=all|complete|partial|missing, name, page, limit, target.
     */
    public function list(Request $request)
    {
        $target = (int) $request->input('target', 5) ?: 5;
        $status = $request->input('status', 'all');
        $search = trim((string) $request->input('name', ''));
        $limit  = (int) $request->input('limit', 20) ?: 20;

        // Vertical scope. This screen was pinned to `plants`, which made it unusable as the
        // centralised image manager the catalogue actually needs — tools, pots and farmbox
        // products have images too and had nowhere to manage them. `vertical` is a type SLUG so
        // the client can build its pills from GET types rather than hardcoding a list that
        // silently rots when a vertical is added; absent or `all` means every vertical.
        $vertical = trim((string) $request->input('vertical', ''));
        $query = Product::query()
            ->withCount('images')
            ->with('plantAttribute:id,product_id,scientific_name');

        if ($vertical !== '' && $vertical !== 'all') {
            $type = Type::where('slug', $vertical)->where('language', 'en')->first();
            if (!$type) {
                return ['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => $limit, 'total' => 0];
            }
            $query->where('type_id', $type->id);
        }

        // Varieties are a cut ACROSS verticals, not one of them — a plant variety is still a
        // plant — so it is a separate flag rather than another pill value.
        if ($request->boolean('variants_only') && \Illuminate\Support\Facades\Schema::hasColumn('products', 'master_product_id')) {
            $query->whereNotNull('master_product_id');
        }

        // Filter by image count using count-subquery constraints (NOT a `having`
        // on the withCount alias — that breaks paginate()'s separate count query).
        switch ($status) {
            case 'missing':
                $query->whereDoesntHave('images');
                break;
            case 'partial':
                $query->has('images', '>=', 1)->has('images', '<', $target);
                break;
            case 'complete':
                $query->has('images', '>=', $target);
                break;
        }

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->orderBy('name')->paginate($limit)->through(function ($p) use ($target) {
            $n = (int) $p->images_count;
            return [
                'id'              => $p->id,
                'slug'            => $p->slug,
                'name'            => $p->name,
                'scientific_name' => optional($p->plantAttribute)->scientific_name,
                'image_count'     => $n,
                'status'          => $n >= $target ? 'complete' : ($n > 0 ? 'partial' : 'missing'),
                'thumbnail'       => data_get($p->image, 'thumbnail') ?: data_get($p->image, 'original'),
            ];
        });
    }

    /** Build coverage summary + rows (slug,name,scientific_name,image_count,status). */
    private function coverage(int $target = 5): array
    {
        $type = Type::where('slug', 'plants')->where('language', 'en')->first();
        $summary = ['total' => 0, 'complete' => 0, 'partial' => 0, 'missing' => 0];
        $rows = [['slug', 'name', 'scientific_name', 'image_count', 'status']];
        if (!$type) return [$summary, $rows];

        Product::where('type_id', $type->id)
            ->withCount('images')
            ->with('plantAttribute:id,product_id,scientific_name')
            ->orderBy('slug')
            ->chunk(300, function ($plants) use (&$summary, &$rows, $target) {
                foreach ($plants as $p) {
                    $n = (int) $p->images_count;
                    $status = $n >= $target ? 'complete' : ($n > 0 ? 'partial' : 'missing');
                    $summary['total']++;
                    $summary[$status]++;
                    $rows[] = [$p->slug, $p->name, optional($p->plantAttribute)->scientific_name, $n, $status];
                }
            });

        return [$summary, $rows];
    }

    /** Delete the S3 object if the URL points at our bucket. */
    protected function deleteFromS3IfOwned(?string $url): void
    {
        if (!$url) return;
        $bucketHost = env('AWS_BUCKET', 'plantathome-media-prod');
        if (!str_contains($url, $bucketHost)) return;

        try {
            // extract the key after the first single slash following the host
            $parts = parse_url($url);
            $key = ltrim($parts['path'] ?? '', '/');
            if ($key) {
                Storage::disk('s3')->delete($key);
            }
        } catch (\Throwable $e) {
            // non-fatal — orphan object is acceptable
        }
    }
}
