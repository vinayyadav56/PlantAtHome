<?php

namespace Tests\Feature\ContentBatches;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Marvel\Database\Models\ProductContentBatch;
use Marvel\Database\Models\ProductContentJob;
use Marvel\Jobs\GenerateProductContentBatchJob;
use Marvel\Services\MissingPlantContent;

/**
 * The stats strip and the run's eligibility walk: which rows are "plants",
 * how they're counted, that the catalogue is scanned in chunks, and that the
 * endpoints are locked down to super admins.
 */
final class MissingContentStatsTest extends ContentBatchTestCase
{
    public function test_stats_count_a_mixed_catalogue(): void
    {
        $complete = $this->seedPlant('Areca Palm');
        $this->seedPlant('Nag Paudha Snake Plant', [], ['hindi_name' => 'Nag Paudha']);          // Roman "Hindi" is not Hindi yet
        $draft    = $this->seedPlant('Draft Fern', ['status' => 'draft'], ['hindi_name' => null]); // any status is in scope
        $this->seedPlant('Areca Palm Dwarf', ['master_product_id' => $complete, 'seo_title' => ''], []); // a variety
        $this->seedPlant('Bare Jade', [], null);                                                   // no plant_attributes row
        $flagged  = $this->seedPlant('Mystery Succulent', [], ['scientific_name' => null]);

        // Out of scope: a pot, the Hindi-language copy of a plant, a trashed plant.
        $this->seedPlant('Terracotta Pot', ['type_id' => $this->potsTypeId, 'description' => null], null);
        $this->seedPlant('Areca Palm (hi)', ['language' => 'hi', 'description' => null], null);
        $this->seedPlant('Old Rose', ['deleted_at' => now(), 'description' => null], null);

        // An earlier run could not identify the succulent's species: flagged for review.
        $past = $this->makeMissingBatch([$flagged => ['scientific_name']], [
            'status'       => ProductContentBatch::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        ProductContentJob::where('batch_id', $past->id)->update([
            'status'  => ProductContentJob::STATUS_NEEDS_REVIEW,
            'changes' => json_encode(['missing_before' => ['scientific_name'], 'review' => ['scientific_name' => 'scientific name confidence low']]),
        ]);

        $stats = $this->getJson('/api/ai-content-batches/missing/stats')->assertOk()->json();

        $this->assertSame([
            'total'               => 6,
            'complete'            => 1,
            'missing_any'         => 5,
            'missing_hindi'       => 3, // draft fern + bare jade + the Roman "Nag Paudha"
            'missing_other'       => 3, // dwarf seo_title, bare jade attributes, succulent species
            'missing_both'        => 1, // bare jade
            'needs_review'        => 1,
            'ready'               => 4, // the flagged succulent has nothing left for the AI
            'ready_missing_hindi' => 3,
            'processing'          => 0,
        ], array_intersect_key($stats, array_flip(['total', 'complete', 'missing_any', 'missing_hindi', 'missing_other', 'missing_both', 'needs_review', 'ready', 'ready_missing_hindi', 'processing'])));
        $this->assertSame([
            'description'       => 0,
            'seo_title'         => 1,
            'seo_description'   => 0,
            'hindi_name'        => 3,
            'hinglish_name'     => 1, // bare jade
            'scientific_name'   => 2,
            'sunlight'          => 1,
            'water_requirement' => 1,
        ], $stats['missing_by_field']);
        $this->assertSame(MissingPlantContent::FIELDS, $stats['fields']);
        $this->assertTrue($stats['ai_enabled']);
        $this->assertSame($past->id, $stats['latest_run']['id']);
        $this->assertSame('completed', $stats['latest_run']['status']);
        $this->assertSame($past->display_id, $stats['latest_run']['display_id']);

        // While a run is active its open rows are "processing", not "ready".
        Queue::fake();
        $this->postJson('/api/ai-content-batches/missing')->assertCreated()->assertJson(['eligible' => 4, 'queued' => 4]);
        $stats = $this->getJson('/api/ai-content-batches/missing/stats')->json();
        $this->assertSame(4, $stats['processing']);
        $this->assertSame(0, $stats['ready']);
        $this->assertSame('pending', $stats['latest_run']['status']);
        $this->assertTrue(ProductContentJob::where('product_id', $draft)->where('status', ProductContentJob::STATUS_PENDING)->exists(), 'drafts are in scope');
    }

    public function test_nothing_missing_is_a_422_and_creates_no_batch(): void
    {
        $this->seedPlant('Areca Palm');
        $flagged = $this->seedPlant('Mystery Succulent', [], ['scientific_name' => null]);
        // Its only gap is flagged for review, so there is still nothing for the AI.
        $past = $this->makeMissingBatch([$flagged => ['scientific_name']], ['status' => ProductContentBatch::STATUS_COMPLETED]);
        ProductContentJob::where('batch_id', $past->id)->update([
            'status'  => ProductContentJob::STATUS_NEEDS_REVIEW,
            'changes' => json_encode(['review' => ['scientific_name' => 'not a full binomial']]),
        ]);

        $this->postJson('/api/ai-content-batches/missing')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'All plants already have the required content. Nothing needs to be generated.']);

        $this->assertSame(1, ProductContentBatch::count(), 'no new batch');
        $this->assertSame(0, count($this->ai->plantCalls));
    }

