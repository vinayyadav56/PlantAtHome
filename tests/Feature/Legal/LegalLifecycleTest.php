<?php

namespace Tests\Feature\Legal;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Legal\LegalAudit;
use Marvel\Database\Models\Legal\LegalDocument;
use Marvel\Enums\LegalDocumentStatus as Status;
use Marvel\Exceptions\MarvelException;
use Marvel\Services\Legal\LegalDocumentService;
use Marvel\Services\Legal\LegalWorkflow;

class LegalLifecycleTest extends LegalTestCase
{
    private function service(): LegalDocumentService
    {
        return new LegalDocumentService(new LegalWorkflow());
    }

    private function makeDocument(object $user): LegalDocument
    {
        return $this->service()->create([
            'title' => 'Return Policy',
            'type_id' => 1,
            'category_id' => 1,
            'visibility' => 'public',
            'content_html' => '<h2>Purpose</h2><p>Draft body.</p>',
        ], $user);
    }

    public function test_create_generates_slug_code_and_v1_draft(): void
    {
        $doc = $this->makeDocument($this->fullActor());

        $this->assertSame('return-policy', $doc->slug);
        $this->assertMatchesRegularExpression('/^PAH-POL-CUST-\d{3}$/', $doc->document_code);
        $this->assertSame(Status::DRAFT, $doc->status);
        $version = $doc->versions->first();
        $this->assertSame('1.0', $version->versionLabel());
        $this->assertSame(Status::DRAFT, $version->status);
    }

    public function test_full_lifecycle_to_published_and_supersede(): void
    {
        $svc = $this->service();
        $author = $this->fullActor();
        $doc = $this->makeDocument($author);
        $v1 = $doc->versions->first();

        $v1 = $svc->applyTransition('submit-review', $v1, $author);
        $this->assertSame(Status::IN_REVIEW, $v1->status);

        $v1 = $svc->applyTransition('approve-review', $v1, $this->fullActor(8));
        $this->assertSame(Status::PENDING_APPROVAL, $v1->status);

        $v1 = $svc->applyTransition('approve', $v1, $this->fullActor(9));
        $this->assertSame(Status::APPROVED, $v1->status);

        $v1 = $svc->applyTransition('publish', $v1, $this->fullActor(9));
        $this->assertSame(Status::PUBLISHED, $v1->status);
        $doc->refresh();
        $this->assertSame(Status::PUBLISHED, $doc->status);
        $this->assertSame($v1->id, $doc->current_version_id);

        // Editing a published document forks 1.1; publishing it supersedes 1.0.
        $v2 = $svc->newVersion($doc, $author);
        $this->assertSame('1.1', $v2->versionLabel());
        $svc->updateVersion($v2, ['content_html' => '<p>Revised.</p>', 'change_summary' => 'Clarified window'], $author);
        foreach (['submit-review', 'approve-review', 'approve', 'publish'] as $t) {
            $v2 = $svc->applyTransition($t, $v2, $this->fullActor(9));
        }
        $this->assertSame(Status::SUPERSEDED, $v1->fresh()->status);
        $this->assertSame($v2->id, $doc->fresh()->current_version_id);
    }

    public function test_draft_cannot_jump_straight_to_published(): void
    {
        $doc = $this->makeDocument($this->fullActor());
        $this->expectException(MarvelException::class);
        $this->service()->applyTransition('publish', $doc->versions->first(), $this->fullActor());
    }

    public function test_transition_requires_its_permission(): void
    {
        $svc = $this->service();
        $author = $this->fullActor();
        $doc = $this->makeDocument($author);
        $v = $svc->applyTransition('submit-review', $doc->versions->first(), $author);
        $v = $svc->applyTransition('approve-review', $v, $author);

        $reviewerOnly = $this->actor(['legal.view', 'legal.review'], 11);
        $this->expectException(MarvelException::class);
        $svc->applyTransition('approve', $v, $reviewerOnly);
    }

    public function test_rejection_requires_a_comment_and_records_approval_row(): void
    {
        $svc = $this->service();
        $author = $this->fullActor();
        $doc = $this->makeDocument($author);
        $v = $svc->applyTransition('submit-review', $doc->versions->first(), $author);

        try {
            $svc->applyTransition('reject', $v, $this->fullActor(8));
            $this->fail('rejection without a comment must throw');
        } catch (MarvelException) {
        }

        $v = $svc->applyTransition('reject', $v, $this->fullActor(8), 'Section 3 conflicts with the refund policy.');
        $this->assertSame(Status::REVISION_REQUIRED, $v->status);
        $this->assertDatabaseHas('legal_document_approvals', ['version_id' => $v->id, 'status' => 'rejected']);
    }

    public function test_published_version_content_is_locked(): void
    {
        $svc = $this->service();
        $author = $this->fullActor();
        $doc = $this->makeDocument($author);
        $v = $doc->versions->first();
        foreach (['submit-review', 'approve-review', 'approve', 'publish'] as $t) {
            $v = $svc->applyTransition($t, $v, $author);
        }
        $this->expectException(MarvelException::class);
        $svc->updateVersion($v, ['content_html' => '<p>sneaky edit</p>'], $author);
    }

    public function test_publishing_a_synced_slug_mirrors_into_settings_legal_pages(): void
    {
        $svc = $this->service();
        $author = $this->fullActor();
        $doc = $svc->create([
            'title' => 'Privacy Policy',
            'type_id' => 1,
            'visibility' => 'public',
            'content_html' => '<h2>Privacy</h2><p>Governed copy.</p>',
        ], $author);
        $this->assertSame('privacy-policy', $doc->slug);

        $v = $doc->versions->first();
        foreach (['submit-review', 'approve-review', 'approve', 'publish'] as $t) {
            $v = $svc->applyTransition($t, $v, $author);
        }

        $options = json_decode(DB::table('settings')->value('options'), true);
        $this->assertSame('Privacy Policy', $options['legalPages']['privacy']['title']);
        $this->assertStringContainsString('Governed copy', $options['legalPages']['privacy']['body']);
    }

    public function test_html_is_sanitized_on_save(): void
    {
        $doc = $this->service()->create([
            'title' => 'XSS Probe',
            'type_id' => 1,
            'content_html' => '<p onclick="steal()">ok</p><script>alert(1)</script><a href="javascript:bad()">x</a>',
        ], $this->fullActor());

        $html = $doc->versions->first()->content_html;
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_audit_trail_covers_the_lifecycle(): void
    {
        $svc = $this->service();
        $author = $this->fullActor();
        $doc = $this->makeDocument($author);
        $v = $doc->versions->first();
        foreach (['submit-review', 'approve-review', 'approve', 'publish'] as $t) {
            $v = $svc->applyTransition($t, $v, $author);
        }
        $events = LegalAudit::where('document_id', $doc->id)->pluck('event');
        foreach (['document_created', 'submit-review', 'approve-review', 'approve', 'publish'] as $expected) {
            $this->assertContains($expected, $events, "missing audit event {$expected}");
        }
    }
}
