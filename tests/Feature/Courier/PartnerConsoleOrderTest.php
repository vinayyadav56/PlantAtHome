<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use App\Http\Controllers\PartnerConsoleOrderController;
use App\Models\PartnerConsoleOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Partner Orders persistence: console test/book records exactly one row per (partner, provider_order_id)
 * and is idempotent (updateOrCreate); manual record is idempotent; the list filters by partner.
 */
final class PartnerConsoleOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('partner_console_orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('partner_code', 32)->index();
            $t->string('provider_order_id', 191)->nullable();
            $t->string('mode', 32)->nullable();
            $t->unsignedBigInteger('cod_amount_paise')->default(0);
            $t->json('request')->nullable();
            $t->json('response')->nullable();
            $t->string('last_status', 64)->nullable();
            $t->timestamp('last_tracked_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['partner_code', 'provider_order_id']);
            $t->string('origin', 16)->default('console');
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->string('partner_status', 24)->nullable();
            $t->string('previous_partner_status', 24)->nullable();
            $t->timestamp('status_changed_at')->nullable();
            $t->string('status_source', 16)->nullable();
            $t->text('tracking_url')->nullable();
            $t->json('latest_tracking_payload')->nullable();
            $t->json('simulation_response')->nullable();
            $t->smallInteger('simulation_http_status')->nullable();
            $t->string('driver_name', 120)->nullable();
            $t->string('driver_phone', 32)->nullable();
            $t->string('vehicle_number', 32)->nullable();
            $t->text('last_error')->nullable();
            $t->json('last_error_payload')->nullable();
            $t->timestamp('last_error_at')->nullable();
            $t->unsignedTinyInteger('track_failures')->default(0);
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('live_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('reopened_at')->nullable();
        });
    }

    private function controller(): PartnerConsoleOrderController
    {
        return new PartnerConsoleOrderController();
    }

    public function test_manual_record_is_idempotent(): void
    {
        $req = Request::create('/', 'POST', ['partner_code' => 'porter', 'provider_order_id' => 'CRN1786369480057']);
        $this->controller()->store($req);
        $this->controller()->store($req); // same id again

        $this->assertSame(1, PartnerConsoleOrder::where('provider_order_id', 'CRN1786369480057')->count());
    }

    public function test_book_persist_semantics_casts_and_upserts(): void
    {
        // Mirrors CourierPartnerProxyController::testBook's onData upsert.
        $upsert = fn () => PartnerConsoleOrder::updateOrCreate(
            ['partner_code' => 'porter', 'provider_order_id' => 'CRN999'],
            [
                'mode' => 'same_city',
                'cod_amount_paise' => 15000,
                'request' => ['pickup' => ['lat' => 28.49], 'drop' => ['lat' => 28.40]],
                'response' => ['ok' => true, 'provider_order_id' => 'CRN999', 'status' => 'pending'],
                'last_status' => 'pending',
            ],
        );
        $upsert();
        $upsert(); // a re-book / retry with the same id must not duplicate

        $this->assertSame(1, PartnerConsoleOrder::count());
        $row = PartnerConsoleOrder::first();
        $this->assertIsArray($row->request, 'request casts to array');
        $this->assertIsArray($row->response);
        $this->assertSame(15000, $row->cod_amount_paise);
        $this->assertSame('pending', $row->last_status);
    }

    public function test_index_filters_by_partner(): void
    {
        PartnerConsoleOrder::create(['partner_code' => 'porter', 'provider_order_id' => 'CRN1']);
        PartnerConsoleOrder::create(['partner_code' => 'borzo', 'provider_order_id' => 'B1']);

        // index() now returns the paginator AS AN ARRAY with a first-page summary riding along —
        // the dashboard's status chips come from the same request as the rows.
        $all = $this->controller()->index(Request::create('/', 'GET'));
        $this->assertSame(2, $all['total']);
        $this->assertSame(2, $all['summary']['total']);

        $porter = $this->controller()->index(Request::create('/', 'GET', ['partner' => 'porter']));
        $this->assertSame(1, $porter['total']);
        $this->assertSame('CRN1', $porter['data'][0]['provider_order_id']);
        $this->assertSame(1, $porter['summary']['total'], 'the summary follows the partner filter');
    }

    public function test_index_filters_by_status_and_crn(): void
    {
        PartnerConsoleOrder::create(['partner_code' => 'porter', 'provider_order_id' => 'CRN1', 'partner_status' => 'live']);
        PartnerConsoleOrder::create(['partner_code' => 'porter', 'provider_order_id' => 'CRN2', 'partner_status' => 'cancelled']);
        PartnerConsoleOrder::create(['partner_code' => 'porter', 'provider_order_id' => null, 'last_error' => 'refused']);

        $live = $this->controller()->index(Request::create('/', 'GET', ['status' => 'live']));
        $this->assertSame(1, $live['total']);
        $this->assertSame('CRN1', $live['data'][0]['provider_order_id']);

        $failed = $this->controller()->index(Request::create('/', 'GET', ['status' => 'failed']));
        $this->assertSame(1, $failed['total'], 'failed = attempts that never got a CRN');

        $crn = $this->controller()->index(Request::create('/', 'GET', ['crn' => 'CRN2']));
        $this->assertSame(1, $crn['total']);
        $this->assertSame(1, $crn['summary']['failed']);
    }
}
