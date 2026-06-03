<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('garden_package_templates')) {
            return;
        }
        Schema::create('garden_package_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->json('items')->nullable(); // [{category,name,qty,note}]
            $table->integer('suggested_visits')->default(0);
            $table->decimal('suggested_price', 12, 2)->default(0);
            $table->integer('duration_days')->default(30);
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = [
            [
                'name' => 'Balcony Starter',
                'tagline' => 'Perfect first garden for apartments & balconies',
                'description' => 'A complete kit to turn a sunny balcony into a green corner — set up and handed over by our experts.',
                'items' => json_encode([
                    ['category' => 'plants', 'name' => '6 easy-care plants (hand-picked for your light)', 'qty' => 6],
                    ['category' => 'seeds', 'name' => 'Seasonal herb & flower seed pack', 'qty' => 1],
                    ['category' => 'fertilizer', 'name' => 'Organic potting mix + plant food', 'qty' => 1],
                    ['category' => 'tools', 'name' => 'Essential hand-tool kit + planters', 'qty' => 1],
                    ['category' => 'gardener', 'name' => 'Expert setup visits', 'qty' => 2],
                ]),
                'suggested_visits' => 2, 'suggested_price' => 14999, 'duration_days' => 30, 'is_active' => 1, 'sort' => 1,
            ],
            [
                'name' => 'Home Garden',
                'tagline' => 'Our most popular — a thriving multi-zone home garden',
                'description' => 'Designed for terraces & backyards. Seasonal planting, fertile soil, and a gardener who visits regularly to keep everything flourishing.',
                'items' => json_encode([
                    ['category' => 'plants', 'name' => '12+ curated plants across foliage, flowering & herbs', 'qty' => 12],
                    ['category' => 'seeds', 'name' => 'Seasonal vegetable & flower seeds', 'qty' => 1],
                    ['category' => 'fertilizer', 'name' => 'Premium soil, compost & organic fertilizer', 'qty' => 1],
                    ['category' => 'tools', 'name' => 'Full tool kit + designer planters', 'qty' => 1],
                    ['category' => 'gardener', 'name' => 'Monthly gardener maintenance visits', 'qty' => 4],
                    ['category' => 'other', 'name' => 'Pest care + plant-health guarantee', 'qty' => 1],
                ]),
                'suggested_visits' => 4, 'suggested_price' => 39999, 'duration_days' => 90, 'is_active' => 1, 'sort' => 2,
            ],
            [
                'name' => 'Premium Garden + Care',
                'tagline' => 'A designed garden with year-round expert care',
                'description' => 'End-to-end landscaping with premium plants, irrigation, and a dedicated gardener visiting through the season. Fully customised to your space.',
                'items' => json_encode([
                    ['category' => 'plants', 'name' => 'Designer plant selection (premium & statement plants)', 'qty' => 25],
                    ['category' => 'seeds', 'name' => 'Year-round seasonal seed program', 'qty' => 1],
                    ['category' => 'fertilizer', 'name' => 'Premium soil, compost, fertilizer & mulch', 'qty' => 1],
                    ['category' => 'tools', 'name' => 'Drip irrigation + premium planters & tools', 'qty' => 1],
                    ['category' => 'gardener', 'name' => 'Fortnightly gardener visits + seasonal replanting', 'qty' => 12],
                    ['category' => 'other', 'name' => 'Priority support + plant-health guarantee', 'qty' => 1],
                ]),
                'suggested_visits' => 12, 'suggested_price' => 89999, 'duration_days' => 180, 'is_active' => 1, 'sort' => 3,
            ],
        ];
        foreach ($rows as $r) {
            DB::table('garden_package_templates')->insert($r + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('garden_package_templates');
    }
};
