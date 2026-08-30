<?php

namespace Tests\Unit;

use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\RendersRecommerceViews;
use Tests\TestCase;

/**
 * Renders the repair record rather than asserting its Blade source.
 *
 * The technician line was wrong for as long as the screen has existed and no
 * source assertion could have caught it: the view read `->name` on the assignee,
 * which Ultimate POS's `users` table does not have and `App\User` does not
 * expose, so every assigned job displayed "Assign later". It only surfaced once
 * the demo estate carried a job with a real technician on it.
 */
class RecommerceRepairRecordRenderTest extends TestCase
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

    public function test_an_assigned_technician_is_named_on_the_record(): void
    {
        $html = $this->renderRecommerceView('recommerce::repair.show', $this->payload([
            'assignee' => new User(['surname' => 'Demo', 'first_name' => 'SaverPOS', 'last_name' => 'Administrator']),
        ]));

        $this->assertStringContainsString('Demo SaverPOS Administrator', $html);
        $this->assertStringNotContainsString('Assign later', $html);
    }

    public function test_an_unassigned_job_still_says_assign_later(): void
    {
        $html = $this->renderRecommerceView('recommerce::repair.show', $this->payload(['assignee' => null]));

        $this->assertStringContainsString('Assign later', $html);
    }

    /**
     * A user whose name parts are all empty must not render as a run of spaces
     * that reads like a missing value; the accessor concatenates without
     * trimming, so a blank name has to fall back like a missing one.
     */
    public function test_a_technician_with_no_recorded_name_falls_back_instead_of_rendering_blank(): void
    {
        $html = $this->renderRecommerceView('recommerce::repair.show', $this->payload([
            'assignee' => new User(['surname' => null, 'first_name' => null, 'last_name' => null]),
        ]));

        $this->assertStringContainsString('Assign later', $html);
    }

    /**
     * Every in-app screen names the browser tab. Four record screens shipped
     * without a title section, so their tab read "- SAVERPOS" and a technician
     * with several jobs open could not tell them apart. Guarded as a class
     * rather than per view, since the next screen will inherit the same layout.
     */
    public function test_every_in_app_module_screen_titles_its_browser_tab(): void
    {
        $untitled = [];

        foreach (glob(base_path('Modules/Recommerce/Resources/views/*/*.blade.php')) as $path) {
            $source = file_get_contents($path);
            if (! str_contains($source, "@extends('layouts.app')")) {
                continue;
            }
            if (! str_contains($source, "@section('title'")) {
                $untitled[] = basename(dirname($path)).'/'.basename($path);
            }
        }

        $this->assertSame([], $untitled, 'These in-app screens render an empty browser tab title.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $job = (object) array_merge([
            'id' => 31,
            'job_code' => 'SB-RP-FIXTURE01',
            'state' => 'IN_REPAIR',
            'lock_version' => 3,
            'location_id' => 1,
            'priority' => 'URGENT',
            'access_status' => 'NO_LOCK',
            'reported_fault' => 'Powers on with sound but the screen stays black.',
            'cosmetic_condition' => 'Rear casing dented near the camera.',
            'estimated_quote_amount' => null,
            'warranty_json' => null,
            'due_at' => Carbon::parse('2026-08-31'),
            'opened_at' => Carbon::parse('2026-08-30 13:09:00'),
            'assignee' => null,
            'contact' => (object) ['name' => 'Demo Customer'],
            'device' => (object) [
                'device_code' => 'SB-DV-00000021-3',
                'category_code' => 'TABLET',
                'specifications_json' => ['brand' => 'SaverBro', 'model' => 'Demo Tablet T10'],
                'identifiers' => collect(),
            ],
            'checklistItems' => collect([
                (object) ['label' => 'Powers on', 'outcome' => 'PASS', 'notes' => null],
                (object) ['label' => 'Display / screen', 'outcome' => 'FAIL', 'notes' => null],
            ]),
            'stateTransitions' => collect([
                (object) ['from_state' => null, 'to_state' => 'RECEIVED', 'occurred_at' => Carbon::parse('2026-08-30 13:09:00')],
                (object) ['from_state' => 'RECEIVED', 'to_state' => 'DIAGNOSIS', 'occurred_at' => Carbon::parse('2026-08-30 13:09:00')],
            ]),
            'diagnosticSessions' => collect(),
            'partReservations' => collect(),
            'partUsages' => collect(),
            'quotes' => collect(),
        ], $overrides);

        return [
            'job' => $job,
            'allowedTransitions' => ['WAITING_PARTS', 'QC', 'READY'],
            'diagnosticViewEnabled' => true,
            'transitionEnabled' => true,
            'costVisible' => true,
            'financialEvidence' => ['sale' => null, 'payment_count' => 0, 'payment_total' => null],
            'collectionSummary' => null,
            'warrantyClaims' => collect(),
            'canClaimWarranty' => false,
            'canCollect' => false,
            'canStartRepeat' => false,
        ];
    }
}
