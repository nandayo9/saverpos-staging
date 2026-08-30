<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\RendersRecommerceViews;
use Tests\TestCase;

/**
 * A submitted diagnostic session is evidence, and the page says so: "The
 * recorded template and observations are immutable." It therefore has to read
 * its labels, units and order from the snapshot the session captured, not from
 * the raw observation rows — the published template it was filled against can
 * be retired or superseded afterwards.
 *
 * Before this was guarded the review listed raw check keys in row order, so a
 * reviewer saw `battery_health` where the technician had seen "Reported battery
 * health", in a different order from the form they filled.
 */
class RecommerceDiagnosticSessionRenderTest extends TestCase
{
    use RendersRecommerceViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRecommerceViewRendering();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_a_submitted_session_shows_snapshot_labels_not_raw_check_keys(): void
    {
        $html = $this->renderRecommerceView('recommerce::diagnostics.show', $this->payload());

        $this->assertStringContainsString('Powers on and reaches the home screen', $html);
        $this->assertStringContainsString('Reported battery health', $html);
        $this->assertStringNotContainsString('battery_health', $html);
        $this->assertStringNotContainsString('powers_on', $html);
    }

    public function test_observations_follow_the_snapshot_order_not_the_row_order(): void
    {
        $html = $this->renderRecommerceView('recommerce::diagnostics.show', $this->payload());

        $powersOn = strpos($html, 'Powers on and reaches the home screen');
        $battery = strpos($html, 'Reported battery health');
        $display = strpos($html, 'Display output across the full panel');

        $this->assertLessThan($battery, $powersOn, 'sort_order 1 must render before sort_order 2.');
        $this->assertLessThan($display, $battery, 'sort_order 2 must render before sort_order 3.');
    }

    public function test_a_numeric_observation_carries_the_unit_the_template_recorded(): void
    {
        $html = $this->renderRecommerceView('recommerce::diagnostics.show', $this->payload());

        $this->assertMatchesRegularExpression('/62\.0000\s*%/', $html);
    }

    /**
     * An observation whose check is absent from the snapshot must still render.
     * The snapshot is written once at session start, so a session recorded
     * before a check existed — or any gap — must degrade to the key rather than
     * dropping the row or throwing.
     */
    public function test_an_observation_missing_from_the_snapshot_falls_back_to_its_key(): void
    {
        $payload = $this->payload();
        $payload['diagnosticSession']->template_snapshot_json = ['checks' => []];

        $html = $this->renderRecommerceView('recommerce::diagnostics.show', $payload);

        $this->assertStringContainsString('powers_on', $html);
        $this->assertStringContainsString('battery_health', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        // Deliberately not in sort_order: the view must reorder them.
        $observations = collect([
            (object) ['check_key' => 'liquid_indicator', 'outcome' => 'NOT_APPLICABLE', 'value_numeric' => null, 'value_text' => 'Not visible'],
            (object) ['check_key' => 'battery_health', 'outcome' => 'RECORDED', 'value_numeric' => '62.0000', 'value_text' => null],
            (object) ['check_key' => 'powers_on', 'outcome' => 'PASS', 'value_numeric' => null, 'value_text' => 'Boots to home screen'],
            (object) ['check_key' => 'display_output', 'outcome' => 'PASS', 'value_numeric' => null, 'value_text' => null],
        ]);

        return [
            'job' => (object) [
                'job_code' => 'SB-RP-FIXTURE01',
                'state' => 'DIAGNOSIS',
                'device' => (object) ['device_code' => 'SB-DV-00000019-1', 'category_code' => 'LAPTOP'],
            ],
            'templates' => collect(),
            'canSubmit' => true,
            'variationId' => null,
            'diagnosticSession' => (object) [
                'id' => 1,
                'status' => 'SUBMITTED',
                'grade_code' => 'B',
                'observations' => $observations,
                'template_snapshot_json' => [
                    'checks' => [
                        ['check_key' => 'powers_on', 'label' => 'Powers on and reaches the home screen', 'unit' => null, 'sort_order' => 1],
                        ['check_key' => 'battery_health', 'label' => 'Reported battery health', 'unit' => '%', 'sort_order' => 2],
                        ['check_key' => 'display_output', 'label' => 'Display output across the full panel', 'unit' => null, 'sort_order' => 3],
                        ['check_key' => 'liquid_indicator', 'label' => 'Liquid damage indicator', 'unit' => null, 'sort_order' => 5],
                    ],
                ],
            ],
        ];
    }
}
