<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A master list of HSN/SAC codes.
 *
 * products.hsn_code and tax_classes.hsn_code were free text in two places, so
 * the same pot could be filed under 3924, 39240090 or "3924 " depending on who
 * typed it — and an HSN that does not exist is a return the department rejects.
 * This gives the admin a list to pick from.
 *
 * Seeded from whatever is ALREADY in use first, so validating against it cannot
 * block an existing product from being saved, then topped up with the codes
 * this catalogue actually needs. Descriptions only: a rate is the CA's call
 * (default_tax_rate_id stays null until they set it), and the engine's rule is
 * that an unconfigured product is 0%, never a guessed 18%.
 */
return new class extends Migration
{
    /** code => description. No rates — see the class docblock. */
    private const CATALOGUE = [
        '0601' => 'Bulbs, tubers, tuberous roots, corms, crowns and rhizomes',
        '0602' => 'Other live plants (including roots), cuttings and slips',
        '0603' => 'Cut flowers and flower buds',
        '0604' => 'Foliage, branches and other parts of plants, without flowers',
        '3101' => 'Animal or vegetable fertilisers',
        '3105' => 'Mineral or chemical fertilisers',
        '3808' => 'Insecticides, fungicides, herbicides and similar products',
        '3924' => 'Tableware, kitchenware and other household articles of plastics',
        '4420' => 'Wooden articles of furniture and ornaments',
        '6305' => 'Sacks and bags, of a kind used for the packing of goods',
        '6810' => 'Articles of cement, of concrete or of artificial stone',
        '6912' => 'Ceramic tableware, kitchenware and other household articles',
        '7326' => 'Other articles of iron or steel',
        '8201' => 'Hand tools used in agriculture, horticulture or forestry',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('hsn_codes')) {
            Schema::create('hsn_codes', function (Blueprint $table) {
                $table->id();
                $table->string('code', 8)->unique();
                $table->string('description')->nullable();
                // The rate this code usually attracts, offered as a default when
                // an admin picks the code on a product. Null until a CA sets it.
                $table->unsignedBigInteger('default_tax_rate_id')->nullable()->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        $this->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('hsn_codes');
    }

    private function seed(): void
    {
        $now = now();
        $rows = [];

        // 1. everything already in use, so validation can never block a save
        foreach (['products', 'tax_classes'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'hsn_code')) {
                continue;
            }
            foreach (DB::table($table)->whereNotNull('hsn_code')->distinct()->pluck('hsn_code') as $code) {
                $code = strtoupper(trim((string) $code));
                if ($code !== '' && strlen($code) <= 8) {
                    $rows[$code] = ['code' => $code, 'description' => null];
                }
            }
        }

        // 2. the codes this catalogue needs, whose descriptions win over a bare in-use row
        foreach (self::CATALOGUE as $code => $description) {
            $rows[$code] = ['code' => $code, 'description' => $description];
        }

        if (!$rows) {
            return;
        }

        $existing = DB::table('hsn_codes')->pluck('code')->all();
        $insert = [];
        foreach ($rows as $code => $row) {
            if (in_array($code, $existing, true)) {
                continue;
            }
            $insert[] = $row + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($insert, 100) as $chunk) {
            DB::table('hsn_codes')->insert($chunk);
        }
    }
};
