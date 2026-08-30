<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\RendersRecommerceViews;
use Tests\TestCase;

/**
 * The three standalone Recommerce documents -- the public certification page,
 * the public repair status page, and the print label -- carry no layout and are
 * reachable without a session, so a template that throws is customer-visible.
 * They are rendered here rather than asserted as source, and each is checked
 * for the disclosure limits its own copy promises.
 */
class RecommercePublicViewRenderTest extends TestCase
{
    use RendersRecommerceViews;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootRecommerceViewRendering();
    }

    public function test_the_certification_page_renders_the_masked_serial_and_never_the_raw_one(): void
    {
        $html = $this->render('recommerce::device.public-certification', ['profile' => [
            'device_name' => 'SaverBro Certified Handset',
            'masked_serial' => '••••••4321',
            'grade' => 'A',
            'qc_passed' => true,
            'battery_health_percent' => 92,
            'purchased_at' => Carbon::parse('2026-03-04'),
            'warranty_expires_at' => Carbon::parse('2027-03-04'),
            'warranty_active' => true,
            'warranty_service_url' => 'https://example.test/warranty',
        ]]);

        $this->assertStringContainsString('SaverBro Certified Handset', $html);
        $this->assertStringContainsString('••••••4321', $html);
        $this->assertStringContainsString('Valid until 04 Mar 2027', $html);
        $this->assertStringContainsString('Request Warranty Service', $html);
        $this->assertStringContainsString('noindex,nofollow,noarchive', $html);
    }

    public function test_the_certification_page_omits_the_serial_row_when_there_is_nothing_safe_to_show(): void
    {
        $html = $this->render('recommerce::device.public-certification', ['profile' => [
            'device_name' => 'SaverBro Certified Handset',
            'masked_serial' => null,
            'grade' => 'B',
            'qc_passed' => true,
            'battery_health_percent' => 80,
            'purchased_at' => Carbon::parse('2024-01-01'),
            'warranty_expires_at' => Carbon::parse('2024-06-01'),
            'warranty_active' => false,
            'warranty_service_url' => null,
        ]]);

        $this->assertStringNotContainsString('<dt>Serial</dt>', $html);
        $this->assertStringContainsString('Expired on 01 Jun 2024', $html);
        $this->assertStringNotContainsString('Request Warranty Service', $html);
    }

    public function test_the_public_repair_status_shows_only_the_summary_it_promises(): void
    {
        $html = $this->render('recommerce::repair.public-status', [
            'publicJob' => (object) [
                'job_code' => 'SB-RP-00000042',
                'state' => 'IN_REPAIR',
                'due_date' => '12 Sep 2026',
                'customer_facing_update' => 'Waiting on a replacement screen.',
            ],
            'deviceSummary' => ['category' => 'MOBILE', 'brand' => 'Fixture', 'model' => 'X1'],
        ]);

        $this->assertStringContainsString('SB-RP-00000042', $html);
        $this->assertStringContainsString('IN REPAIR', $html);
        $this->assertStringContainsString('MOBILE · Fixture · X1', $html);
        $this->assertStringContainsString('Waiting on a replacement screen.', $html);
        $this->assertStringContainsString('noindex,nofollow', $html);
    }

    public function test_the_public_repair_status_escapes_a_customer_facing_update(): void
    {
        $html = $this->render('recommerce::repair.public-status', [
            'publicJob' => (object) [
                'job_code' => 'SB-RP-00000043',
                'state' => 'READY',
                'due_date' => null,
                'customer_facing_update' => '<script>alert(1)</script>',
            ],
            'deviceSummary' => ['category' => 'MOBILE'],
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('Due date', $html);
    }

    public function test_the_device_label_prints_the_code_and_keeps_the_scan_target_opaque(): void
    {
        $html = $this->render('recommerce::labels.device', [
            'label' => [
                'device_code' => 'SB-DV-00000001-9',
                'safe_description' => 'SaverBro Demo Device · Default',
                'template_version' => 'LABEL-V1',
            ],
            'rendered' => [
                'qr_svg' => '<svg role="img" id="qr"></svg>',
                'code128_svg' => '<svg role="img" id="c128"></svg>',
            ],
        ]);

        $this->assertStringContainsString('SB-DV-00000001-9', $html);
        $this->assertStringContainsString('SaverBro Demo Device · Default', $html);
        $this->assertStringContainsString('<svg role="img" id="qr">', $html);
        $this->assertStringContainsString('QR destination is opaque and not printed as text', $html);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function render(string $view, array $data): string
    {
        return $this->renderRecommerceView($view, $data);
    }
}
