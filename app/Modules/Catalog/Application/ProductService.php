<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\AttributeType;
use App\Modules\Catalog\Domain\Events\ProductPublished;
use App\Modules\Catalog\Domain\Events\ProductUpdated;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Infrastructure\Models\Attribute;
use App\Modules\Catalog\Infrastructure\Models\AttributeValue;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductMedia;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product use-cases: create/update/publish, plus variants, media and attribute
 * values. Catalog events (ProductPublished / ProductUpdated) are emitted through
 * the transactional outbox so downstream contexts (Search, Pricing, Analytics)
 * react off the request path. All mutations run in a transaction with the event.
 */
class ProductService
{
    public function __construct(private readonly EventPublisher $events)
    {
    }

    public function create(array $data, ?string $actorUuid = null): Product
    {
        return DB::transaction(function () use ($data, $actorUuid) {
            $status = $data['status'] ?? ProductStatus::DRAFT;
            if (! ProductStatus::isValid($status)) {
                throw DomainActionException::unprocessable("Invalid product status: {$status}", 'INVALID_STATUS', 'status');
            }

            $product = new Product([
                'category_id'     => $this->resolveCategoryId($data['category_uuid'] ?? null),
                'name'            => $data['name'],
                'slug'            => $this->uniqueSlug($data['slug'] ?? $data['name']),
                'botanical_name'  => $data['botanical_name'] ?? null,
                'hindi_name'      => $data['hindi_name'] ?? null,
                'description'     => $data['description'] ?? null,
                'care'            => $data['care'] ?? null,
                'status'          => $status,
                'seo_title'       => $data['seo_title'] ?? null,
                'seo_description' => $data['seo_description'] ?? null,
                'created_by'      => $actorUuid,
                'updated_by'      => $actorUuid,
            ]);
            if ($status === ProductStatus::PUBLISHED) {
                $product->published_at = Carbon::now();
            }
            $product->save();

            foreach (($data['variants'] ?? []) as $variant) {
                $this->makeVariant($product, $variant, $actorUuid);
            }
            foreach (($data['media'] ?? []) as $media) {
                $this->makeMedia($product, $media);
            }
            $this->setAttributeValues($product, $data['attributes'] ?? []);

            if ($product->isPublished()) {
                $this->events->publish($this->publishedEvent($product));
            }

            return $product->fresh(['category', 'variants', 'media', 'attributeValues.attribute']);
        });
    }

    public function update(Product $product, array $data, ?string $actorUuid = null): Product
    {
        return DB::transaction(function () use ($product, $data, $actorUuid) {
            $changed = [];
            foreach (['name', 'botanical_name', 'hindi_name', 'description', 'care', 'seo_title', 'seo_description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $product->{$field} = $data[$field];
                    $changed[] = $field;
                }
            }
            if (array_key_exists('category_uuid', $data)) {
                $product->category_id = $this->resolveCategoryId($data['category_uuid']);
                $changed[] = 'category';
            }
            if (! empty($data['slug']) && $data['slug'] !== $product->slug) {
                $product->slug = $this->uniqueSlug($data['slug'], $product->id);
                $changed[] = 'slug';
            }
            if (array_key_exists('attributes', $data)) {
                $this->setAttributeValues($product, $data['attributes']);
                $changed[] = 'attributes';
            }

            $product->updated_by = $actorUuid;
            $product->save();

            // Only a live product's changes matter to downstream read models.
            if ($product->isPublished() && $changed !== []) {
                $this->events->publish(new ProductUpdated($product->uuid, $changed));
            }

            return $product->fresh(['category', 'variants', 'media', 'attributeValues.attribute']);
        });
    }

    public function publish(Product $product, ?string $actorUuid = null): Product
    {
        if ($product->isPublished()) {
            return $product; // idempotent
        }

        return DB::transaction(function () use ($product, $actorUuid) {
            $product->status = ProductStatus::PUBLISHED;
            $product->published_at = Carbon::now();
            $product->updated_by = $actorUuid;
            $product->save();

            $this->events->publish($this->publishedEvent($product));

            return $product;
        });
    }

