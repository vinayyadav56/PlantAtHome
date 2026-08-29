<?php

namespace Tests\Feature\Legal;

use Marvel\Database\Models\Legal\LegalCategory;
use Marvel\Database\Models\Legal\LegalDocument;
use Marvel\Database\Models\Legal\LegalTemplate;
use Marvel\Database\Seeders\LegalLibrarySeeder;
use Marvel\Enums\LegalDocumentStatus as Status;

class LegalLibrarySeederTest extends LegalTestCase
{
    private function runLibrarySeeder(): void
    {
        (new LegalLibrarySeeder())->run();
    }

    public function test_it_seeds_the_taxonomy_and_library(): void
    {
        $this->runLibrarySeeder();

        $this->assertGreaterThanOrEqual(9, LegalCategory::count());
        $this->assertGreaterThanOrEqual(55, LegalDocument::count(), 'the blueprint library should be seeded');
        $this->assertSame(4, LegalTemplate::count());

        // Every draft blueprint carries the review flag and a v1.0 body.
        $draft = LegalDocument::where('status', Status::DRAFT)->first();
        $this->assertTrue((bool) $draft->legal_review_required);
        $version = $draft->versions()->first();
        $this->assertSame('1.0', $version->versionLabel());
        $this->assertStringContainsString('LEGAL REVIEW REQUIRED', $version->content_html);
    }

    public function test_the_three_public_pages_are_published_with_live_copy(): void
    {
        $this->runLibrarySeeder();

        foreach (['privacy-policy', 'terms-of-service', 'data-deletion-instructions'] as $slug) {
            $doc = LegalDocument::where('slug', $slug)->first();
            $this->assertNotNull($doc, "{$slug} should be seeded");
            $this->assertSame(Status::PUBLISHED, $doc->status);
            $this->assertSame('public', $doc->visibility);
            $this->assertNotNull($doc->current_version_id, 'published doc must point at its live version');
            $this->assertStringContainsString('Silvestrix Green LLP', $doc->currentVersion->content_html);
        }
    }

    public function test_seeding_twice_creates_nothing_new(): void
    {
        $this->runLibrarySeeder();
        $documents = LegalDocument::count();
        $categories = LegalCategory::count();
        $templates = LegalTemplate::count();

        $this->runLibrarySeeder();

        $this->assertSame($documents, LegalDocument::count());
        $this->assertSame($categories, LegalCategory::count());
        $this->assertSame($templates, LegalTemplate::count());
    }

    public function test_document_codes_are_unique(): void
    {
        $this->runLibrarySeeder();
        $codes = LegalDocument::pluck('document_code');
        $this->assertSame($codes->count(), $codes->unique()->count(), 'document codes must be unique');
    }
}
