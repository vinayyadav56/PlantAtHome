<?php

namespace App\Modules\Serviceability\Console;

use App\Modules\Serviceability\Application\PostalCodeImporter;
use Illuminate\Console\Command;

/**
 * Imports/refreshes the pincode master. Idempotent (upsert keyed on
 * country_id + pincode); --fresh wipes India's rows first. Defaults to the
 * bundled GeoNames-derived dataset.
 */
class ImportPostalCodesCommand extends Command
{
    protected $signature = 'plantathome:pincodes-import
        {path? : CSV or .csv.gz to import (defaults to the bundled dataset)}
        {--fresh : Delete existing postal codes before importing}';

    protected $description = 'Import the India pincode master into postal_codes';

    public function handle(PostalCodeImporter $importer): int
    {
        try {
            $report = $importer->import($this->argument('path'), (bool) $this->option('fresh'));
        } catch (\RuntimeException $e) {
            $this->error('Import aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'pincodes-import: %d row(s) processed, %d unique pincode(s), %d district(s) touched, %d skipped.',
            $report['pincodes'],
            $report['unique_pincodes'],
            $report['districts'],
            $report['skipped'],
        ));

        return self::SUCCESS;
    }
}