    public function archive(Product $product, ?string $actorUuid = null): Product
    {
        $product->status = ProductStatus::ARCHIVED;
        $product->updated_by = $actorUuid;
        $product->save();

        return $product;
    }

    public function addVariant(Product $product, array $data, ?string $actorUuid = null): ProductVariant
    {
        return DB::transaction(function () use ($product, $data, $actorUuid) {
            $variant = $this->makeVariant($product, $data, $actorUuid);

            if ($product->isPublished()) {
                $this->events->publish(new ProductUpdated($product->uuid, ['variants']));
            }

            return $variant;
        });
    }

    /** @param array<int,array{attribute:string,value:mixed}> $attributes */
    public function setAttributeValues(Product $product, array $attributes): void
    {
        foreach ($attributes as $entry) {
            $ref = $entry['attribute'] ?? null;
            if (! $ref || ! array_key_exists('value', $entry)) {
                continue;
            }

            $attribute = Attribute::where('uuid', $ref)->orWhere('code', $ref)->first();
            if (! $attribute) {
                throw DomainActionException::unprocessable("Unknown attribute: {$ref}", 'UNKNOWN_ATTRIBUTE', 'attributes');
            }

            $coerced = AttributeType::coerce($attribute->type, $entry['value']);

            AttributeValue::updateOrCreate(
                ['attribute_id' => $attribute->id, 'product_id' => $product->id],
                ['value' => ['value' => $coerced]], // wrapped so scalar values survive the json cast
            );
        }
    }

    private function makeVariant(Product $product, array $data, ?string $actorUuid): ProductVariant
    {
        return $product->variants()->create([
            'sku'          => $data['sku'] ?? $this->generateSku($product, $data['size_code'] ?? null),
            'size_code'    => $data['size_code'] ?? null,
            'name'         => $data['name'] ?? null,
            'weight_grams' => $data['weight_grams'] ?? null,
            'dimensions'   => $data['dimensions'] ?? null,
            'status'       => $data['status'] ?? 'active',
            'sort'         => $data['sort'] ?? 0,
            'created_by'   => $actorUuid,
            'updated_by'   => $actorUuid,
        ]);
    }

    private function makeMedia(Product $product, array $data): ProductMedia
    {
        return $product->media()->create([
            'url'  => $data['url'],
            'type' => $data['type'] ?? 'image',
            'alt'  => $data['alt'] ?? null,
            'sort' => $data['sort'] ?? 0,
        ]);
    }

    private function publishedEvent(Product $product): ProductPublished
    {
        return new ProductPublished(
            productUuid: $product->uuid,
            slug: $product->slug,
            categoryUuid: $product->category?->uuid,
            name: $product->name,
        );
    }

    private function resolveCategoryId(?string $categoryUuid): ?int
    {
        if (! $categoryUuid) {
            return null;
        }
        $category = Category::byUuid($categoryUuid)->first();
        if (! $category) {
            throw DomainActionException::unprocessable('Category not found.', 'CATEGORY_NOT_FOUND', 'category_uuid');
        }

        return $category->id;
    }

    /** A collision-safe SKU: readable prefix + size + a random discriminator,
     *  re-rolled until unique (two variants of the same size never collide). */
    private function generateSku(Product $product, ?string $sizeCode): string
    {
        $base = substr(strtoupper(Str::slug($product->slug, '')), 0, 12) ?: 'SKU';
        $size = $sizeCode ? '-'.strtoupper($sizeCode) : '';

        do {
            $candidate = $base.$size.'-'.strtoupper(Str::random(5));
        } while (ProductVariant::withTrashed()->where('sku', $candidate)->exists());

        return $candidate;
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base) ?: 'product';
        $candidate = $slug;
        $n = 1;
        while (Product::withTrashed()
            ->where('slug', $candidate)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $candidate = $slug.'-'.(++$n);
        }

        return $candidate;
    }
}
