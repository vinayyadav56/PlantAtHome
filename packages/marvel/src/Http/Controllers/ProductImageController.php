<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductImage;

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
