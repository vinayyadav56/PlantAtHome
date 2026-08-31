<?php

namespace Tests\Feature\Legal;

use Marvel\Database\Models\Legal\LegalDocument;
use Marvel\Database\Models\Legal\LegalVariable;
use Marvel\Enums\LegalDocumentStatus as Status;
use Marvel\Exceptions\MarvelException;
use Marvel\Services\Legal\LegalDocumentService;
use Marvel\Services\Legal\LegalWorkflow;
use Marvel\Services\Legal\VariableResolver;

class LegalVariablesTest extends LegalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = require base_path('packages/marvel/database/migrations/2026_08_31_000300_create_legal_variables_and_relations.php');
        $migration->up();
        VariableResolver::flushCache();

        LegalVariable::create([
            'key' => 'refund_processing_time', 'label' => 'Refund processing time',
            'value' => '5-7 business days', 'category' => 'refunds', 'is_approved' => false,
        ]);
        LegalVariable::create([
            'key' => 'support_channels', 'label' => 'Support channels',
            'value' => 'email and WhatsApp', 'category' => 'support', 'is_approved' => true,
        ]);
        VariableResolver::flushCache();
    }

    private function service(): LegalDocumentService
    {
        return new LegalDocumentService(new LegalWorkflow());
    }

    public function test_admin_context_shows_unapproved_values_but_marks_them(): void
    {
        $html = VariableResolver::resolve('<p>Within {{refund_processing_time}}.</p>', VariableResolver::ADMIN);

        $this->assertStringContainsString('5-7 business days', $html);
        $this->assertStringContainsString('legal-var--pending', $html, 'an unapproved value must be visibly marked in admin');
    }

    public function test_public_context_hides_unapproved_values(): void
    {
        $html = VariableResolver::resolve('<p>Within {{refund_processing_time}}.</p>', VariableResolver::PUBLIC_CONTEXT);

        $this->assertStringNotContainsString('5-7 business days', $html, 'an unapproved default must never reach the public');
        $this->assertStringNotContainsString('{{', $html, 'raw placeholders must never be exposed');
        $this->assertStringContainsString('currently approved configuration', $html);
    }

    public function test_public_context_substitutes_approved_values(): void
    {
        $html = VariableResolver::resolve('<p>Reach us on {{support_channels}}.</p>', VariableResolver::PUBLIC_CONTEXT);

        $this->assertStringContainsString('email and WhatsApp', $html);
    }

    public function test_unknown_placeholder_is_never_exposed_publicly(): void
    {
        $html = VariableResolver::resolve('<p>Value {{not_a_real_variable}}.</p>', VariableResolver::PUBLIC_CONTEXT);

        $this->assertStringNotContainsString('not_a_real_variable', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    public function test_outstanding_reports_unapproved_and_unknown(): void
    {
        $out = VariableResolver::outstanding('<p>{{refund_processing_time}} {{support_channels}} {{ghost_var}}</p>');

        $this->assertSame(['refund_processing_time'], $out['unapproved']);
        $this->assertSame(['ghost_var'], $out['unknown']);
    }

    public function test_publishing_a_public_document_with_unapproved_variables_is_blocked(): void
    {
        $svc = $this->service();
        $user = $this->fullActor();
        $doc = $svc->create([
            'title' => 'Refund Policy',
            'type_id' => 1,
            'visibility' => 'public',
            'content_html' => '<p>Refunds within {{refund_processing_time}} of approval.</p>',
        ], $user);

        $v = $doc->versions->first();
        foreach (['submit-review', 'approve-review', 'approve'] as $t) {
            $v = $svc->applyTransition($t, $v, $user);
        }

        $this->expectException(MarvelException::class);
        $this->expectExceptionMessageMatches('/refund_processing_time/');
        $svc->applyTransition('publish', $v, $user);
    }

    public function test_internal_documents_publish_with_draft_values(): void
    {
        $svc = $this->service();
        $user = $this->fullActor();
        $doc = $svc->create([
            'title' => 'Internal Refund Runbook',
            'type_id' => 1,
            'visibility' => 'internal',
            'content_html' => '<p>Refunds within {{refund_processing_time}}.</p>',
        ], $user);

        $v = $doc->versions->first();
        foreach (['submit-review', 'approve-review', 'approve', 'publish'] as $t) {
            $v = $svc->applyTransition($t, $v, $user);
        }
        $this->assertSame(Status::PUBLISHED, $v->status);
    }

    public function test_approving_the_variable_unblocks_publication(): void
    {
        LegalVariable::where('key', 'refund_processing_time')->update(['is_approved' => true]);
        VariableResolver::flushCache();

        $svc = $this->service();
        $user = $this->fullActor();
        $doc = $svc->create([
            'title' => 'Refund Policy Public',
            'type_id' => 1,
            'visibility' => 'public',
            'content_html' => '<p>Refunds within {{refund_processing_time}}.</p>',
        ], $user);

        $v = $doc->versions->first();
        foreach (['submit-review', 'approve-review', 'approve', 'publish'] as $t) {
            $v = $svc->applyTransition($t, $v, $user);
        }
        $this->assertSame(Status::PUBLISHED, $v->status);
    }

    public function test_new_drafts_start_at_v0_1_and_publishing_promotes_to_1_0(): void
    {
        $svc = $this->service();
        $user = $this->fullActor();
        $doc = $svc->create([
            'title' => 'Versioning Probe',
            'type_id' => 1,
            'visibility' => 'internal',
            'content_html' => '<p>Body.</p>',
        ], $user);

        $v = $doc->versions->first();
        $this->assertSame('0.1', $v->versionLabel(), 'drafts start at 0.1');

        foreach (['submit-review', 'approve-review', 'approve', 'publish'] as $t) {
            $v = $svc->applyTransition($t, $v, $user);
        }
        $this->assertSame('1.0', $v->fresh()->versionLabel(), 'first issue promotes to 1.0');
    }
}
