<?php

namespace Tests\Feature\Sms;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Seeders\SmsTemplateSeeder;

class SmsTemplateSeederTest extends SmsTestCase
{
    public function test_all_18_templates_seed_once_and_rerun_is_idempotent(): void
    {
        // The migration itself seeds inline (deploys never run db:seed).
        $this->assertSame(18, DB::table('email_templates')->where('channel', 'sms')->count());

        (new SmsTemplateSeeder())->run();
        (new SmsTemplateSeeder())->run();

        $this->assertSame(18, DB::table('email_templates')->where('channel', 'sms')->count());
        $codes = DB::table('email_templates')->where('channel', 'sms')->pluck('template_code');
        $this->assertSame($codes->count(), $codes->unique()->count(), 'template codes must be unique');
    }

    public function test_admin_edits_survive_a_reseed(): void
    {
        DB::table('email_templates')->where('template_code', 'PlantAtHome_Login_OTP')->update([
            'dlt_template_id' => '1107123456789012345',
            'provider_template_id' => 'FLOWABC',
            'status' => 'active',
            'text_body' => 'PlantAtHome verification code is {{otp}}. Valid {{otpValidityMinutes}} minutes.',
        ]);

        (new SmsTemplateSeeder())->run();

        $row = DB::table('email_templates')->where('template_code', 'PlantAtHome_Login_OTP')->first();
        $this->assertSame('1107123456789012345', $row->dlt_template_id);
        $this->assertSame('active', $row->status);
        $this->assertStringContainsString('Valid {{otpValidityMinutes}} minutes.', $row->text_body);
    }

    public function test_bodies_are_url_free_and_never_carry_airtel_placeholders(): void
    {
        foreach (DB::table('email_templates')->where('channel', 'sms')->get() as $row) {
            $this->assertDoesNotMatchRegularExpression('~(https?://|www\.|\S+@\S+\.\S+)~i', $row->text_body, $row->template_code);
            $this->assertStringNotContainsString('{#', $row->text_body, $row->template_code);
            $this->assertStringContainsString('PlantAtHome', $row->text_body, $row->template_code);
            foreach (json_decode($row->variables, true) ?: [] as $v) {
                $this->assertDoesNotMatchRegularExpression('~(https?://|www\.)~i', $v['sample'], "{$row->template_code}.{$v['name']}");
                $this->assertContains($v['type'], ['numeric', 'alphanumeric']);
            }
        }
    }

    public function test_variable_order_matches_the_dlt_declaration(): void
    {
        // Payment_Success deliberately reverses the pair vs Order_Confirmed.
        $ps = json_decode(DB::table('email_templates')->where('template_code', 'PlantAtHome_Payment_Success')->value('variables'), true);
        $this->assertSame(['paymentAmount', 'orderId'], array_column($ps, 'name'));

        $oc = json_decode(DB::table('email_templates')->where('template_code', 'PlantAtHome_Order_Confirmed')->value('variables'), true);
        $this->assertSame(['orderId', 'orderAmount'], array_column($oc, 'name'));
        $this->assertSame('PAH-20260911-10452', $oc[0]['sample']);
        $this->assertSame('numeric', $oc[1]['type']);

        $otp = json_decode(DB::table('email_templates')->where('template_code', 'PlantAtHome_Login_OTP')->value('variables'), true);
        $this->assertSame(['otp', 'otpValidityMinutes'], array_column($otp, 'name'));
    }

    public function test_no_variable_templates_declare_empty_arrays(): void
    {
        foreach (['PlantAtHome_Account_Created', 'PlantAtHome_Password_Changed'] as $code) {
            $vars = json_decode(DB::table('email_templates')->where('template_code', $code)->value('variables'), true);
            $this->assertSame([], $vars, $code);
        }
    }

    public function test_dlt_template_id_starts_empty_and_differs_from_code(): void
    {
        foreach (DB::table('email_templates')->where('channel', 'sms')->get() as $row) {
            $this->assertNull($row->dlt_template_id, $row->template_code);
            $this->assertSame('draft', $row->status, $row->template_code);
        }
    }
}
