<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;
use Marvel\Services\PlantImageFetcher;

/**
 * Fetches plant images in bulk and writes a coverage report.
 *
 *   php artisan plantathome:fetch-plant-images --missing
 *   php artisan plantathome:fetch-plant-images --limit=50 --target=5
 */
class FetchPlantImagesCommand extends Command
{
    protected $signature = 'plantathome:fetch-plant-images
        {--missing : only plants that have fewer than target images}
        {--limit= : cap how many plants to process}
        {--target=5 : images wanted per plant}';

    protected $description = 'Fetch plant images (iNaturalist/Wikimedia/Pixabay) to S3 and write a coverage report.';

    public function handle(): int
    {
        $target = (int) $this->option('target') ?: 5;
        $fetcher = new PlantImageFetcher($target);

        $type = Type::where('slug', 'plants')->where('language', 'en')->first();
        if (!$type) {
            $this->error('Plants type not found.');
            return self::FAILURE;
        }

        $query = Product::where('type_id', $type->id)->withCount('images');
        if ($this->option('missing')) {
            $query->having('images_count', '<', $target);
        }
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $total = (clone $query)->count();
        $this->info("Processing {$total} plants (target {$target} images each)...");

        $done = 0;
        $query->orderBy('id')->chunk(50, function ($plants) use ($fetcher, $target, &$done, $total) {
            foreach ($plants as $p) {
                try {
                    $imgs = $fetcher->fetchFor($p);
                    $done++;
                    if ($done % 25 === 0) {
                        $this->info("  ... {$done}/{$total} processed");
                    }
                } catch (\Throwable $e) {
                    $this->warn("  {$p->slug}: " . $e->getMessage());
                }
                usleep(300000); // 0.3s — be polite to the source APIs
            }
        });

        $report = $this->writeCoverageReport($type->id, $target);
        $this->info("Done. Coverage report: {$report}");
        return self::SUCCESS;
    }

    /** Build the coverage CSV for ALL plants and upload to S3. Returns the public URL. */
    private function writeCoverageReport(int $typeId, int $target): string
    {
        $rows = [['slug', 'name', 'scientific_name', 'image_count', 'status']];
        $summary = ['complete' => 0, 'partial' => 0, 'missing' => 0];

        Product::where('type_id', $typeId)
            ->withCount('images')
            ->with('plantAttribute:id,product_id,scientific_name')
            ->orderBy('slug')
            ->chunk(200, function ($plants) use (&$rows, &$summary, $target) {
                foreach ($plants as $p) {
                    $n = (int) $p->images_count;
                    $status = $n >= $target ? 'complete' : ($n > 0 ? 'partial' : 'missing');
                    $summary[$status]++;
                    $rows[] = [$p->slug, $p->name, optional($p->plantAttribute)->scientific_name, $n, $status];
                }
            });

        $csv = '';
        foreach ($rows as $r) {
            $csv .= implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $r)) . "\n";
        }

        $ts = now()->format('Ymd-His');
        $key = "reports/plant-image-coverage-{$ts}.csv";
        try {
            Storage::disk('s3')->put($key, $csv);
            Storage::disk('s3')->put('reports/plant-image-coverage-latest.csv', $csv);
        } catch (\Throwable $e) {
            $this->warn('Could not upload report to S3: ' . $e->getMessage());
        }
        // also keep a local copy
        Storage::disk('local')->put("reports/plant-image-coverage-{$ts}.csv", $csv);

        $this->line("  coverage — complete: {$summary['complete']}, partial: {$summary['partial']}, missing: {$summary['missing']}");

        $bucket = env('AWS_BUCKET', 'plantathome-media-prod');
        $region = env('AWS_DEFAULT_REGION', 'ap-south-1');
        return "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}";
    }
}