    public function test_a_230_plant_catalogue_is_scanned_in_chunks_and_capped(): void
    {
        Queue::fake();
        $this->app->instance(MissingPlantContent::class, new MissingPlantContent(50));
        // Cost cap $0.20 at $0.002/plant = 100 plants per run.
        config(['content-batches.max_estimated_cost' => 0.2, 'content-batches.cost_per_product' => 0.002]);

        $now  = now();
        $rows = [];
        for ($i = 1; $i <= 230; $i++) {
            $rows[] = [
                'name' => "Plant {$i}", 'slug' => "plant-{$i}", 'type_id' => $this->plantsTypeId, 'language' => 'en',
                'description' => '<p>Written.</p>', 'seo_title' => 'Title', 'seo_description' => 'Meta description.',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('products')->insert($chunk);
        }
        DB::table('products')->insert(['name' => 'Complete', 'slug' => 'complete', 'type_id' => $this->plantsTypeId, 'language' => 'en']);
        $complete = (int) DB::table('products')->max('id');
        DB::table('plant_attributes')->insert(['product_id' => $complete] + $this->completeContent()['attribute']);
        DB::table('products')->where('id', $complete)->update($this->completeContent()['product']);

        DB::enableQueryLog();
        $response = $this->postJson('/api/ai-content-batches/missing')->assertCreated();
        $scans    = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'left join "plant_attributes"') && str_contains($q['query'], 'limit 50'));
        DB::disableQueryLog();

        $this->assertCount(5, $scans, '231 plants at 50 per query = 5 scans');
        $response->assertJson(['eligible' => 230, 'queued' => 100, 'total_rows' => 100, 'estimated_cost' => 0.2]);

        $batch = ProductContentBatch::findOrFail($response->json('id'));
        $this->assertSame(ProductContentBatch::MODE_MISSING, $batch->options['mode']);
        $this->assertSame(100 * 5, $batch->options['fields_missing_initial']); // no attributes row: 5 attribute fields
        $this->assertSame(100, $batch->options['missing_by_field']['hindi_name']);
        $this->assertSame(0, $batch->options['missing_by_field']['description']);

        $jobs = ProductContentJob::where('batch_id', $batch->id)->orderBy('id')->get();
        $this->assertCount(100, $jobs);
        $this->assertSame(range(1, 100), $jobs->pluck('product_id')->map(fn ($id) => (int) $id)->all(), 'the first 100 by id');
        $this->assertSame(5, $jobs[0]->fields_missing);
        $this->assertSame(['hindi_name', 'hinglish_name', 'scientific_name', 'sunlight', 'water_requirement'], $jobs[0]->changes['missing_before']);
        $this->assertSame(ProductContentJob::STATUS_PENDING, $jobs[0]->status);
        Queue::assertPushed(GenerateProductContentBatchJob::class);

