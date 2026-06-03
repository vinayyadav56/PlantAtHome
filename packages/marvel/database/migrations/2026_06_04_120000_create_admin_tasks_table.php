<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Admin "Pending Tasks" — a tracked checklist of outstanding setup / follow-up
 * items, visible under Setup → Pending Tasks. Seeded with everything still
 * pending as of this build; admins can add, mark done, or reopen.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('admin_tasks')) {
            return;
        }
        Schema::create('admin_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->default('general'); // setup/security/feature/tech-debt/infra
            $table->string('priority')->default('medium');   // high/medium/low
            $table->string('status')->default('pending')->index(); // pending/done
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        $seed = [
            ['Register Razorpay garden webhook', 'Add webhook URL <API>/api/webhooks/razorpay-garden for event payment_link.paid so paid garden packages auto-activate.', 'setup', 'high'],
            ['Set VOICE_SEARCH_INGEST_SECRET in prod', 'The voice-search cost-log ingest endpoint is open by default; set this env var to lock it down in production.', 'security', 'high'],
            ['Rotate OPENAI_API_KEY', 'The OpenAI key was shared in plaintext during setup; rotate it in the OpenAI dashboard and update Vercel (shop project).', 'security', 'high'],
            ['Repair remaining catalog Unsplash images', '~10 staging product/category images still point at external Unsplash URLs (DB data). Run the image-repair pass to move them to S3.', 'tech-debt', 'medium'],
            ['Add admin error states to voice stats/logs', 'Voice cost dashboard stats/logs render zeros on API failure instead of an error state.', 'tech-debt', 'low'],
            ['Fix 14 masked admin TypeScript errors', 'Admin build has ignoreBuildErrors:true masking ~14 real TS errors (product-image.ts, product-form.tsx, etc.).', 'tech-debt', 'medium'],
            ['Garden package template CRUD UI', 'API + 3 seeded tiers exist; add an admin UI to create/edit garden package templates.', 'feature', 'low'],
            ['Garden landing SEO + lead notifications', 'Add SEO metadata + sitemap entry for /garden-service and notify admin (email/WhatsApp) on a new lead.', 'feature', 'low'],
            ['Storefront SSR edge caching', 'Add Cache-Control (s-maxage/stale-while-revalidate) to storefront *.ssr.ts reads to cut origin hits.', 'tech-debt', 'low'],
            ['Offsite EC2 catalog backups', 'prod-data-op S3 offsite copy is skipped (no aws CLI on the box) — backups are local-only. Install aws CLI or add another offsite path.', 'infra', 'medium'],
            ['Track Whisper transcription cost', 'Voice cost tracking covers chat tokens only; Whisper (per-second audio) cost is not recorded.', 'tech-debt', 'low'],
        ];
        foreach ($seed as $s) {
            DB::table('admin_tasks')->insert([
                'title' => $s[0], 'description' => $s[1], 'category' => $s[2], 'priority' => $s[3],
                'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_tasks');
    }
};
