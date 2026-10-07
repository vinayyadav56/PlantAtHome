<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_10_08_000100_site_font_manrope: the owner's "Everything in Manrope". The body font in
 * options.typography becomes Manrope, the heading choice and every other option are left
 * exactly as they were (empty `{}` maps stay objects), the settings cache is forgotten, and
 * down() puts the old typography back.
 */
final class SiteFontManropeMigrationTest extends TestCase
{
    private const MIGRATION = 'packages/marvel/database/migrations/2026_10_08_000100_site_font_manrope.php';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options');
            $t->string('language')->default('en');
            $t->timestamps();
        });
    }

    private function storedOptions(int $id = 1): string
    {
        return (string) DB::table('settings')->where('id', $id)->value('options');
    }

    public function test_up_sets_the_body_font_and_leaves_everything_else_alone_and_down_restores(): void
    {
        $original = '{"siteTitle":"PlantAtHome","typography":{"fontFamily":"Inter","headingFontFamily":null},"designSystem":{"fontTheme":{"id":"manrope-inter"}},"seo":{},"maintenance":{"start":"2026-01-01"}}';
        DB::table('settings')->insert(['id' => 1, 'options' => $original, 'language' => 'en']);
        Cache::put('cached_settings_en', ['stale' => true], 600);

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $after = json_decode($this->storedOptions());
        $this->assertSame('Manrope', $after->typography->fontFamily);
        $this->assertTrue(property_exists($after->typography, 'headingFontFamily'), 'both keys written');
        $this->assertNull($after->typography->headingFontFamily, 'the heading choice is kept');
        $this->assertSame('manrope-inter', $after->designSystem->fontTheme->id);
        $this->assertSame('PlantAtHome', $after->siteTitle);
        $this->assertStringContainsString('"seo":{}', $this->storedOptions(), 'empty maps stay objects');
        $this->assertNull(Cache::get('cached_settings_en'), 'the settings cache is forgotten');

        $migration->down();

        $this->assertEquals(json_decode($original), json_decode($this->storedOptions()));
        $this->assertFalse(Schema::hasTable('pah_settings_typography_backup'));
    }

    public function test_a_row_already_on_manrope_or_without_typography(): void
    {
        DB::table('settings')->insert([
            ['id' => 1, 'options' => '{"typography":{"fontFamily":"Manrope","headingFontFamily":"Lora"}}', 'language' => 'en'],
            ['id' => 2, 'options' => '{"siteTitle":"X"}', 'language' => 'hi'],
        ]);

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $this->assertSame('Lora', json_decode($this->storedOptions(1))->typography->headingFontFamily, 'already Manrope: untouched');
        $this->assertSame('Manrope', json_decode($this->storedOptions(2))->typography->fontFamily, 'absent typography is created');
        $this->assertSame(1, DB::table('pah_settings_typography_backup')->count());

        $migration->down();

        $this->assertFalse(property_exists(json_decode($this->storedOptions(2)), 'typography'), 'down removes a key that was absent');
    }

    public function test_no_settings_table_is_a_no_op(): void
    {
        Schema::drop('settings');
        (require base_path(self::MIGRATION))->up();

        $this->assertFalse(Schema::hasTable('pah_settings_typography_backup'));
    }
}
