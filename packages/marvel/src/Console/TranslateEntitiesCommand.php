<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Jobs\TranslateEntityJob;
use Marvel\Translation\TranslationService;

/**
 * Warm / populate the translation cache for dynamic content (products,
 * categories) into the configured languages via the overlay engine.
 *
 *   php artisan marvel:translate-entities                 # queue all, all langs
 *   php artisan marvel:translate-entities --type=product --langs=hi,ta --limit=50
 *   php artisan marvel:translate-entities --sync          # run inline (no worker)
 *
 * Idempotent (the job skips up-to-date rows). Run this before enabling a language
 * on the storefront to avoid a cold-cache stampede.
 */
class TranslateEntitiesCommand extends Command
{
    protected $signature = 'marvel:translate-entities
        {--type=both : product | category | both}
        {--langs= : comma list; defaults to all configured non-default languages}
        {--limit=0 : cap entities per type (0 = all)}
        {--sync : translate inline instead of dispatching to the queue}';

    protected $description = 'Populate translation cache for products/categories into the configured languages.';

    public function handle(TranslationService $service): int
    {
        $default = config('translation.default_language', 'en');
        $langsOpt = $this->option('langs');
        $languages = $langsOpt
            ? array_map('trim', explode(',', $langsOpt))
            : array_values(array_filter(config('translation.languages', []), fn($l) => $l !== $default));
        $languages = array_values(array_intersect($languages, config('translation.languages', [])));

        if (empty($languages)) {
            $this->error('No target languages.');
            return self::FAILURE;
        }

        $types = match ($this->option('type')) {
            'product' => ['product' => Product::class],
            'category' => ['category' => Category::class],
            default => ['product' => Product::class, 'category' => Category::class],
        };
        $limit = (int) $this->option('limit');
        $sync = (bool) $this->option('sync');
        $queue = config('translation.queue', 'translations');

        $dispatched = 0;
        foreach ($types as $label => $model) {
            $query = $model::where('language', $default)->select('id');
            if ($limit > 0) $query->limit($limit);
            $ids = $query->pluck('id');
            $this->info(sprintf('%s: %d entities × %d languages', $label, $ids->count(), count($languages)));

            $bar = $this->output->createProgressBar($ids->count() * count($languages));
            foreach ($ids as $id) {
                foreach ($languages as $lang) {
                    if ($sync) {
                        (new TranslateEntityJob($model, (int) $id, $lang))->handle($service);
                    } else {
                        TranslateEntityJob::dispatch($model, (int) $id, $lang)->onQueue($queue);
                    }
                    $dispatched++;
                    $bar->advance();
                }
            }
            $bar->finish();
            $this->newLine();
        }

        $this->info(($sync ? 'Translated' : 'Queued') . " {$dispatched} entity-language jobs.");
        return self::SUCCESS;
    }
}
