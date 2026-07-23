<?php

namespace Marvel\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Marvel\Database\Models\ImageGenerationJob;

/**
 * Parses the batch AI image-generation sheet (.xlsx/.xls/.csv) into
 * image_generation_jobs rows.
 *
 * Expected headings (case/space-insensitive via WithHeadingRow):
 *   plant_code   (required — folder/file prefix, e.g. PLT001)
 *   plant_name   (required — human name, e.g. Areca Palm)
 *   prompt       (required — the generation prompt)
 *
 * Robustness mirrors VendorPriceSheetImport: chunk-read so a 2000-row sheet
 * can't exhaust memory; each row is wrapped so one malformed row is recorded
 * (with its absolute line number) instead of aborting the import. Exceeding
 * max_rows throws RowLimitExceededException so the controller can reject the
 * whole upload cleanly.
 */
class ImageBatchSheetImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    public int $rowCount = 0;      // valid rows imported
    public int $errorCount = 0;
    public array $errors = [];
    /** Running count of data rows seen across chunks, so line numbers stay absolute. */
    private int $rowOffset = 0;
    /** folder_name → first line that used it (duplicate plant_code handling). */
    private array $seenFolders = [];

    public function __construct(
        private int $batchId,
        private int $imagesPerPlant,
        private int $maxRows,
    ) {
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function collection(Collection $rows): void
    {
        $base = $this->rowOffset;

        foreach ($rows as $i => $row) {
            $line = $base + $i + 2; // +1 heading, +1 to 1-index
            try {
                if ($this->importRow($row, $line)) {
                    $this->rowCount++;
                }
            } catch (RowLimitExceededException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->fail($line, 'Could not import this row: ' . $e->getMessage());
            }
        }

        $this->rowOffset += $rows->count();
    }

    private function importRow(Collection $row, int $line): bool
    {
        $code   = $this->str($row, 'plant_code');
        $name   = $this->str($row, 'plant_name');
        $prompt = $this->str($row, 'prompt');

        // A fully blank row (trailing spreadsheet noise) is skipped silently.
        if ($code === '' && $name === '' && $prompt === '') {
            return false;
        }

        if ($code === '') {
            $this->fail($line, 'Missing plant_code.');
            return false;
        }
        if ($name === '') {
            $this->fail($line, 'Missing plant_name.');
            return false;
        }
        if ($prompt === '') {
            $this->fail($line, 'Missing prompt.');
            return false;
        }
        if (mb_strlen($prompt) > 3000) {
            $this->fail($line, 'Prompt longer than 3000 characters.');
            return false;
        }

        if ($this->rowCount >= $this->maxRows) {
            throw new RowLimitExceededException(
                "Sheet exceeds the maximum of {$this->maxRows} plants per batch."
            );
        }

        // Clean folder/file prefix: PLT001_Areca_Palm. Duplicate plant_codes
        // get a _r{line} suffix so their files can never overwrite each other.
        $folder = $this->folderName($code, $name);
        if (isset($this->seenFolders[$folder])) {
            $folder .= '_r' . $line;
        }
        $this->seenFolders[$folder] = $line;

        ImageGenerationJob::create([
            'batch_id'         => $this->batchId,
            'row_number'       => $line,
            'plant_code'       => mb_substr($code, 0, 64),
            'plant_name'       => mb_substr($name, 0, 191),
            'prompt'           => $prompt,
            'folder_name'      => $folder,
            'status'           => ImageGenerationJob::STATUS_PENDING,
            'images_requested' => $this->imagesPerPlant,
        ]);

        return true;
    }

    /** 'PLT001' + 'Areca Palm' → 'PLT001_Areca_Palm' (filesystem/zip/s3-safe). */
    private function folderName(string $code, string $name): string
    {
        $clean = function (string $v): string {
            $v = preg_replace('/\s+/', '_', trim($v));
            $v = preg_replace('/[^A-Za-z0-9_\-]/', '', $v);
            $v = preg_replace('/_+/', '_', (string) $v);

            return trim((string) $v, '_');
        };

        $folder = $clean($code) . '_' . $clean($name);

        return mb_substr(trim($folder, '_'), 0, 150) ?: 'plant';
    }

    private function fail(int $line, string $msg): void
    {
        $this->errorCount++;
        if (count($this->errors) < 200) {
            $this->errors[] = ['line' => $line, 'error' => $msg];
        }
    }

    private function str(Collection $row, string $key): string
    {
        $v = $row->get($key);

        return $v === null ? '' : trim((string) $v);
    }
}
