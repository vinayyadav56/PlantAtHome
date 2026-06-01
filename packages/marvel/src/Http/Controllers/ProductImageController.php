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

    /** PATCH /products/{id}/images/{image}/primary — set the hero image. */
    public function setPrimary($id, $imageId)
    {
        $product = Product::findOrFail($id);
        ProductImage::where('product_id', $product->id)->update(['is_primary' => false]);
        ProductImage::where('product_id', $product->id)->where('id', $imageId)->update(['is_primary' => true]);

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