        $this->assertSame(231, app(MissingPlantContent::class)->stats()['total']);
    }

    public function test_try_first_limit_queues_only_that_many(): void
    {
        Queue::fake();
        foreach (range(1, 4) as $i) {
            $this->seedPlant("Fern {$i}", [], ['hindi_name' => null]);
        }

        $this->postJson('/api/ai-content-batches/missing', ['limit' => 2])
            ->assertCreated()
            ->assertJson(['eligible' => 4, 'queued' => 2, 'total_rows' => 2]);

        $this->postJson('/api/ai-content-batches/missing', ['limit' => 0])->assertStatus(422);
        $this->postJson('/api/ai-content-batches/missing', ['limit' => 2001])->assertStatus(422);
    }

    public function test_routes_are_super_admin_sanctum_and_throttled_and_ignore_client_product_ids(): void
    {
        $routes = app('router')->getRoutes();

        $stats = $routes->match(Request::create('/api/ai-content-batches/missing/stats', 'GET'));
        $this->assertSame('missingStats', $stats->getActionMethod());
        // Each route has its own throttle bucket (a bare throttle:N,1 shares one
        // per-user counter with every other throttled route).
        foreach (['permission:super_admin', 'auth:sanctum', 'throttle:30,1,ai-missing-stats'] as $middleware) {
            $this->assertContains($middleware, $stats->gatherMiddleware());
        }

        $review = $routes->match(Request::create('/api/ai-content-batches/missing/review', 'GET'));
        $this->assertSame('missingReview', $review->getActionMethod());
        foreach (['permission:super_admin', 'auth:sanctum', 'throttle:30,1,ai-missing-review'] as $middleware) {
            $this->assertContains($middleware, $review->gatherMiddleware());
        }

        $start = $routes->match(Request::create('/api/ai-content-batches/missing', 'POST'));
        $this->assertSame('startMissing', $start->getActionMethod());
        foreach (['permission:super_admin', 'auth:sanctum', 'throttle:6,1,ai-missing-start'] as $middleware) {
            $this->assertContains($middleware, $start->gatherMiddleware());
        }

        // "missing" is never captured as a batch id.
        $this->assertSame('show', $routes->match(Request::create('/api/ai-content-batches/12', 'GET'))->getActionMethod());

        // The server picks the plants: ids the client sends are ignored.
        Queue::fake();
        $complete = $this->seedPlant('Areca Palm');
        $needy    = $this->seedPlant('Fern', [], ['hindi_name' => null]);
        $response = $this->postJson('/api/ai-content-batches/missing', ['product_ids' => [$complete]])
            ->assertCreated()
            ->assertJson(['queued' => 1]);
        $this->assertSame([$needy], ProductContentJob::where('batch_id', $response->json('id'))->pluck('product_id')->map(fn ($id) => (int) $id)->all());
    }
    public function test_stats_follow_the_active_run_over_a_newer_finished_one(): void
    {
        $id      = $this->seedPlant('Areca Palm', [], ['hindi_name' => null]);
        $retried = $this->makeMissingBatch([$id => ['hindi_name']], ['status' => ProductContentBatch::STATUS_PROCESSING]);
        $newer   = $this->makeMissingBatch([$id => ['hindi_name']], ['status' => ProductContentBatch::STATUS_COMPLETED]);
        $this->assertGreaterThan($retried->id, $newer->id);

        $this->assertSame($retried->id, app(MissingPlantContent::class)->stats()['latest_run']['id']);
    }

    public function test_starting_with_ai_switched_off_is_refused(): void
    {
        Queue::fake();
        $this->seedPlant('Areca Palm', [], ['hindi_name' => null]);
        DB::table('settings')->update(['options' => json_encode(['useAi' => false])]);

        $this->postJson('/api/ai-content-batches/missing')
            ->assertStatus(422)
            ->assertJson(['message' => 'AI is turned off in Settings. Turn it on to generate content.']);
        $this->assertSame(0, ProductContentBatch::count());
        Queue::assertNothingPushed();
    }

    public function test_the_review_list_shows_flagged_plants_from_any_run_with_reasons(): void
    {
        $flagged  = $this->seedPlant('Mystery Succulent', [], ['scientific_name' => null]);
        $complete = $this->seedPlant('Areca Palm');
        $old      = $this->makeMissingBatch([$flagged => ['scientific_name']], ['status' => ProductContentBatch::STATUS_COMPLETED]);
        ProductContentJob::where('batch_id', $old->id)->update([
            'status'  => ProductContentJob::STATUS_NEEDS_REVIEW,
            'changes' => json_encode(['review' => ['scientific_name' => 'scientific name confidence medium']]),
        ]);
        // A newer run exists; the flagged plant is not one of its rows.
        $this->makeMissingBatch([$complete => ['seo_title']], ['status' => ProductContentBatch::STATUS_COMPLETED]);

        $list = $this->getJson('/api/ai-content-batches/missing/review')->assertOk()->json();

        $this->assertSame(1, $list['total']);
        $this->assertSame($flagged, $list['data'][0]['product_id']);
        $this->assertSame(['scientific_name'], $list['data'][0]['fields']);
        $this->assertSame(['scientific_name' => 'scientific name confidence medium'], $list['data'][0]['reasons']);
        $this->assertNotEmpty($list['data'][0]['product_slug']);
    }
}
