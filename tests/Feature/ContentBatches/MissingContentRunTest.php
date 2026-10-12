<?php

namespace Tests\Feature\ContentBatches;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Marvel\Ai\InvalidAiOutput;
use Marvel\Console\SweepContentBatchesCommand;
use Marvel\Database\Models\ProductContentBatch;
use Marvel\Database\Models\ProductContentJob;
use Marvel\Jobs\GenerateProductContentBatchJob;
use Marvel\Services\MissingPlantContent;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;

/**
 * A missing-content run end to end: only missing fields are asked for, nothing
 * a human wrote is ever overwritten, failures are classified, review flags
 * stick, duplicates are no-ops, and the summary adds up.
 */
final class MissingContentRunTest extends ContentBatchTestCase
{
    public function test_a_plant_missing_everything_gets_every_field(): void
    {
        $id = $this->seedPlant('Tulsi', ['description' => null, 'seo_title' => null, 'seo_description' => null], null);

        $response = $this->postJson('/api/ai-content-batches/missing')->assertCreated();

        $this->assertSame([MissingPlantContent::FIELDS], $this->ai->requestedFields());
        $this->assertSame('Tulsi', $this->ai->plantCalls[0]->name);
        $this->assertSame(config('content-batches.plant_vocabulary'), $this->ai->plantCalls[0]->vocabulary);

        $good  = FakeAi::goodValues();
        $plant = $this->plantRow($id);
        $this->assertSame($good['description'], $plant->description);
        $this->assertSame($good['seo_title'], $plant->seo_title);
        $this->assertSame($good['seo_description'], $plant->seo_description);
        $this->assertSame('तुलसी', $plant->hindi_name);
        $this->assertSame('Tulsi', $plant->hinglish_name);
        $this->assertSame(['hi' => 'तुलसी'], json_decode($plant->regional_names, true), 'mirrored like ProductRepository');
        $this->assertSame('Ocimum tenuiflorum', $plant->scientific_name);
        $this->assertSame('Full Sun', $plant->sunlight);
        $this->assertSame('Moderate', $plant->water_requirement);
        $this->assertSame(0, DB::table('products_meta')->count(), 'columns, never fluent meta rows');

        $batch = ProductContentBatch::findOrFail($response->json('id'));
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED, $batch->status);
        $row = ProductContentJob::where('batch_id', $batch->id)->sole();
        $this->assertSame(ProductContentJob::STATUS_COMPLETED, $row->status);
        $this->assertSame(MissingPlantContent::FIELDS, $row->changes['generated']);
        $this->assertSame(8, $row->fields_generated);
        $this->assertSame(0, $row->fields_missing);
        $this->assertSame(1, app(MissingPlantContent::class)->stats()['complete']);
    }

    public function test_only_the_missing_fields_are_requested(): void
    {
        $hindiOnly = $this->seedPlant('Snake Plant', [], ['hindi_name' => null]);
        $metaOnly  = $this->seedPlant('Peace Lily', ['seo_description' => ' '], []);
        $before    = $this->plantRow($hindiOnly);

        $this->postJson('/api/ai-content-batches/missing')->assertCreated();

        $this->assertSame([['hindi_name'], ['seo_description']], $this->ai->requestedFields());
        $after = $this->plantRow($hindiOnly);
        $this->assertSame('तुलसी', $after->hindi_name);
        $this->assertSame($before->description, $after->description);
        $this->assertSame($before->seo_title, $after->seo_title);
        $this->assertSame($before->scientific_name, $after->scientific_name);
        $this->assertSame(FakeAi::goodValues()['seo_description'], $this->plantRow($metaOnly)->seo_description);
        $this->assertSame('Written Title', $this->plantRow($metaOnly)->seo_title);
    }

    public function test_a_complete_plant_is_never_queued(): void
    {
        $complete = $this->seedPlant('Areca Palm');
        $needy    = $this->seedPlant('Fern', [], ['hindi_name' => null]);

        $response = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->assertJson(['eligible' => 1, 'queued' => 1]);

        $this->assertSame([$needy], ProductContentJob::where('batch_id', $response->json('id'))->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        $this->assertCount(1, $this->ai->plantCalls);
        $this->assertSame('Written Title', $this->plantRow($complete)->seo_title);
    }

    public function test_edits_after_start_and_during_the_ai_call_are_never_overwritten(): void
    {
        Queue::fake();
        $editedEarly = $this->seedPlant('Money Plant', ['seo_title' => null], ['hindi_name' => null]);
        $editedLate  = $this->seedPlant('Jade Plant', ['description' => null], ['sunlight' => null]);
        $batchId     = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');

        // An admin fills the Hindi name after the run was queued…
        DB::table('plant_attributes')->where('product_id', $editedEarly)->update(['hindi_name' => 'मनी प्लांट']);
        // …and edits the jade plant while its AI call is in flight (a legacy sunlight value included).
        $this->ai->duringPlantCall = function ($request) use ($editedLate) {
            if ($request->name === 'Jade Plant') {
                DB::table('products')->where('id', $editedLate)->update(['description' => '<p>Hand-written copy.</p>']);
                DB::table('plant_attributes')->where('product_id', $editedLate)->update(['sunlight' => 'Bright Light']);
            }
        };

        (new GenerateProductContentBatchJob($batchId))->handle();

        $this->assertSame([['seo_title'], ['description', 'sunlight']], $this->ai->requestedFields(), 're-checked before the call');
        $this->assertSame('मनी प्लांट', $this->plantRow($editedEarly)->hindi_name);
        $this->assertSame(FakeAi::goodValues()['seo_title'], $this->plantRow($editedEarly)->seo_title);
        $this->assertSame('<p>Hand-written copy.</p>', $this->plantRow($editedLate)->description);
        $this->assertSame('Bright Light', $this->plantRow($editedLate)->sunlight);

        $late = ProductContentJob::where('product_id', $editedLate)->sole();
        $this->assertSame(ProductContentJob::STATUS_COMPLETED, $late->status, 'nothing is missing any more');
        $this->assertSame([], $late->changes['generated']);
        $this->assertSame(0, $late->fields_generated);
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED, ProductContentBatch::find($batchId)->status);
    }

    public function test_a_plant_with_nothing_left_for_the_ai_finishes_without_a_call(): void
    {
        Queue::fake();
        $filled  = $this->seedPlant('Areca Palm', [], ['hindi_name' => null]);
        $flagged = $this->seedPlant('Mystery Succulent', [], ['hindi_name' => null, 'scientific_name' => null]);
        $batchId = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');

        // Before the worker reaches them: one plant is completed by hand, the
        // other's last gap gets flagged by a different run's review.
        DB::table('plant_attributes')->where('product_id', $filled)->update(['hindi_name' => 'सुपारी']);
        DB::table('plant_attributes')->where('product_id', $flagged)->update(['hindi_name' => 'रसीला']);
        $other = $this->makeMissingBatch([$flagged => ['scientific_name']], ['status' => ProductContentBatch::STATUS_COMPLETED]);
        ProductContentJob::where('batch_id', $other->id)->update([
            'status'  => ProductContentJob::STATUS_NEEDS_REVIEW,
            'changes' => json_encode(['review' => ['scientific_name' => 'not a full binomial']]),
        ]);

        (new GenerateProductContentBatchJob($batchId))->handle();

        $this->assertCount(0, $this->ai->plantCalls, 'no paid call when nothing is left to generate');
        $rows = ProductContentJob::where('batch_id', $batchId)->get()->keyBy('product_id');
        $this->assertSame(ProductContentJob::STATUS_SKIPPED, $rows[$filled]->status);
        $this->assertSame(0, $rows[$filled]->fields_missing);
        $this->assertSame(ProductContentJob::STATUS_NEEDS_REVIEW, $rows[$flagged]->status);
        $this->assertSame(['scientific_name'], array_keys($rows[$flagged]->changes['review']));
        $this->assertSame([], $rows[$flagged]->changes['requested']);

        $batch = ProductContentBatch::find($batchId);
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(2, (int) $batch->processed_count);
        $this->assertSame(2, (int) $batch->skipped_count);
    }

    public function test_a_provider_error_is_retried_and_a_quota_error_fails_at_once(): void
    {
        $timedOut = $this->seedPlant('Areca Palm', [], ['hindi_name' => null]);
        $quota    = $this->seedPlant('Boston Fern', [], ['hindi_name' => null]);
        $serverly = $this->seedPlant('Calathea', [], ['hindi_name' => null]);

        $this->ai->plantQueue = [
            new TransporterException(new ConnectException('cURL error 28: Operation timed out', new PsrRequest('POST', 'https://api.openai.com/v1/chat/completions'))),
            new ErrorException(['message' => 'You exceeded your current quota, please check your plan and billing details.', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']),
            new ErrorException(['message' => 'The server had an error while processing your request.', 'type' => 'server_error', 'code' => null]),
            // the two retries succeed
        ];

        $batchId = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');

        $this->assertCount(5, $this->ai->plantCalls, '3 first attempts + 2 retries, no retry for quota');
        $rows = ProductContentJob::where('batch_id', $batchId)->get()->keyBy('product_id');
        $this->assertSame(ProductContentJob::STATUS_COMPLETED, $rows[$timedOut]->status);
        $this->assertSame(1, (int) $rows[$timedOut]->attempts);
        $this->assertSame(ProductContentJob::STATUS_COMPLETED, $rows[$serverly]->status);
        $this->assertSame(ProductContentJob::STATUS_FAILED, $rows[$quota]->status);
        $this->assertSame(1, (int) $rows[$quota]->attempts, 'quota is not retried');
        $this->assertStringContainsString('exceeded your current quota', (string) $rows[$quota]->last_error);
        $this->assertNull($this->plantRow($quota)->hindi_name);
        $this->assertSame('तुलसी', $this->plantRow($timedOut)->hindi_name);

        $batch = ProductContentBatch::find($batchId);
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED_WITH_ERRORS, $batch->status);
        $this->assertSame(3, (int) $batch->processed_count);
        $this->assertSame(1, (int) $batch->failed_count);
        $summary = $batch->summary();
        $this->assertSame(2, $summary['retries']);
        $this->assertSame([['message' => 'You exceeded your current quota, please check your plan and billing details.', 'count' => 1]], $summary['errors']);
    }

    public function test_a_run_where_every_row_fails_is_failed(): void
    {
        $this->seedPlant('Areca Palm', [], ['hindi_name' => null]);
        $this->ai->plantQueue = [new ErrorException(['message' => 'Incorrect API key provided: sk-***.', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key'])];

        $batchId = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');

        $this->assertCount(1, $this->ai->plantCalls);
        $this->assertSame(ProductContentBatch::STATUS_FAILED, ProductContentBatch::find($batchId)->status);
    }

    public function test_invalid_output_saves_nothing_invalid_and_is_not_retried(): void
    {
        $id = $this->seedPlant('Tulsi', ['description' => null, 'seo_title' => null], ['hindi_name' => null, 'sunlight' => null]);
        $broken = $this->seedPlant('Rubber Plant', [], ['hindi_name' => null]);
        $this->ai->plantQueue = [
            ['fields' => ['hindi_name' => 'Tulsi', 'seo_title' => '<b>Tulsi</b> Plant Online', 'sunlight' => 'Bright Light']],
            new \UnexpectedValueException('AI reply was not valid JSON.'),
        ];

        $batchId = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');

        $this->assertCount(2, $this->ai->plantCalls, 'invalid output is never retried automatically');
        $plant = $this->plantRow($id);
        $this->assertNull($plant->hindi_name);
        $this->assertNull($plant->seo_title);
        $this->assertNull($plant->sunlight);
        $this->assertSame(FakeAi::goodValues()['description'], $plant->description, 'the valid field is kept');

        $row = ProductContentJob::where('product_id', $id)->sole();
        $this->assertSame(ProductContentJob::STATUS_FAILED, $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(['description'], $row->changes['generated']);
        $this->assertSame(['seo_title', 'hindi_name', 'sunlight'], array_keys($row->changes['invalid']));
        $this->assertStringContainsString('Invalid AI output: seo_title must be plain text', (string) $row->last_error);
        $this->assertStringContainsString('hindi_name not Devanagari', (string) $row->last_error);
        $this->assertSame(3, $row->fields_missing);
        $this->assertSame(1, $row->fields_generated);

        $brokenRow = ProductContentJob::where('product_id', $broken)->sole();
        $this->assertSame(ProductContentJob::STATUS_FAILED, $brokenRow->status);
        $this->assertSame(1, (int) $brokenRow->attempts);
        $this->assertSame('AI reply was not valid JSON.', $brokenRow->last_error);

        $this->assertSame(ProductContentBatch::STATUS_FAILED, ProductContentBatch::find($batchId)->status);
    }

    public function test_needs_review_flags_are_sticky(): void
    {
        $succulent = $this->seedPlant('Mystery Succulent', [], ['hindi_name' => null, 'scientific_name' => null]);
        $combo     = $this->seedPlant('Combo of 3 Plants', ['description' => null], ['hindi_name' => null]);
        $this->ai->plantQueue = [
            ['scientific_name_confidence' => 'medium'],
            // Not a single real plant: the model returns null for everything.
            ['fields' => ['description' => null, 'hindi_name' => null]],
        ];

        $first = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');

        $this->assertSame('तुलसी', $this->plantRow($succulent)->hindi_name);
        $this->assertNull($this->plantRow($succulent)->scientific_name);
        $row = ProductContentJob::where('product_id', $succulent)->sole();
        $this->assertSame(ProductContentJob::STATUS_NEEDS_REVIEW, $row->status);
        $this->assertSame(['scientific_name' => 'scientific name confidence medium'], $row->changes['review']);
        $comboRow = ProductContentJob::where('product_id', $combo)->sole();
        $this->assertSame(ProductContentJob::STATUS_NEEDS_REVIEW, $comboRow->status);
        $this->assertSame(['description', 'hindi_name'], array_keys($comboRow->changes['review']));
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED, ProductContentBatch::find($first)->status);

        $stats = app(MissingPlantContent::class)->stats();
        $this->assertSame(2, $stats['needs_review']);
        $this->assertSame(0, $stats['ready']);

        // Flags stick: a second run has nothing to ask the AI.
        $this->postJson('/api/ai-content-batches/missing')->assertStatus(422);
        $this->assertCount(2, $this->ai->plantCalls);

        // A human fills the species from the edit form → the plant is complete.
        DB::table('plant_attributes')->where('product_id', $succulent)->update(['scientific_name' => 'Echeveria elegans']);
        $stats = app(MissingPlantContent::class)->stats();
        $this->assertSame(1, $stats['needs_review']);
        $this->assertSame(1, $stats['complete']);
    }

    public function test_duplicate_execution_and_concurrent_starts_are_no_ops_or_409(): void
    {
        Queue::fake();
        $a = $this->seedPlant('Areca Palm', [], ['hindi_name' => null]);
        $this->seedPlant('Boston Fern', [], ['hindi_name' => null]);

        $batchId = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');
        $this->postJson('/api/ai-content-batches/missing')
            ->assertStatus(409)
            ->assertJson(['message' => 'A missing-content run is already in progress.', 'batch_id' => $batchId]);

        $lock = Cache::lock('ai-content:missing-run', 120);
        $this->assertTrue($lock->get());
        $this->postJson('/api/ai-content-batches/missing')->assertStatus(409)->assertJson(['message' => 'A missing-content run is being started.']);
        $lock->release();

        // The same batch executed twice (sweeper + original): the second is a no-op.
        (new GenerateProductContentBatchJob($batchId))->handle();
        (new GenerateProductContentBatchJob($batchId))->handle();
        $this->assertCount(2, $this->ai->plantCalls);
        $batch = ProductContentBatch::find($batchId);
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(2, (int) $batch->processed_count);
        $this->assertSame(2, (int) $batch->updated_count);

        // A row another execution finished while this one waited on the AI:
        // completion is guarded on `processing`, so nothing is counted twice
        // and the other execution's value stands.
        $c       = $this->seedPlant('Calathea', [], ['hindi_name' => null]);
        $batchId = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');
        $this->ai->duringPlantCall = function () use ($batchId, $c) {
            DB::table('plant_attributes')->where('product_id', $c)->update(['hindi_name' => 'कलैथिया']);
            ProductContentJob::where('batch_id', $batchId)->update(['status' => ProductContentJob::STATUS_COMPLETED]);
        };
        (new GenerateProductContentBatchJob($batchId))->handle();

        $this->assertSame('कलैथिया', $this->plantRow($c)->hindi_name);
        $batch = ProductContentBatch::find($batchId);
        $this->assertSame(0, (int) $batch->processed_count, 'the other execution owns the count');
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame('तुलसी', $this->plantRow($a)->hindi_name);
    }

    public function test_counters_retries_tokens_and_cost_add_up(): void
    {
        $everything = $this->seedPlant('Tulsi', ['description' => null, 'seo_title' => null, 'seo_description' => null], null);
        $this->seedPlant('Snake Plant', [], ['hindi_name' => null]);
        $this->seedPlant('Peace Lily', ['seo_description' => null], []);
        $this->ai->latencyMs  = 3;
        $this->ai->plantQueue = [
            new ErrorException(['message' => 'Rate limit reached for gpt-4o-mini on requests per min (RPM).', 'type' => 'requests', 'code' => 'rate_limit_exceeded']),
            ['usage' => ['prompt_tokens' => 300, 'completion_tokens' => 50]],
            ['usage' => ['prompt_tokens' => 200, 'completion_tokens' => 60]],
            ['usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 400]], // Tulsi's retry
        ];

        $batchId = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');
        // Backdate the run so throughput is measured over a real window.
        ProductContentBatch::where('id', $batchId)->update(['created_at' => now()->subMinute()]);

        $response = $this->getJson("/api/ai-content-batches/{$batchId}")->assertOk();
        $summary  = $response->json('summary');

        $this->assertSame('completed', $response->json('status'));
        $this->assertSame(3, $response->json('processed_count'));
        $this->assertSame(3, $response->json('updated_count'));
        $this->assertSame(100, $response->json('progress'));
        $this->assertSame([
            'total' => 3, 'pending' => 0, 'retrying' => 0, 'processing' => 0, 'completed' => 3,
            'needs_review' => 0, 'failed' => 0, 'skipped' => 0, 'processed' => 3, 'progress' => 100,
            'fields_initially_missing' => 10, 'fields_generated' => 10, 'fields_still_missing' => 0, 'retries' => 1,
        ], array_slice($summary, 0, 14));
        $this->assertSame(['prompt' => 1500, 'completion' => 510], $summary['tokens']);
        $this->assertEqualsWithDelta((1500 * 0.15 + 510 * 0.60) / 1_000_000, $summary['cost_usd'], 1e-9);
        $this->assertGreaterThanOrEqual(3, $summary['avg_ai_ms']);
        $this->assertEqualsWithDelta(3.0, $summary['rows_per_minute'], 0.2);
        $this->assertNull($summary['eta_seconds'], 'no ETA once finished');
        $this->assertNotNull($summary['last_activity_at']);
        $this->assertSame([], $summary['errors']);

        $tulsi = ProductContentJob::where('product_id', $everything)->sole();
        $this->assertSame(8, $tulsi->fields_generated);
        $this->assertSame(1000, $tulsi->prompt_tokens);
        $this->assertSame(1, (int) $tulsi->attempts);
    }

    public function test_retry_failed_touches_failed_plants_only(): void
    {
        $ok       = $this->seedPlant('Areca Palm', [], ['hindi_name' => null]);
        $invalid  = $this->seedPlant('Boston Fern', ['seo_title' => null], ['hindi_name' => null]);
        $declined = $this->seedPlant('Mystery Succulent', [], ['scientific_name' => null]);
        $this->ai->plantQueue = [
            [],
            ['fields' => ['hindi_name' => 'Boston Fern']],
            ['scientific_name_confidence' => 'low'],
        ];

        $batchId = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED_WITH_ERRORS, ProductContentBatch::find($batchId)->status);
        $this->assertSame(FakeAi::goodValues()['seo_title'], $this->plantRow($invalid)->seo_title, 'valid half kept');

        // Failed + needs-review rows (with slugs for edit links); unknown statuses are ignored.
        $listed = $this->getJson("/api/ai-content-batches/{$batchId}/rows?status=failed,needs_review,bogus")->assertOk()->json('data');
        $this->assertSame([$invalid, $declined], array_map(fn ($r) => (int) $r['product_id'], $listed));
        $this->assertSame(DB::table('products')->where('id', $invalid)->value('slug'), $listed[0]['product_slug']);
        $this->assertSame(['hindi_name' => 'not Devanagari'], $listed[0]['changes']['invalid']);
        $this->assertCount(3, $this->getJson("/api/ai-content-batches/{$batchId}/rows?status=bogus")->json('data'));

        // Another missing-content run is active → 409 with its id.
        $other = $this->makeMissingBatch([$ok => ['hindi_name']]);
        $this->postJson("/api/ai-content-batches/{$batchId}/retry-failed")->assertStatus(409)->assertJson(['batch_id' => $other->id]);
        $other->update(['status' => ProductContentBatch::STATUS_CANCELLED]);

        Queue::fake();
        $okBefore = ProductContentJob::where('product_id', $ok)->where('batch_id', $batchId)->sole();
        $this->postJson("/api/ai-content-batches/{$batchId}/retry-failed")->assertOk();
        $batch = ProductContentBatch::find($batchId);
        $this->assertSame(2, (int) $batch->processed_count, 'total minus open rows — needs_review counts as processed');
        $this->assertSame(0, (int) $batch->failed_count);

        (new GenerateProductContentBatchJob($batchId))->handle();

        $this->assertSame([['hindi_name']], array_slice($this->ai->requestedFields(), 3), 'only the still-missing field of the failed plant');
        $this->assertSame('तुलसी', $this->plantRow($invalid)->hindi_name);
        $rows = ProductContentJob::where('batch_id', $batchId)->get()->keyBy('product_id');
        $this->assertSame(ProductContentJob::STATUS_COMPLETED, $rows[$invalid]->status);
        $this->assertSame(['seo_title', 'hindi_name'], $rows[$invalid]->changes['generated']);
        $this->assertSame(2, $rows[$invalid]->fields_generated);
        $this->assertSame(ProductContentJob::STATUS_NEEDS_REVIEW, $rows[$declined]->status);
        $this->assertEquals($okBefore->updated_at, $rows[$ok]->updated_at, 'completed rows untouched');
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED, ProductContentBatch::find($batchId)->status);
    }

    public function test_a_stalled_row_is_recovered_by_the_sweeper(): void
    {
        $id    = $this->seedPlant('Areca Palm', [], ['hindi_name' => null]);
        $batch = $this->makeMissingBatch([$id => ['hindi_name']], [
            'status'             => ProductContentBatch::STATUS_PROCESSING,
            'last_dispatched_at' => now()->subMinutes(30),
        ]);
        // Its worker died mid-row half an hour ago.
        ProductContentJob::where('batch_id', $batch->id)->update([
            'status'     => ProductContentJob::STATUS_PROCESSING,
            'claimed_at' => now()->subMinutes(30),
        ]);

        $this->artisan(SweepContentBatchesCommand::class)->assertSuccessful();

        $this->assertSame('तुलसी', $this->plantRow($id)->hindi_name);
        $this->assertSame(ProductContentJob::STATUS_COMPLETED, ProductContentJob::where('batch_id', $batch->id)->value('status'));
        $batch->refresh();
        $this->assertSame(ProductContentBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, (int) $batch->processed_count);
        $this->assertCount(1, $this->ai->plantCalls);
    }
    public function test_a_field_the_ai_gets_wrong_twice_goes_to_review_and_stays_there(): void
    {
        $id = $this->seedPlant('Tulsi', [], ['hindi_name' => null]);
        $this->ai->plantQueue = [
            ['fields' => ['hindi_name' => 'Tulsi']], // Roman script: invalid
            ['fields' => ['hindi_name' => 'Tulsi']], // the same mistake on "Retry failed"
        ];

        $batchId = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');
        $row     = ProductContentJob::where('batch_id', $batchId)->sole();
        $this->assertSame(ProductContentJob::STATUS_FAILED, $row->status, 'a first invalid answer is a failure');

        $this->postJson("/api/ai-content-batches/{$batchId}/retry-failed")->assertOk();

        $row->refresh();
        $this->assertSame(ProductContentJob::STATUS_NEEDS_REVIEW, $row->status);
        $this->assertStringContainsString('failed validation twice', $row->changes['review']['hindi_name']);
        $this->assertNull($this->plantRow($id)->hindi_name);

        // Sticky: no further run pays to ask again.
        $this->postJson('/api/ai-content-batches/missing')->assertStatus(422);
        $this->assertCount(2, $this->ai->plantCalls);
        $this->assertSame(1, app(MissingPlantContent::class)->stats()['needs_review']);
    }

    public function test_a_rate_limit_is_retried_even_when_its_message_mentions_billing(): void
    {
        $limited = $this->seedPlant('Areca Palm', [], ['hindi_name' => null]);
        $capped  = $this->seedPlant('Boston Fern', [], ['hindi_name' => null]);
        $this->ai->plantQueue = [
            new ErrorException([
                'message' => 'Rate limit reached for gpt-4o-mini on requests per min (RPM): Limit 3. You can increase your rate limit by adding a payment method to your account at https://platform.openai.com/account/billing.',
                'type'    => 'requests',
                'code'    => 'rate_limit_exceeded',
            ]),
            // An older payload with no code: a hard billing cap is permanent.
            new ErrorException(['message' => 'Billing hard limit has been reached.', 'type' => 'invalid_request_error', 'code' => null]),
        ];

        $batchId = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');

        $rows = ProductContentJob::where('batch_id', $batchId)->get()->keyBy('product_id');
        $this->assertSame(ProductContentJob::STATUS_COMPLETED, $rows[$limited]->status);
        $this->assertSame(1, (int) $rows[$limited]->attempts);
        $this->assertSame(ProductContentJob::STATUS_FAILED, $rows[$capped]->status);
        $this->assertSame(1, (int) $rows[$capped]->attempts, 'a billing cap is not retried');
        $this->assertCount(3, $this->ai->plantCalls);
    }

    public function test_an_unusable_reply_still_counts_its_tokens(): void
    {
        $this->seedPlant('Areca Palm', [], ['hindi_name' => null]);
        $this->ai->plantQueue = [new InvalidAiOutput('AI reply was cut off (finish_reason=length).', ['prompt_tokens' => 300, 'completion_tokens' => 1200])];

        $batchId = $this->postJson('/api/ai-content-batches/missing')->assertCreated()->json('id');

        $row = ProductContentJob::where('batch_id', $batchId)->sole();
        $this->assertSame(ProductContentJob::STATUS_FAILED, $row->status);
        $this->assertSame(300, (int) $row->prompt_tokens);
        $this->assertSame(1200, (int) $row->completion_tokens);
        $summary = ProductContentBatch::find($batchId)->summary();
        $this->assertSame(['prompt' => 300, 'completion' => 1200], $summary['tokens']);
        $this->assertGreaterThan(0, $summary['cost_usd']);
    }

    public function test_the_sweeper_finalizes_an_all_failed_missing_run_as_failed(): void
    {
        $id    = $this->seedPlant('Areca Palm', [], ['hindi_name' => null]);
        $batch = $this->makeMissingBatch([$id => ['hindi_name']], [
            'status'             => ProductContentBatch::STATUS_PROCESSING,
            'last_dispatched_at' => now()->subMinutes(30),
        ]);
        ProductContentJob::where('batch_id', $batch->id)->update(['status' => ProductContentJob::STATUS_FAILED, 'last_error' => 'quota']);

        $this->artisan(SweepContentBatchesCommand::class)->assertSuccessful();

        $this->assertSame(ProductContentBatch::STATUS_FAILED, $batch->fresh()->status);
        $this->assertCount(0, $this->ai->plantCalls);
    }

    public function test_a_hinglish_hindi_name_becomes_devanagari_and_stays_searchable(): void
    {
        // Seeded like the old catalogue: the "Hindi name" is Hinglish, there is no Hinglish field.
        $id = $this->seedPlant('Snake Plant', [], ['hindi_name' => 'Nag Paudha', 'hinglish_name' => null]);
        $this->ai->plantQueue = [['fields' => ['hindi_name' => 'नाग पौधा', 'hinglish_name' => 'Nag Paudha']]];

        $this->postJson('/api/ai-content-batches/missing')->assertCreated();

        $this->assertSame([['hindi_name', 'hinglish_name']], $this->ai->requestedFields());
        $this->assertSame('Nag Paudha', $this->ai->plantCalls[0]->context['old_hindi_name'], 'the old name is the hint');
        $plant = $this->plantRow($id);
        $this->assertSame('नाग पौधा', $plant->hindi_name);
        $this->assertSame('Nag Paudha', $plant->hinglish_name);
        $this->assertSame(['hi' => 'नाग पौधा'], json_decode($plant->regional_names, true));
        $this->assertSame(['Nag Paudha'], json_decode($plant->common_names, true), 'still found by "nag paudha"');
        $this->assertSame([], app(MissingPlantContent::class)->missingFields(app(MissingPlantContent::class)->find($id)));
    }

    public function test_a_hindi_name_that_repeats_the_product_name_is_not_sent_as_a_hint(): void
    {
        // 265 seeded plants carry their English name as the "Hindi name"; as a hint it made the AI decline.
        $this->seedPlant('Song of India', [], ['hindi_name' => ' song  of india', 'hinglish_name' => null]);
        $this->ai->plantQueue = [['fields' => ['hindi_name' => 'सॉन्ग ऑफ इंडिया', 'hinglish_name' => 'Song of India']]];

        $this->postJson('/api/ai-content-batches/missing')->assertCreated();

        $this->assertSame([['hindi_name', 'hinglish_name']], $this->ai->requestedFields());
        $this->assertArrayNotHasKey('old_hindi_name', $this->ai->plantCalls[0]->context);
    }
}
