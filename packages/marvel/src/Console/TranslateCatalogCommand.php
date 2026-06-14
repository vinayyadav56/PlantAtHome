<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Category;
use Marvel\Traits\AiTranslationTrait;

/**
 * AI-translate the product/category catalog into the Indian languages.
 *
 * Pickbazar serves localized content by storing ONE row per language, sharing the same `slug`,
 * with `language` set and name/description translated (the shop filters `?language=`). This command
 * replicates each default-language row, AI-translates its text, nulls `sku` (the canonical row keeps
 * it — sku is uniquely indexed), copies category/tag pivots, and records the `translations` pivot.
 *
 * SAFETY: dry-run by default. ALWAYS validate on staging with a single product before any bulk run:
 *   php artisan marvel:translate-catalog --entity=product --product=123 --langs=hi --commit
 * then verify the shop renders /products/<slug>?language=hi. Then scale:
 *   php artisan marvel:translate-catalog --langs=hi,bn,ta --commit --limit=50
 */
class TranslateCatalogCommand extends Command
{
    use AiTranslationTrait;

    protected $signature = 'marvel:translate-catalog
        {--entity=both : product | category | both}
        {--langs= : Comma-separated ISO codes (default: all 22 Indian languages)}
        {--product= : Only this product id (testing)}
        {--limit=0 : Max source rows per entity (0 = all)}
        {--model=claude-sonnet-4-6 : Claude model}
        {--commit : Actually write (default is a dry run)}';

    protected $description = 'AI-translate product/category content into the Indian languages';

    private const LANG_NAMES = [
        'hi' => 'Hindi', 'bn' => 'Bengali', 'ta' => 'Tamil', 'te' => 'Telugu', 'mr' => 'Marathi',
        'gu' => 'Gujarati', 'kn' => 'Kannada', 'ml' => 'Malayalam', 'pa' => 'Punjabi', 'or' => 'Odia',
        'as' => 'Assamese', 'ur' => 'Urdu', 'sa' => 'Sanskrit', 'ne' => 'Nepali', 'kok' => 'Konkani',
        'mai' => 'Maithili', 'doi' => 'Dogri', 'ks' => 'Kashmiri', 'sd' => 'Sindhi', 'sat' => 'Santali',
        'mni' => 'Manipuri (Meitei)', 'brx' => 'Bodo',
    ];

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $model = (string) $this->option('model');
        $limit = (int) $this->option('limit');
        $langs = $this->option('langs')
            ? array_map('trim', explode(',', $this->option('langs')))
            : array_keys(self::LANG_NAMES);
        $entity = (string) $this->option('entity');
        $source = defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'en';

        if (!$commit) {
            $this->warn('DRY RUN — no rows will be written. Add --commit to apply.');
        }

        if (in_array($entity, ['product', 'both'], true)) {
            $q = Product::where('language', $source);
            if ($this->option('product')) {
                $q->where('id', (int) $this->option('product'));
            }
            if ($limit > 0) {
                $q->limit($limit);
            }
            $products = $q->get();
            $this->info("Products: {$products->count()} source rows");
            foreach ($products as $product) {
                foreach ($langs as $lang) {
                    $this->translateProduct($product, $lang, $source, $model, $commit);
                }
            }
        }

        if (in_array($entity, ['category', 'both'], true)) {
            $q = Category::where('language', $source);
            if ($limit > 0) {
                $q->limit($limit);
            }
            $categories = $q->get();
            $this->info("Categories: {$categories->count()} source rows");
            foreach ($categories as $category) {
                foreach ($langs as $lang) {
                    $this->translateCategory($category, $lang, $source, $model, $commit);
                }
            }
        }

        $this->info("\nDone. Have native speakers review machine translations before production.");
        return self::SUCCESS;
    }

    private function translateProduct(Product $product, string $lang, string $source, string $model, bool $commit): void
    {
        $langName = self::LANG_NAMES[$lang] ?? $lang;
        // Idempotency: a same-slug row already in this language?
        $exists = Product::where('slug', $product->slug)->where('language', $lang)->exists();
        if ($exists) {
            $this->line("  [skip] product#{$product->id} {$product->slug} -> {$lang} (exists)");
            return;
        }

        $map = array_filter([
            'name' => (string) $product->name,
            'description' => (string) $product->description,
        ], fn ($v) => $v !== '');
        if (empty($map)) {
            return;
        }

        try {
            $tr = $this->aiTranslateMap($map, $langName, $model);
        } catch (\Throwable $e) {
            $this->error("  product#{$product->id} {$lang}: " . $e->getMessage());
            return;
        }

        $this->line("  product#{$product->id} {$product->slug} -> {$lang}: " . mb_substr($tr['name'] ?? '', 0, 40));
        if (!$commit) {
            return;
        }

        DB::transaction(function () use ($product, $lang, $source, $tr) {
            $copy = $product->replicate();
            $copy->language = $lang;
            $copy->name = $tr['name'] ?? $product->name;
            if (isset($tr['description'])) {
                $copy->description = $tr['description'];
            }
            $copy->sku = null; // canonical row keeps the (uniquely-indexed) sku
            $copy->save();

            // Copy the pivots the storefront needs to render the product.
            $copy->categories()->sync($product->categories()->pluck('categories.id')->all());
            $copy->tags()->sync($product->tags()->pluck('tags.id')->all());

            // Record the translation link (admin language grouping) — idempotent insert.
            $product->storeTranslation($product->id, $source, $source);
            $copy->storeTranslation($product->id, $lang, $source);
        });
    }

    private function translateCategory(Category $category, string $lang, string $source, string $model, bool $commit): void
    {
        $langName = self::LANG_NAMES[$lang] ?? $lang;
        $exists = Category::where('slug', $category->slug)->where('language', $lang)->exists();
        if ($exists) {
            $this->line("  [skip] category#{$category->id} {$category->slug} -> {$lang} (exists)");
            return;
        }

        $map = array_filter([
            'name' => (string) $category->name,
            'details' => (string) ($category->details ?? ''),
        ], fn ($v) => $v !== '');
        if (empty($map)) {
            return;
        }

        try {
            $tr = $this->aiTranslateMap($map, $langName, $model);
        } catch (\Throwable $e) {
            $this->error("  category#{$category->id} {$lang}: " . $e->getMessage());
            return;
        }

        $this->line("  category#{$category->id} {$category->slug} -> {$lang}: " . mb_substr($tr['name'] ?? '', 0, 40));
        if (!$commit) {
            return;
        }

        DB::transaction(function () use ($category, $lang, $source, $tr) {
            $copy = $category->replicate();
            $copy->language = $lang;
            $copy->name = $tr['name'] ?? $category->name;
            if (isset($tr['details'])) {
                $copy->details = $tr['details'];
            }
            $copy->save();
            $category->storeTranslation($category->id, $source, $source);
            $copy->storeTranslation($category->id, $lang, $source);
        });
    }
}
