<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Marvel\Database\Models\City;
use Marvel\Database\Models\LocationPage;
use Marvel\Services\AvailabilityService;

/**
 * Creates a landing-page row per city that HONESTLY deserves one: serviceable,
 * not a subdivision, accepting orders, and with real product supply in
 * product_city_availability. Deliberately narrower than cities.is_serviceable,
 * which is sticky (flipped on at vendor onboarding, never off) and over-reports.
 *
 * SEO title/description are templated starting points the admin can edit; the
 * page itself is dominated by dynamic city-scoped content (live products,
 * categories, delivery info), so activated pages are not duplicates of each
 * other.
 */
class SeedLocationPagesCommand extends Command
{
    protected $signature = 'plantathome:seed-location-pages
        {--dry-run : Report what would be created without writing}
        {--activate : Create pages active (default: inactive drafts)}';

    protected $description = 'Create city landing pages for genuinely supplied serviceable cities';

    public function handle(AvailabilityService $availability): int
    {
        $cities = City::where('is_serviceable', true)
            ->where('is_subdivision', false)
            ->whereIn('status', [City::STATUS_ACTIVE, City::STATUS_MAINTENANCE])
            ->orderBy('name')
            ->get(['id', 'name', 'state_name']);

        $created = $skippedNoSupply = $skippedExists = 0;
        $seenSlugs = [];

        foreach ($cities as $city) {
            $key = AvailabilityService::canonicalCityKey($city->name);
            $slug = Str::slug($key);
            if ($slug === '' || isset($seenSlugs[$slug])) {
                continue; // aliases collapse to one page
            }
            $seenSlugs[$slug] = true;

            if (LocationPage::where('slug', $slug)->exists()) {
                $skippedExists++;
                continue;
            }
            if (! $availability->availabilityProductIdQuery($key)->exists()) {
                $skippedNoSupply++;
                $this->line("  no live supply, skipped: {$city->name}");
                continue;
            }

            $display = Str::title($key);
            $this->info(($this->option('dry-run') ? '[dry-run] ' : '') . "create: /plants-in/{$slug} ({$display})");
            $created++;

            if ($this->option('dry-run')) {
                continue;
            }
            LocationPage::create([
                'city_id' => $city->id,
                'slug' => $slug,
                'city_name' => $city->name,
                'state_name' => $city->state_name,
                'seo_title' => "Buy Plants Online in {$display} | Plant Delivery | PlantAtHome",
                'seo_description' => "Order healthy indoor and outdoor plants, pots and gardening essentials online in {$display}. "
                    . 'Hand-checked plants with doorstep delivery from PlantAtHome.',
                'is_active' => (bool) $this->option('activate'),
                'is_indexable' => true,
            ]);
        }

        $this->info("Location pages: {$created} created"
            . ($this->option('activate') ? ' (active)' : ' (drafts)')
            . ", {$skippedExists} already exist, {$skippedNoSupply} skipped for no live supply.");

        return self::SUCCESS;
    }
}
