<?php

namespace Tests\Feature\Sms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Marvel\Services\SmsTemplateService;

/**
 * Airtel DLT slots are positional ({#var#}); our bodies are semantic
 * ({{name}}). The ONLY thing mapping values into the right slots is the
 * declared variable order — these tests pin the validator that keeps the
 * body and the declaration provably aligned, and the converters that render
 * the DLT/MSG91 views of a body.
 */
class SmsDltAlignmentTest extends SmsTestCase
{
    private function decl(array $names): array
    {
        return array_map(
            fn ($n) => ['name' => $n, 'type' => 'alphanumeric', 'sample' => 'x'],
            $names,
        );
    }

    public function test_aligned_body_passes(): void
    {
        $this->assertNull(SmsTemplateService::validateBodyAgainstDeclaration(
            'Your OTP is {{otp}}, valid {{mins}} minutes.',
            $this->decl(['otp', 'mins']),
        ));
    }

    public function test_swapped_order_is_named(): void
    {
        $reason = SmsTemplateService::validateBodyAgainstDeclaration(
            'Amount {{amount}} for order {{orderId}}.',
            $this->decl(['orderId', 'amount']),
        );
        $this->assertStringContainsString('ORDER', (string) $reason);
    }

    public function test_repeated_placeholder_is_refused(): void
    {
        $reason = SmsTemplateService::validateBodyAgainstDeclaration(
            'Order {{orderId}} — track {{orderId}}.',
            $this->decl(['orderId']),
        );
        $this->assertStringContainsString('repeats', (string) $reason);
    }

    public function test_undeclared_and_unused_variables_are_refused(): void
    {
        $this->assertStringContainsString('undeclared', (string) SmsTemplateService::validateBodyAgainstDeclaration(
            'Hi {{name}}.',
            $this->decl([]),
        ));
        $this->assertStringContainsString('never appear', (string) SmsTemplateService::validateBodyAgainstDeclaration(
            'Hello there.',
            $this->decl(['name']),
        ));
    }

    public function test_duplicate_declared_names_are_refused(): void
    {
        $this->assertStringContainsString('Duplicate', (string) SmsTemplateService::validateBodyAgainstDeclaration(
            '{{a}} {{a}}',
            $this->decl(['a', 'a']),
        ));
    }

    public function test_converters_render_dlt_and_flow_views(): void
    {
        $body = 'OTP {{otp}} valid {{ mins }} minutes.';
        $this->assertSame('OTP {#var#} valid {#var#} minutes.', SmsTemplateService::toDltFormat($body));
        $this->assertSame('OTP ##var1## valid ##var2## minutes.', SmsTemplateService::toFlowFormat($body));
    }

    public function test_send_refuses_a_drifted_row_and_sends_nothing(): void
    {
        config([
            'auth.notify_gateway' => 'msg91',
            'services.msg91.auth_key' => 'test-key',
            'services.msg91.sender' => 'PLNTAT',
        ]);
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success'])]);
        Log::spy();

        // Seeded body order is paymentAmount THEN orderId — flip the DECLARATION.
        $this->activate('PlantAtHome_Payment_Success', 'FLOW_PS');
        DB::table('email_templates')->where('template_code', 'PlantAtHome_Payment_Success')->update([
            'variables' => json_encode($this->decl(['orderId', 'paymentAmount'])),
        ]);

        $ok = (new SmsTemplateService())->sendByCode('PlantAtHome_Payment_Success', '9876543210', [
            'orderId' => 'PAH-1', 'paymentAmount' => '1499',
        ]);

        $this->assertFalse($ok, 'a drifted template must refuse, not deliver swapped values');
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($msg) => $msg === 'sms.template.slot_mismatch'
        )->once();
    }
}
