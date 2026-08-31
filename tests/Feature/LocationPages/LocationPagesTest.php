<?php

namespace Tests\Feature\LocationPages;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Marvel\Database\Models\LocationPage;
use Marvel\Services\AvailabilityService;

class LocationPagesTest extends LocationPagesTestCase
{
    /* ── slug canonicalisation ────────────────────────────────────── */

    public function test_alias_slug_resolves_to_the_canonical_page(): void
    {
        $cityId = $this->city('Gurugram');
        LocationPage::create([
            'city_id' => $cityId, 'slug' => 'gurugram', 'city_name' => 'Gurugram',
            'is_active' => true,
        ]);

        $canonical = Str::slug(AvailabilityService::canonicalCityKey(str_replace('-', ' ', 'gurgaon')));
        $this->assertSame('gurugram', $canonical);

        $page = LocationPage::where('is_active', true)
            ->whereIn('slug', array_unique(['gurgaon', $canonical]))->first();
        $this->assertNotNull($page);
        $this->assertSame('gurugram', $page->slug, 'alias must land on the canonical row');
    }

    public function test_delhi_district_slugs_collapse_to_delhi(): void
    {
        foreach (['new-delhi', 'south-delhi', 'north-west-delhi'] as $alias) {
            $canonical = Str::slug(AvailabilityService::canonicalCityKey(str_replace('-', ' ', $alias)));
            $this->assertSame('delhi', $canonical, "{$alias} must canonicalise to delhi");
        }
    }

    /* ── public visibility ────────────────────────────────────────── */

    public function test_inactive_pages_are_invisible_publicly(): void
    {
        $cityId = $this->city('Jaipur');
        LocationPage::create([
            'city_id' => $cityId, 'slug' => 'jaipur', 'city_name' => 'Jaipur',
            'is_active' => false,
        ]);

        $visible = LocationPage::where('is_active', true)->get();
        $this->assertCount(0, $visible, 'a draft page must never reach the public index');
    }

    /* ── seeder command ───────────────────────────────────────────── */

    public function test_seeder_creates_pages_only_for_cities_with_live_supply(): void
    {
        $this->city('Delhi');
        $this->supply('delhi');
        $this->city('Jaipur'); // serviceable, but NO supply rows

        Artisan::call('plantathome:seed-location-pages');

        $this->assertNotNull(LocationPage::where('slug', 'delhi')->first());
        $this->assertNull(LocationPage::where('slug', 'jaipur')->first(),
            'sticky is_serviceable without live supply must not create a page');
    }

    public function test_seeder_skips_subdivisions_and_non_active_cities(): void
    {
        $this->city('South Delhi', ['is_subdivision' => true]);
        $this->supply('south delhi');
        $this->city('Noida', ['status' => 'disabled']);
        $this->supply('noida');

        Artisan::call('plantathome:seed-location-pages');

        $this->assertSame(0, LocationPage::count());
    }

    public function test_seeder_is_idempotent_and_activate_flag_works(): void
    {
        $this->city('Delhi');
        $this->supply('delhi');

        Artisan::call('plantathome:seed-location-pages', ['--activate' => true]);
        $page = LocationPage::where('slug', 'delhi')->first();
        $this->assertTrue($page->is_active, '--activate must create the page live');
        $this->assertTrue($page->is_indexable);
        $this->assertStringContainsString('Delhi', (string) $page->seo_title);

        Artisan::call('plantathome:seed-location-pages', ['--activate' => true]);
        $this->assertSame(1, LocationPage::count(), 're-run must not duplicate');
    }

    public function test_seeder_dry_run_writes_nothing(): void
    {
        $this->city('Delhi');
        $this->supply('delhi');

        Artisan::call('plantathome:seed-location-pages', ['--dry-run' => true]);

        $this->assertSame(0, LocationPage::count());
    }

    public function test_seeder_collapses_alias_cities_to_one_page(): void
    {
        // Both spellings serviceable + supplied: one canonical page only.
        $this->city('Gurugram');
        $this->city('Gurgaon');
        $this->supply('gurugram');

        Artisan::call('plantathome:seed-location-pages');

        $this->assertSame(1, LocationPage::count());
        $this->assertSame('gurugram', LocationPage::first()->slug);
    }

    /* ── stock override honesty ───────────────────────────────────── */

    public function test_zeroed_stock_does_not_count_as_supply(): void
    {
        $this->city('Delhi');
        \Illuminate\Support\Facades\DB::table('product_city_availability')->insert([
            'product_id' => 1, 'city' => 'delhi', 'variation_option_id' => 0,
            'has_local' => true, 'has_courier' => false,
            'stock' => 5, 'stock_override' => 0, // operator took it off the shelf
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('plantathome:seed-location-pages');

        $this->assertSame(0, LocationPage::count());
    }
}
