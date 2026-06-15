<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split the delivery partner's name into first/last and add structured bank
 * payout details. `full_name` is kept (auto-derived from first+last at the model
 * layer) so existing readers — the admin list, search, and the mobile app — keep
 * working. The account number is stored encrypted (like aadhaar/pan).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('delivery_partners')) {
            return;
        }

        Schema::table('delivery_partners', function (Blueprint $table) {
            if (!Schema::hasColumn('delivery_partners', 'first_name')) {
                $table->string('first_name')->nullable()->after('is_vendor_cum_dp');
            }
            if (!Schema::hasColumn('delivery_partners', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }
            // Bank / payout details
            if (!Schema::hasColumn('delivery_partners', 'account_holder_name')) {
                $table->string('account_holder_name')->nullable();
            }
            if (!Schema::hasColumn('delivery_partners', 'bank_account_number')) {
                $table->text('bank_account_number')->nullable();   // encrypted at the model layer
            }
            if (!Schema::hasColumn('delivery_partners', 'ifsc_code')) {
                $table->string('ifsc_code', 20)->nullable();
            }
            if (!Schema::hasColumn('delivery_partners', 'bank_name')) {
                $table->string('bank_name')->nullable();
            }
            if (!Schema::hasColumn('delivery_partners', 'branch')) {
                $table->string('branch')->nullable();
            }
        });

        // Backfill first/last from the existing single full_name (first token / remainder).
        foreach (DB::table('delivery_partners')->select('id', 'full_name')->whereNull('first_name')->get() as $row) {
            $name = trim((string) ($row->full_name ?? ''));
            if ($name === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $name, 2);
            DB::table('delivery_partners')->where('id', $row->id)->update([
                'first_name' => $parts[0] ?? $name,
                'last_name'  => $parts[1] ?? null,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('delivery_partners')) {
            return;
        }
        Schema::table('delivery_partners', function (Blueprint $table) {
            foreach (['first_name', 'last_name', 'account_holder_name', 'bank_account_number', 'ifsc_code', 'bank_name', 'branch'] as $col) {
                if (Schema::hasColumn('delivery_partners', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
