<?php

namespace Tests\Feature\Sms;

use Illuminate\Support\Facades\Http;
use Marvel\Services\SmsTemplateService;

class SmsTemplateServiceTest extends SmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'auth.notify_gateway' => 'msg91',
            'services.msg91.auth_key' => 'test-key',
            'services.msg91.sender' => 'PLNTAT',
        ]);
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success', 'request_id' => 'req1'])]);
    }

    private function svc(): SmsTemplateService
    {
        return new SmsTemplateService();
    }

    public function test_active_template_sends_vars_in_declared_order(): void
    {
        $this->activate('PlantAtHome_Payment_Success', 'FLOW_PS');

        $ok = $this->svc()->sendByCode('PlantAtHome_Payment_Success', '9876543210', [
            'orderId' => 'PAH-1', 'paymentAmount' => '1499',
        ]);

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            $body = $request->data();
            $recipient = $body['recipients'][0];
            // Declared order is paymentAmount THEN orderId — var1/var2 must
            // follow the declaration, not the caller's array order.
            return $body['template_id'] === 'FLOW_PS'
                && $recipient['var1'] === '1499'
                && $recipient['var2'] === 'PAH-1'
                && $recipient['mobiles'] === '919876543210';
        });
    }

    public function test_draft_or_unconfigured_template_refuses_and_sends_nothing(): void
    {
        // Draft (default seed state)
        $this->assertFalse($this->svc()->sendByCode('PlantAtHome_Order_Confirmed', '9876543210', [
            'orderId' => 'PAH-1', 'orderAmount' => '100',
        ]));

        // Active but no MSG91 flow id
        \Illuminate\Support\Facades\DB::table('email_templates')
            ->where('template_code', 'PlantAtHome_Order_Confirmed')->update(['status' => 'active']);
        $this->assertFalse($this->svc()->sendByCode('PlantAtHome_Order_Confirmed', '9876543210', [
            'orderId' => 'PAH-1', 'orderAmount' => '100',
        ]));

        Http::assertNothingSent();
    }

    public function test_whatsapp_notify_gateway_leaves_dlt_path_alone(): void
    {
        config(['auth.notify_gateway' => 'whatsapp']);
        $this->activate('PlantAtHome_Order_Confirmed');

        $this->assertFalse($this->svc()->sendByCode('PlantAtHome_Order_Confirmed', '9876543210', [
            'orderId' => 'PAH-1', 'orderAmount' => '100',
        ]));
        Http::assertNothingSent();
    }

    public function test_missing_variable_refuses(): void
    {
        $this->activate('PlantAtHome_Order_Confirmed');
        $this->assertFalse($this->svc()->sendByCode('PlantAtHome_Order_Confirmed', '9876543210', [
            'orderId' => 'PAH-1', // orderAmount missing
        ]));
        Http::assertNothingSent();
    }

    public function test_url_in_a_variable_value_refuses(): void
    {
        $this->activate('PlantAtHome_Order_Confirmed');
        $this->assertFalse($this->svc()->sendByCode('PlantAtHome_Order_Confirmed', '9876543210', [
            'orderId' => 'https://plantathome.in/order/PAH-1', 'orderAmount' => '100',
        ]));
        Http::assertNothingSent();
    }

    public function test_otp_provider_template_id_prefers_active_registry_row(): void
    {
        $this->assertNull(SmsTemplateService::providerTemplateId('PlantAtHome_Login_OTP'));

        $this->activate('PlantAtHome_Login_OTP', 'OTPFLOW9');
        $this->assertSame('OTPFLOW9', SmsTemplateService::providerTemplateId('PlantAtHome_Login_OTP'));
    }
}
