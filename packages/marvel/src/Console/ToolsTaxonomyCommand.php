<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Services\ToolsTaxonomyImporter;

/**
 * Imports the Gardening Tools category taxonomy (packages/marvel/data/tools-taxonomy.json)
 * into the Tools vertical. Create-only and idempotent: safe on every staging boot and as the
 * production data op. See ToolsTaxonomyImporter for what it does and does not touch.
 *
 *   php artisan plantathome:tools-taxonomy              import (prints what changed)
 *   php artisan plantathome:tools-taxonomy --dry-run    same, rolled back: shows what WOULD change
 *   php artisan plantathome:tools-taxonomy --relabel    rename to the data file's names (rows no admin edited)
 *   php artisan plantathome:tools-taxonomy --rollback   undo everything the import recorded
 */
class ToolsTaxonomyCommand extends Command
{
    protected $signature = 'plantathome:tools-taxonomy
        {--dry-run : Show what would change and write nothing}
        {--relabel : Rename categories to the names in the data file (only ones no admin has edited)}
        {--rollback : Undo every change the import recorded}';

    protected $description = 'Import the Gardening Tools category taxonomy (create-only, idempotent).';

    public function handle(ToolsTaxonomyImporter $importer): int
    {
        $dry = (bool) $this->option('dry-run');
        try {
            if ($this->option('rollback')) {
                $report = $importer->rollback();
            } elseif ($this->option('relabel')) {
                $report = $importer->relabel($dry);
            } else {
                $report = $importer->run($dry);
            }
        } catch (\Throwable $e) {
            $this->error('tools-taxonomy: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dry ? '[dry run — nothing written] ' : '') . 'Gardening tools taxonomy:');
        foreach ($report as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $line) {
                    $this->line("  {$key}: {$line}");
                }
            } else {
                $this->line("  {$key}: {$value}");
            }
        }

        return self::SUCCESS;
    }
}
