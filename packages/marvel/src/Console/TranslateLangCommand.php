<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Traits\AiTranslationTrait;

/**
 * Generate Laravel localization files (emails / SMS / messages) for the Indian languages by
 * AI-translating resources/lang/en/*.php. Idempotent (skips existing files unless --force).
 *
 *   php artisan marvel:translate-lang --langs=hi,bn,ta
 */
class TranslateLangCommand extends Command
{
    use AiTranslationTrait;

    protected $signature = 'marvel:translate-lang
        {--langs= : Comma-separated ISO codes (default: all 22 Indian languages)}
        {--files= : Comma-separated lang files without extension (default: all in en/)}
        {--model=claude-sonnet-4-6 : Claude model}
        {--force : Overwrite existing translated files}';

    protected $description = 'AI-translate resources/lang/en into the Indian languages';

    private const LANG_NAMES = [
        'hi' => 'Hindi', 'bn' => 'Bengali', 'ta' => 'Tamil', 'te' => 'Telugu', 'mr' => 'Marathi',
        'gu' => 'Gujarati', 'kn' => 'Kannada', 'ml' => 'Malayalam', 'pa' => 'Punjabi', 'or' => 'Odia',
        'as' => 'Assamese', 'ur' => 'Urdu', 'sa' => 'Sanskrit', 'ne' => 'Nepali', 'kok' => 'Konkani',
        'mai' => 'Maithili', 'doi' => 'Dogri', 'ks' => 'Kashmiri', 'sd' => 'Sindhi', 'sat' => 'Santali',
        'mni' => 'Manipuri (Meitei)', 'brx' => 'Bodo',
    ];

    public function handle(): int
    {
        $base = lang_path('en');
        if (!is_dir($base)) {
            $this->error("Source locale not found: {$base}");
            return self::FAILURE;
        }

        $langs = $this->option('langs')
            ? array_map('trim', explode(',', $this->option('langs')))
            : array_keys(self::LANG_NAMES);

        $files = $this->option('files')
            ? array_map('trim', explode(',', $this->option('files')))
            : array_map(fn ($p) => pathinfo($p, PATHINFO_FILENAME), glob($base . '/*.php'));

        $model = (string) $this->option('model');
        $force = (bool) $this->option('force');

        foreach ($langs as $lang) {
            $langName = self::LANG_NAMES[$lang] ?? $lang;
            $this->info("\n[{$lang}] {$langName}");
            foreach ($files as $file) {
                $src = $base . '/' . $file . '.php';
                if (!is_file($src)) {
                    continue;
                }
                $outDir = lang_path($lang);
                $outPath = $outDir . '/' . $file . '.php';
                if (is_file($outPath) && !$force) {
                    $this->line("  skip {$lang}/{$file}.php (exists)");
                    continue;
                }

                $sourceArr = require $src;
                if (!is_array($sourceArr)) {
                    continue;
                }
                $flat = $this->flattenMap($sourceArr);
                if (empty($flat)) {
                    continue;
                }

                try {
                    $translatedFlat = $this->aiTranslateMap($flat, $langName, $model);
                } catch (\Throwable $e) {
                    $this->error("  {$file}: " . $e->getMessage());
                    continue;
                }
                // Keep any keys the model dropped by falling back to English.
                $merged = array_merge($flat, $translatedFlat);
                $nested = $this->unflattenMap($merged);

                if (!is_dir($outDir)) {
                    mkdir($outDir, 0775, true);
                }
                $php = "<?php\n\nreturn " . var_export($nested, true) . ";\n";
                file_put_contents($outPath, $php);
                $this->line("  wrote {$lang}/{$file}.php (" . count($flat) . ' keys)');
            }
        }

        $this->info("\nDone. Have native speakers review machine translations before production.");
        return self::SUCCESS;
    }
}
