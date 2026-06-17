<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier integration C1 — 3rd-party courier (Shiprocket) fields on shipments. Additive +
 * nullable; existing shipments are unaffected and the manual courier flow keeps working.
 */
return new class extends Migration {
    private array $cols = [
        'awb_number', 'courier_name', 'courier_company_id', 'provider', 'provider_order_id',
        'provider_shipment_id', 'label_url', 'manifest_url', 'tracking_url', 'payment_method',
        'cod_amount', 'shipped_at', 'delivered_at', 'last_status', 'last_status_at',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }
        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'awb_number')) {
                $table->string('awb_number', 64)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'courier_name')) {
                $table->string('courier_name', 96)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'courier_company_id')) {
                $table->unsignedInteger('courier_company_id')->nullable();
            }
            if (!Schema::hasColumn('shipments', 'provider')) {
                $table->string('provider', 24)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'provider_order_id')) {
                $table->string('provider_order_id', 64)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'provider_shipment_id')) {
                $table->string('provider_shipment_id', 64)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'label_url')) {
                $table->string('label_url', 512)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'manifest_url')) {
                $table->string('manifest_url', 512)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'tracking_url')) {
                $table->string('tracking_url', 512)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'payment_method')) {
                $table->string('payment_method', 8)->nullable(); // prepaid | cod
            }
            if (!Schema::hasColumn('shipments', 'cod_amount')) {
                $table->decimal('cod_amount', 14, 2)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'shipped_at')) {
                $table->timestamp('shipped_at')->nullable();
            }
            if (!Schema::hasColumn('shipments', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable();
            }
            if (!Schema::hasColumn('shipments', 'last_status')) {
                $table->string('last_status', 48)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'last_status_at')) {
                $table->timestamp('last_status_at')->nullable();
            }
        });

        // Webhook lookup keys.
        foreach ([['provider_shipment_id'], ['awb_number']] as $idx) {
            try {
                Schema::table('shipments', fn (Blueprint $t) => $t->index($idx));
            } catch (\Throwable $e) {
                // already indexed
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }
        Schema::table('shipments', function (Blueprint $table) {
            foreach ($this->cols as $c) {
                if (Schema::hasColumn('shipments', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
