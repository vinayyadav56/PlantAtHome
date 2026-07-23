<?php

namespace Tests\Feature\ImageBatches;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\ImageBatch;
use Marvel\Database\Models\ImageGenerationJob;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Base for batch AI image-generation tests: in-memory sqlite with the real
 * feature migrations, sync queue, faked storage/mail, and OpenAI faked at the
 * HTTP layer (the client is plain Laravel Http). Route middleware (sanctum +
 * spatie permission) is dropped — the permission stack is framework-tested;
 * these tests cover the feature's own logic.
 */
abstract class ImageBatchTestCase extends TestCase
{
    public const PNG_BYTES = "\x89PNG-fake-image-bytes";

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
            'queue.default'               => 'sync',
            'shop.openai.secret_Key'      => 'test-openai-key',
            // Fast tests: no pacing sleeps, no retry cooldown.
            'image-batches.rate_per_minute'        => 1_000_000,
            'image-batches.retry_cooldown_seconds' => 0,
            'image-batches.row_max_attempts'       => 3,
            'image-batches.max_rows'               => 2000,
            'image-batches.max_estimated_cost'     => 200,
        ]);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->timestamps();
        });

        foreach ([
            '2026_07_23_100000_create_image_batches_table.php',
            '2026_07_23_100010_create_image_generation_jobs_table.php',
            '2026_07_23_100020_create_image_generation_results_table.php',
        ] as $migration) {
            (require base_path('packages/marvel/database/migrations/' . $migration))->up();
        }

        Storage::fake('s3');
        Storage::fake('local');
        Mail::fake();

        // Laravel-Excel spools uploads into a temp dir that a fresh CI clone
        // doesn't have (storage/framework/cache is gitignored and the
        // library's mkdir is not recursive) — point it at a real tmp dir.
        $excelTmp = sys_get_temp_dir() . '/laravel-excel-tests';
        if (!is_dir($excelTmp)) {
            mkdir($excelTmp, 0777, true);
        }
        config(['excel.temporary_files.local_path' => $excelTmp]);

        $this->withoutMiddleware();
    }

    protected function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@plantathome.test']);
        $this->actingAs($user);

        return $user;
    }

    /** CSV sheet upload (maatwebsite reads csv by extension). */
    protected function sheet(array $rows, array $headings = ['plant_code', 'plant_name', 'prompt']): UploadedFile
    {
        $lines = [implode(',', $headings)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $row));
        }

        return UploadedFile::fake()->createWithContent('plants.csv', implode("\n", $lines));
    }

    /** Every OpenAI image call succeeds, except prompts containing FAILME. */
    protected function fakeOpenAi(): void
    {
        Http::fake([
            'api.openai.com/*' => function ($request) {
                $body = (string) $request->body();
                if (str_contains($body, 'FAILME')) {
                    return Http::response(['error' => ['message' => 'Prompt rejected by fake.']], 400);
                }

                return Http::response([
                    'data' => [['b64_json' => base64_encode(self::PNG_BYTES), 'revised_prompt' => null]],
                ], 200);
            },
        ]);
    }

    protected function makeBatch(array $rows, array $overrides = [], int $imagesPerPlant = 2): ImageBatch
    {
        $batch = ImageBatch::create(array_merge([
            'uploaded_by'      => null,
            'notify_email'     => 'admin@plantathome.test',
            'settings'         => [
                'model'      => 'gpt-image-1',
                'size'       => '1024x1024',
                'quality'    => 'low',
                'style'      => 'natural',
                'background' => 'auto',
            ],
            'images_per_plant' => $imagesPerPlant,
            'status'           => ImageBatch::STATUS_PENDING,
            'valid_rows'       => count($rows),
            'total_rows'       => count($rows),
            'total_images'     => count($rows) * $imagesPerPlant,
        ], $overrides));

        foreach (array_values($rows) as $i => [$code, $name, $prompt]) {
            ImageGenerationJob::create([
                'batch_id'         => $batch->id,
                'row_number'       => $i + 2,
                'plant_code'       => $code,
                'plant_name'       => $name,
                'prompt'           => $prompt,
                'folder_name'      => $code . '_' . str_replace(' ', '_', $name),
                'status'           => ImageGenerationJob::STATUS_PENDING,
                'images_requested' => $imagesPerPlant,
            ]);
        }

        return $batch->fresh();
    }
}
