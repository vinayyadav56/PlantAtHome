<?php

namespace Tests\Feature\ContentBatches;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\ProductContentBatch;
use Marvel\Database\Models\ProductContentJob;
use Marvel\Services\MissingPlantContent;
use Tests\TestCase;

/**
 * Base for bulk AI product-content tests: in-memory sqlite with the feature's
 * real migrations (batches, plant_attributes + the SEO columns, row metrics)
 * plus a minimal products/categories/types schema (the full marvel product
 * migration pulls in far more than this feature touches), a sync queue, and
 * the `ai` container binding swapped for a recording fake — no test may reach
 * OpenAI, and "did we make a paid call at all?" is itself an assertion.
 */
abstract class ContentBatchTestCase extends TestCase
{
    protected FakeAi $ai;

    protected int $plantsTypeId;

    protected int $potsTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
            'queue.default'                          => 'sync',
            // Fast tests: no pacing sleeps, no retry cooldown.
            'content-batches.rate_per_minute'        => 1_000_000,
            'content-batches.retry_cooldown_seconds' => 0,
            'content-batches.row_max_attempts'       => 3,
            'content-batches.max_rows'               => 2000,
            'content-batches.max_estimated_cost'     => 50,
        ]);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });
        // Product carries Sluggable + Metable, both of which touch storage on
        // every save — the columns/table have to exist even though this feature
        // only ever writes `description`.
        Schema::create('products', function (Blueprint $t) {
            // Master Catalog membership + listing switch. Defaulted TRUE in stubs, not FALSE:
            // production starts empty by design, but a fixture that had to opt every product in
            // would make each existing test assert the new gate instead of what it was written for.
            $t->boolean('is_available_product')->default(true);
            $t->boolean('listing_enabled')->default(true);
            $t->timestamp('available_at')->nullable();
            $t->unsignedBigInteger('available_by')->nullable();
            $t->boolean('track_stock')->default(false);
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->longText('description')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
            // Read by the missing-content scope (seo_title/seo_description come from the real migration).
            $t->string('language')->default('en');
            $t->string('status')->default('publish');
            $t->unsignedBigInteger('master_product_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('products_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('key')->nullable();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('category_id');
        });
        Schema::create('types', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        // stats() reports the same useAi switch Marvel\Ai\Base enforces.
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert(['options' => json_encode(['useAi' => true])]);
        $this->plantsTypeId = (int) DB::table('types')->insertGetId(['name' => 'Plants', 'slug' => 'plants', 'language' => 'en']);
        $this->potsTypeId   = (int) DB::table('types')->insertGetId(['name' => 'Pots', 'slug' => 'pots', 'language' => 'en']);

        foreach ([
            // Saving a Product fires the translation observer, which reads the
            // overlay cache table — writing a description touches it too.
            '2026_07_11_000000_create_translations_cache_table.php',
            '2026_07_25_130000_create_product_content_batches_table.php',
            '2026_06_01_151945_create_plant_attributes_table.php',
            '2026_07_13_000200_add_botanical_fields_to_plant_attributes.php', // + products.seo_title/seo_description
            '2026_10_12_000100_add_ai_metrics_to_product_content_jobs.php',
        ] as $migration) {
            (require base_path('packages/marvel/database/migrations/' . $migration))->up();
        }

        $this->ai = new FakeAi();
        $this->app->instance('ai', $this->ai);

        $this->withoutMiddleware();
    }

    /** @param array<int, array{0: string, 1: string|null}> $products [name, description] */
    protected function seedProducts(array $products, int $typeId = 1): array
    {
        $ids = [];
        foreach ($products as [$name, $description]) {
            $ids[] = (int) DB::table('products')->insertGetId([
                'name'        => $name,
                'description' => $description,
                'type_id'     => $typeId,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        return $ids;
    }

    protected function seedCategory(string $name, string $slug, int $typeId = 1): int
    {
        return (int) DB::table('categories')->insertGetId([
            'name'    => $name,
            'slug'    => $slug,
            'type_id' => $typeId,
        ]);
    }

    /** Every missing-content field filled with a real value. */
    protected function completeContent(): array
    {
        return [
            'product'   => [
                'description'     => '<p>An established favourite with a written description.</p>',
                'seo_title'       => 'Written Title',
                'seo_description' => 'A hand-written meta description for this plant.',
            ],
            'attribute' => [
                'hindi_name'        => 'तुलसी',
                'scientific_name'   => 'Ocimum tenuiflorum',
                'sunlight'          => 'Full Sun',
                'water_requirement' => 'Moderate',
            ],
        ];
    }

    /**
     * A product of type plants (default language, published) — complete unless
     * overridden. Pass $attributes = null for a plant with no plant_attributes row.
     */
    protected function seedPlant(string $name, array $product = [], ?array $attributes = []): int
    {
        $full = $this->completeContent();
        $id   = (int) DB::table('products')->insertGetId(array_merge([
            'name'       => $name,
            'slug'       => Str::slug($name) . '-' . (DB::table('products')->max('id') + 1),
            'type_id'    => $this->plantsTypeId,
            'language'   => 'en',
            'status'     => 'publish',
            'created_at' => now(),
            'updated_at' => now(),
        ], $full['product'], $product));

        if ($attributes !== null) {
            DB::table('plant_attributes')->insert(array_merge([
                'product_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ], $full['attribute'], $attributes));
        }

        return $id;
    }

    /** Row values for one plant: products + plant_attributes columns. */
    protected function plantRow(int $productId): object
    {
        return DB::table('products')
            ->leftJoin('plant_attributes', 'plant_attributes.product_id', '=', 'products.id')
            ->where('products.id', $productId)
            ->select('products.*', 'plant_attributes.hindi_name', 'plant_attributes.scientific_name', 'plant_attributes.sunlight', 'plant_attributes.water_requirement', 'plant_attributes.regional_names')
            ->first();
    }

    /**
     * A missing-content run built the way the controller builds it.
     *
     * @param array<int, string[]> $todoByProduct product_id => fields queued
     */
    protected function makeMissingBatch(array $todoByProduct, array $overrides = []): ProductContentBatch
    {
        $batch = ProductContentBatch::create(array_merge([
            'options'    => [
                'mode'                   => ProductContentBatch::MODE_MISSING,
                'fields'                 => MissingPlantContent::FIELDS,
                'fields_missing_initial' => array_sum(array_map('count', $todoByProduct)),
            ],
            'status'     => ProductContentBatch::STATUS_PENDING,
            'total_rows' => count($todoByProduct),
        ], $overrides));

        foreach ($todoByProduct as $productId => $todo) {
            ProductContentJob::create([
                'batch_id'       => $batch->id,
                'product_id'     => $productId,
                'product_name'   => 'Plant ' . $productId,
                'status'         => ProductContentJob::STATUS_PENDING,
                'fields_missing' => count($todo),
                'changes'        => ['missing_before' => $todo],
            ]);
        }

        return $batch->fresh();
    }

    /** @param int[] $productIds */
    protected function makeBatch(array $productIds, array $options = [], array $overrides = []): ProductContentBatch
    {
        $batch = ProductContentBatch::create(array_merge([
            'created_by' => null,
            'options'    => array_merge([
                'generate'      => ['description', 'categories'],
                'length'        => 'medium',
                'tone'          => 'professional',
                'language'      => 'en',
                'category_mode' => 'append',
                'only_missing'  => false,
            ], $options),
            'status'     => ProductContentBatch::STATUS_PENDING,
            'total_rows' => count($productIds),
        ], $overrides));

        foreach ($productIds as $id) {
            ProductContentJob::create([
                'batch_id'     => $batch->id,
                'product_id'   => $id,
                'product_name' => 'Product ' . $id,
                'status'       => ProductContentJob::STATUS_PENDING,
            ]);
        }

        return $batch->fresh();
    }
}
