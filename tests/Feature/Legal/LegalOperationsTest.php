<?php

namespace Tests\Feature\Legal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Legal\LegalComplianceItem;
use Marvel\Database\Models\Legal\LegalCorrectiveAction;
use Marvel\Database\Models\Legal\LegalRiskItem;
use Marvel\Database\Models\Legal\LegalSettings;
use Marvel\Services\Legal\RiskScoring;

class LegalOperationsTest extends LegalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = require base_path('packages/marvel/database/migrations/2026_08_30_000200_create_legal_operations_tables.php');
        $migration->up();
    }

    private function risk(array $attrs = []): LegalRiskItem
    {
        return LegalRiskItem::create(array_merge([
            'risk_code' => 'RSK-' . Str::random(5),
            'title' => 'Courier partner outage',
            'category' => 'logistics',
            'probability' => 3,
            'impact' => 4,
        ], $attrs));
    }

    public function test_risk_score_and_level_are_derived_not_supplied(): void
    {
        // A client trying to set its own score/level must not win.
        $risk = $this->risk(['probability' => 4, 'impact' => 5, 'risk_score' => 1, 'risk_level' => 'low']);

        $this->assertSame(20, $risk->risk_score);
        $this->assertSame('critical', $risk->risk_level);
    }

    public function test_scoring_bands_map_correctly(): void
    {
        foreach ([[1, 1, 'low'], [2, 2, 'low'], [3, 3, 'medium'], [4, 4, 'high'], [5, 5, 'critical']] as [$p, $i, $level]) {
            $this->assertSame($level, RiskScoring::level(RiskScoring::score($p, $i)), "p{$p}×i{$i}");
        }
    }

    public function test_thresholds_are_configurable_from_settings(): void
    {
        $settings = LegalSettings::current();
        $settings->settings = ['risk_thresholds' => ['low' => 2, 'medium' => 4, 'high' => 8]];
        $settings->save();

        // 3×3=9 is 'medium' by default but 'critical' under this stricter model.
        $this->assertSame('critical', RiskScoring::level(RiskScoring::score(3, 3)));
    }

    public function test_rescoring_on_update_moves_the_level(): void
    {
        $risk = $this->risk(['probability' => 1, 'impact' => 1]);
        $this->assertSame('low', $risk->risk_level);

        $risk->update(['probability' => 5, 'impact' => 4]);
        $this->assertSame(20, $risk->fresh()->risk_score);
        $this->assertSame('critical', $risk->fresh()->risk_level);
    }

    public function test_corrective_actions_attach_to_any_source(): void
    {
        $risk = $this->risk();
        $compliance = LegalComplianceItem::create([
            'item_code' => 'CMP-001',
            'title' => 'GST e-invoice threshold',
            'compliance_area' => 'Taxation',
            'status' => 'under_review',
        ]);

        LegalCorrectiveAction::create(['source_type' => 'risk', 'source_id' => $risk->id, 'title' => 'Add backup courier']);
        LegalCorrectiveAction::create(['source_type' => 'compliance', 'source_id' => $compliance->id, 'title' => 'Confirm turnover band']);

        $this->assertCount(1, $risk->actions);
        $this->assertCount(1, $compliance->actions);
        $this->assertSame('Add backup courier', $risk->actions->first()->title);
    }

    public function test_overdue_detection(): void
    {
        $risk = $this->risk();
        $overdue = LegalCorrectiveAction::create([
            'source_type' => 'risk', 'source_id' => $risk->id,
            'title' => 'Late item', 'due_date' => now()->subWeek()->toDateString(),
        ]);
        $done = LegalCorrectiveAction::create([
            'source_type' => 'risk', 'source_id' => $risk->id,
            'title' => 'Finished item', 'due_date' => now()->subWeek()->toDateString(), 'status' => 'done',
        ]);

        $this->assertTrue($overdue->isOverdue());
        $this->assertFalse($done->isOverdue(), 'a completed action is never overdue');
    }

    public function test_registers_write_audit_rows(): void
    {
        $risk = $this->risk();
        $risk->update(['status' => 'mitigating']);

        $events = DB::table('legal_document_audits')->pluck('event');
        $this->assertContains('risk_created', $events);
        $this->assertContains('risk_updated', $events);
    }
}
