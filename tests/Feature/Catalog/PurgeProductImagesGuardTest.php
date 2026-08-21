<?php

namespace Tests\Feature\Catalog;

use Marvel\Console\PurgeProductImagesCommand;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The key allowlist is the last thing between a wrong manifest and permanent deletion: the media
 * bucket has no versioning, and it holds the site logo, category banners, shop assets and the
 * catalog backups alongside product imagery.
 *
 * It is tested rather than trusted because the layout was already wrong once — the command assumed
 * every product object lived under a numeric media id, when 7,230 of 7,240 in the live catalog are
 * under plants/{slug}/. The guard refused the whole purge and the assumption was corrected.
 */
class PurgeProductImagesGuardTest extends TestCase
{
    private function isProductKey(string $key): bool
    {
        $m = new ReflectionMethod(PurgeProductImagesCommand::class, 'isProductKey');
        $m->setAccessible(true);
        return $m->invoke(new PurgeProductImagesCommand(), $key);
    }

    /** @dataProvider keys */
    public function test_only_product_layouts_are_deletable(string $key, bool $allowed, string $why): void
    {
        $this->assertSame($allowed, $this->isProductKey($key), $why);
    }

    public static function keys(): array
    {
        return [
            // The two real layouts, both verified against the live bucket.
            ['plants/30-cm-spiral-stick-lucky-bamboo-plant/1.jpg', true, 'image-pipeline photo'],
            ['plants/abelia-confetti/3.jpg', true, 'image-pipeline photo'],
            ['2504/ChatGPT-Image-Jun-9,-2026,-11_58_03-PM.png', true, 'spatie media original'],
            ['2504/conversions/ChatGPT-Image-Jun-9,-2026,-11_58_03-PM-thumbnail.jpg', true, 'spatie conversion'],
            ['ai-batches/BATCH000007/PLT001_Areca_Palm/PLT001_Areca_Palm_1.png', true, 'image_generation_results output'],
            ['ai-instant/some-generated-file.png', true, 'instant_images output'],

            // Everything else in the same bucket must be untouchable.
            ['backups/pah-catalog-20260821-050421.sql.gz', false, 'the catalog backup lives here — deleting it destroys the restore point'],
            ['shop-assets/visa.png', false, 'shop asset'],
            ['plants/', false, 'a bare prefix names no object'],
            ['plants/slug-only', false, 'no file component'],
            ['', false, 'empty key'],
            ['logo.svg', false, 'root-level asset'],
            ['ai-batches/', false, 'a bare prefix names no object'],
            ['ai-batches/BATCH000007', false, 'no file component'],
            ['../2504/x.png', false, 'traversal must not match the numeric layout'],
        ];
    }
}
