<?php

namespace Tests\Feature\Vendor;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Shop;
use Marvel\Services\VendorKycService;
use Tests\TestCase;

/**
 * The vendor paperwork policy is one switch, and both halves must read it.
 *
 * Turning off only the approve gate would let a vendor go live and then be put
 * on hold weeks later by the nightly sweep, for documents nobody is asking for
 * any more. These pin that the switch reaches the shared source of truth, so
 * the approve screen and the sweep can never disagree.
 */
final class VendorKycEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function shopWithNoDocuments(): Shop
    {
        return new Shop(['settings' => ['documents' => []]]);
    }

    public function test_nothing_is_missing_while_the_policy_is_off(): void
    {
        config(['shop.kyc.enforce' => false]);
        $kyc = app(VendorKycService::class);

        $this->assertFalse($kyc->enforced());
        // No documents at all, yet nothing is owed — this is what unblocks approval.
        $this->assertSame([], $kyc->missingDocuments($this->shopWithNoDocuments()));
    }

    public function test_the_full_required_set_comes_back_when_it_is_on(): void
    {
        config(['shop.kyc.enforce' => true]);
        $kyc = app(VendorKycService::class);

        $this->assertTrue($kyc->enforced());
        $this->assertSame(
            ['GST Certificate', 'PAN card', 'Cancelled cheque'],
            $kyc->missingDocuments($this->shopWithNoDocuments()),
        );
    }

    public function test_a_shop_that_has_filed_everything_owes_nothing_either_way(): void
    {
        $complete = new Shop(['settings' => ['documents' => [
            'gstCertificate' => 'https://example.test/gst.pdf',
            'pan'            => 'https://example.test/pan.pdf',
            'cheque'         => 'https://example.test/cheque.pdf',
        ]]]);

        foreach ([false, true] as $enforced) {
            config(['shop.kyc.enforce' => $enforced]);
            $this->assertSame([], app(VendorKycService::class)->missingDocuments($complete));
        }
    }

    /** The default ships OFF — that is the decision, not an accident of env. */
    public function test_it_is_off_by_default(): void
    {
        $this->assertFalse(
            filter_var(env('VENDOR_KYC_ENFORCE', false), FILTER_VALIDATE_BOOLEAN),
            'VENDOR_KYC_ENFORCE should default to false',
        );
    }
}
