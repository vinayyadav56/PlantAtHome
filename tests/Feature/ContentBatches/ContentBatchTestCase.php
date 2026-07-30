<?php

namespace Tests\Feature\ContentBatches;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\ProductContentBatch;
use Marvel\Database\Models\ProductContentJob;
use Tests\TestCase;

/**
 * Base for bulk AI product-content tests: in-memory sqlite with the feature's
 * real migration plus a minimal products/categories schema (the full marvel
 * product migration pulls in far more than this feature touches), a sync queue,
 * and the `ai` container binding swapped for a recording fake — no test may
 * reach OpenAI, and "did we make a paid call at all?" is itself an assertion.
 */
abstract class ContentBatchTestCase extends TestCase
{
    protected FakeAi $ai;

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
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->longText('description')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
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

        foreach ([
            // Saving a Product fires the translation observer, which reads the
            // overlay cache table — writing a description touches it too.
            '2026_07_11_000000_create_translations_cache_table.php',
            '2026_07_25_130000_create_product_content_batches_table.php',
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
