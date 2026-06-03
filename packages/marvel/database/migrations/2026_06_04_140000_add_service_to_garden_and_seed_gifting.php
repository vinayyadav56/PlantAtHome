<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Generalize the garden tables to also serve Corporate Gifting via a `service`
 * discriminator (garden|gifting) + lead `source` (garden|corporate) and a few
 * corporate fields. Seeds 3 buyable gifting templates.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('garden_package_templates') && !Schema::hasColumn('garden_package_templates', 'service')) {
            Schema::table('garden_package_templates', function (Blueprint $table) {
                $table->string('service')->default('garden')->index();
            });
        }
        if (Schema::hasTable('garden_packages') && !Schema::hasColumn('garden_packages', 'service')) {
            Schema::table('garden_packages', function (Blueprint $table) {
                $table->string('service')->default('garden')->index();
            });
        }
        if (Schema::hasTable('garden_leads') && !Schema::hasColumn('garden_leads', 'source')) {
            Schema::table('garden_leads', function (Blueprint $table) {
                $table->string('source')->default('garden')->index();
                $table->string('company')->nullable();
                $table->string('occasion')->nullable();
                $table->string('quantity')->nullable();
            });
        }

        // Seed gifting templates once.
        if (Schema::hasTable('garden_package_templates') && DB::table('garden_package_templates')->where('service', 'gifting')->count() === 0) {
            $now = now();
            $rows = [
                [
                    'name' => 'Festive Plant Hampers',
                    'tagline' => 'Diwali & festival gifting that lasts beyond the season',
                    'description' => 'Curated live-plant hampers in premium eco packaging — a memorable, sustainable corporate gift for clients & teams.',
                    'items' => json_encode([
                        ['category' => 'plants', 'name' => 'Curated desk plant (per hamper)', 'qty' => 1],
                        ['category' => 'other', 'name' => 'Premium eco gift box + care card', 'qty' => 1],
                        ['category' => 'other', 'name' => 'Custom branded message tag', 'qty' => 1],
                        ['category' => 'gardener', 'name' => 'Bulk delivery & coordination', 'qty' => 0],
                    ]),
                    'suggested_visits' => 0, 'suggested_price' => 6999, 'duration_days' => 0, 'is_active' => 1, 'sort' => 1, 'service' => 'gifting',
                ],
                [
                    'name' => 'Employee Welcome Kits',
                    'tagline' => 'Greet every new joiner with a living desk companion',
                    'description' => 'Low-maintenance desk plants with branded pots — perfect for onboarding kits, milestones and employee appreciation at scale.',
                    'items' => json_encode([
                        ['category' => 'plants', 'name' => 'Easy-care desk plant', 'qty' => 1],
                        ['category' => 'tools', 'name' => 'Branded ceramic/self-watering pot', 'qty' => 1],
                        ['category' => 'other', 'name' => 'Care guide + welcome note', 'qty' => 1],
                    ]),
                    'suggested_visits' => 0, 'suggested_price' => 999, 'duration_days' => 0, 'is_active' => 1, 'sort' => 2, 'service' => 'gifting',
                ],
                [
                    'name' => 'Premium Curated Hampers',
                    'tagline' => 'Statement gifts for VIP clients & leadership',
                    'description' => 'Designer plants, premium planters and gourmet eco add-ons in a luxury hamper — fully customisable to your brand and budget.',
                    'items' => json_encode([
                        ['category' => 'plants', 'name' => 'Premium statement plant', 'qty' => 1],
                        ['category' => 'tools', 'name' => 'Designer planter', 'qty' => 1],
                        ['category' => 'other', 'name' => 'Luxury eco hamper + custom branding', 'qty' => 1],
                        ['category' => 'other', 'name' => 'Optional gourmet add-ons', 'qty' => 1],
                    ]),
                    'suggested_visits' => 0, 'suggested_price' => 2999, 'duration_days' => 0, 'is_active' => 1, 'sort' => 3, 'service' => 'gifting',
                ],
            ];
            foreach ($rows as $r) {
                DB::table('garden_package_templates')->insert($r + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('garden_package_templates', 'service')) {
            Schema::table('garden_package_templates', fn (Blueprint $t) => $t->dropColumn('service'));
        }
        if (Schema::hasColumn('garden_packages', 'service')) {
            Schema::table('garden_packages', fn (Blueprint $t) => $t->dropColumn('service'));
        }
        if (Schema::hasColumn('garden_leads', 'source')) {
            Schema::table('garden_leads', fn (Blueprint $t) => $t->dropColumn(['source', 'company', 'occasion', 'quantity']));
        }
    }
};
