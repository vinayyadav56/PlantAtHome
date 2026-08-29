<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Marvel\Database\Models\Product;
use Marvel\Services\Media\MediaService;
use Marvel\Services\Media\MediaUrlService;

/**
 * Bulk-import locally processed product photos through the media system.
 *
 * Manifest (JSON array): [{folder, code, name, files: [abs paths, main first],
 * products: [{id, name}...]}]. A folder with no products still gets its items
 * uploaded + published (library-only) so another environment can reference the
 * URLs. Run with QUEUE_CONNECTION=sync so variant generation happens inline.
 *
 * Progress is appended per folder to --out (JSONL); on re-run, folders already
 * in that file are skipped, which is what makes the command resumable.
 */
class ImportProcessedImagesCommand extends Command
{
    protected $signature = 'plantathome:import-processed-images
        {--manifest= : JSON manifest path}
        {--out= : JSONL progress/result path}
        {--user-id=1 : acting user id for publish}
        {--limit=0 : stop after N folders (0 = all)}
        {--dry-run : match + count only, no writes}';

    protected $description = 'Import processed plant photos via MediaService and attach them to products';

    public function handle(MediaService $media, MediaUrlService $urls): int
    {
        $manifestPath = (string) $this->option('manifest');
        $outPath = (string) $this->option('out') ?: ($manifestPath . '.out.jsonl');
        $userId = (int) $this->option('user-id');
        $limit = (int) $this->option('limit');

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            $this->error("Unreadable manifest: {$manifestPath}");
            return self::FAILURE;
        }

        $done = [];
        if (is_file($outPath)) {
            foreach (file($outPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $row = json_decode($line, true);
                if (!empty($row['folder']) && empty($row['error'])) {
                    $done[$row['folder']] = true;
                }
            }
        }

        $todo = array_values(array_filter($manifest, fn ($m) => empty($done[$m['folder']])));
        $this->info(sprintf(
            'folders=%d already-done=%d todo=%d%s',
            count($manifest),
            count($done),
            count($todo),
            $this->option('dry-run') ? ' (dry run)' : ''
        ));
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $processed = 0;
        foreach ($todo as $entry) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
            $processed++;
            try {
                $result = $this->importFolder($media, $urls, $entry, $userId);
            } catch (\Throwable $e) {
                $result = ['folder' => $entry['folder'], 'error' => $e->getMessage()];
                $this->error("[{$processed}] {$entry['folder']}: {$e->getMessage()}");
            }
            file_put_contents($outPath, json_encode($result) . PHP_EOL, FILE_APPEND);
            if (empty($result['error'])) {
                $this->line(sprintf(
                    '[%d/%d] %s -> products [%s], %d images',
                    $processed,
                    count($todo),
                    $entry['folder'],
                    implode(',', array_column($entry['products'] ?? [], 'id')),
                    count($result['images'] ?? [])
                ));
            }
        }

        $this->info("done; results appended to {$outPath}");
        return self::SUCCESS;
    }

    private function importFolder(MediaService $media, MediaUrlService $urls, array $entry, int $userId): array
    {
        $products = [];
        foreach ($entry['products'] ?? [] as $p) {
            $product = Product::find($p['id']);
            if ($product) {
                $products[] = $product;
            }
        }

        // Idempotence for attached folders: if every target product already has
        // media-backed images, the folder was fully imported by a prior run.
        if ($products !== []) {
            $pending = array_filter(
                $products,
                fn (Product $p) => !$p->images()->whereNotNull('media_item_id')->exists()
            );
            if ($pending === []) {
                return ['folder' => $entry['folder'], 'skipped' => 'all products already media-backed'];
            }
        }

        $images = [];
        $items = [];
        foreach ($entry['files'] as $i => $path) {
            if (!is_file($path)) {
                throw new \RuntimeException("missing file: {$path}");
            }
            $file = new UploadedFile($path, basename($path), 'image/jpeg', null, true);
            $item = $media->upload($file, [
                'entity_hint' => 'products',
                'alt' => $entry['name'],
                'source' => 'import',
            ], $userId);
            // QUEUE_CONNECTION=sync ran ProcessMediaVersionJob inline during
            // upload(), so v1 is already ready here.
            $version = $item->versions()->orderByDesc('version_number')->firstOrFail();
            $version = $media->publish($version->fresh(), $userId);
            $items[] = $item;
            $images[] = [
                'original' => $urls->url($version, 'original'),
                'large' => $urls->url($version, 'large'),
                'thumbnail' => $urls->url($version, 'thumbnail'),
            ];
        }

        foreach ($products as $product) {
            foreach ($items as $i => $item) {
                $media->attach($item, $product, $i === 0 ? 'main' : 'gallery', $i);
            }
        }

        return [
            'folder' => $entry['folder'],
            'code' => $entry['code'],
            'products' => array_map(fn (Product $p) => $p->id, $products),
            'images' => $images,
        ];
    }
}
