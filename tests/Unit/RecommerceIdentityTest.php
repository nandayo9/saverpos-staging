<?php

namespace Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Modules\Recommerce\Support\Identity\DeviceCode;
use Modules\Recommerce\Support\Identity\OpaqueScanToken;
use Modules\Recommerce\Support\Identity\ScanInput;
use Modules\Recommerce\Support\Identity\StrongIdentifierHasher;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceIdentifier;
use Modules\Recommerce\Services\LabelRenderer;
use Modules\Recommerce\Support\LabelPayloadBuilder;
use Tests\TestCase;

class RecommerceIdentityTest extends TestCase
{
    public function test_device_code_is_stable_and_checkable()
    {
        $code = DeviceCode::forDeviceId(1);

        $this->assertSame('SB-DV-00000001-9', $code);
        $this->assertTrue(DeviceCode::isValid($code));
        $this->assertTrue(DeviceCode::isValid(' sb-dv-00000001-9 '));
        $this->assertFalse(DeviceCode::isValid('SB-DV-00000001-8'));
        $this->assertFalse(DeviceCode::isValid('SB-DV-1-9'));
    }

    public function test_scan_token_is_opaque_and_hash_matches_only_original()
    {
        config(['app.key' => 'test-only-app-key']);

        $service = new OpaqueScanToken();
        $rawToken = $service->issue();
        $storedHash = $service->hash($rawToken);

        $this->assertSame(64, strlen($rawToken));
        $this->assertSame(64, strlen($storedHash));
        $this->assertTrue(ctype_xdigit($rawToken));
        $this->assertTrue($service->matches($rawToken, $storedHash));
        $this->assertFalse($service->matches(str_repeat('a', 64), $storedHash));
        $this->assertFalse($service->matches('malformed-scan-input', $storedHash));
        $this->assertFalse($service->matches($rawToken, 'malformed-stored-hash'));
    }

    public function test_scan_input_accepts_only_code_or_approved_https_qr_path()
    {
        config(['recommerce.resolver_host' => 'scan.saverbro.example']);

        $this->assertSame(
            ['type' => 'DEVICE_CODE', 'value' => 'SB-DV-00000001-9'],
            ScanInput::parse('SB-DV-00000001-9')
        );
        $this->assertSame(
            ['type' => 'DEVICE_TOKEN', 'value' => str_repeat('a', 64)],
            ScanInput::parse('https://scan.saverbro.example/s/d/'.str_repeat('a', 64))
        );
        $this->assertNull(ScanInput::parse('http://scan.saverbro.example/s/d/'.str_repeat('a', 64)));
        $this->assertNull(ScanInput::parse('https://other.example/s/d/'.str_repeat('a', 64)));
        $this->assertNull(ScanInput::parse('https://user:pass@scan.saverbro.example/s/d/'.str_repeat('a', 64)));
        $this->assertNull(ScanInput::parse('https://scan.saverbro.example/s/d/'.str_repeat('a', 64).'?next=1'));
        $this->assertNull(ScanInput::parse("SB-DV-00000001-9\x00"));

        config(['recommerce.resolver_host' => '127.0.0.1:8001']);
        $this->assertSame(
            ['type' => 'DEVICE_TOKEN', 'value' => str_repeat('b', 64)],
            ScanInput::parse('https://127.0.0.1:8001/s/d/'.str_repeat('b', 64))
        );
        $this->assertNull(ScanInput::parse('https://127.0.0.1:8002/s/d/'.str_repeat('b', 64)));
    }

    public function test_label_payload_contains_only_safe_print_fields()
    {
        config([
            'app.key' => 'test-only-app-key',
            'recommerce.resolver_host' => 'scan.saverbro.example',
        ]);

        $device = Device::unguarded(function () {
            return new Device([
                'device_code' => 'SB-DV-00000001-9',
                'stock_participation' => 'ON_HAND',
            ]);
        });
        $rawToken = (new OpaqueScanToken())->issue();
        $payload = (new LabelPayloadBuilder())->forDevice($device, $rawToken);

        $this->assertSame('SB-DV-00000001-9', $payload['code128_value']);
        $this->assertStringStartsWith('https://scan.saverbro.example/s/d/', $payload['qr_url']);
        $this->assertArrayNotHasKey('raw_token', $payload);
        $this->assertArrayNotHasKey('token_hash', $payload);
        $this->assertArrayNotHasKey('manufacturer_serial', $payload);
    }

    public function test_label_description_is_sanitized_and_bounded()
    {
        config([
            'app.key' => 'test-only-app-key',
            'recommerce.resolver_host' => 'scan.saverbro.example',
        ]);

        $device = Device::unguarded(function () {
            return new Device([
                'device_code' => 'SB-DV-00000001-9',
                'stock_participation' => 'ON_HAND',
            ]);
        });
        $product = new \App\Product();
        $product->name = "Refurbished\x00 laptop\n".str_repeat('x', 100);
        $device->setRelation('product', $product);

        $payload = (new LabelPayloadBuilder())->forDevice(
            $device,
            (new OpaqueScanToken())->issue()
        );

        $this->assertSame(80, strlen($payload['safe_description']));
        $this->assertStringNotContainsString("\x00", $payload['safe_description']);
        $this->assertStringNotContainsString("\n", $payload['safe_description']);
    }

    public function test_label_payload_rejects_invalid_token_material()
    {
        config(['recommerce.resolver_host' => 'scan.saverbro.example']);
        $device = Device::unguarded(fn () => new Device(['device_code' => 'SB-DV-00000001-9']));

        $this->expectException(InvalidArgumentException::class);
        (new LabelPayloadBuilder())->forDevice($device, 'not-a-token');
    }

    public function test_label_renderer_rejects_svg_that_echoes_the_opaque_qr_url()
    {
        $payload = [
            'code128_value' => 'SB-DV-00000001-9',
            'qr_url' => 'https://scan.saverbro.example/s/d/'.str_repeat('a', 64),
        ];

        app()->instance('DNS1D', new class {
            public function getBarcodeSVG(): string
            {
                return '<svg><rect /></svg>';
            }
        });
        app()->instance('DNS2D', new class {
            public function getBarcodeSVG(string $value): string
            {
                return '<svg data-value="'.$value.'"></svg>';
            }
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('invalid image');

        (new LabelRenderer())->render($payload);
    }

    public function test_label_payload_requires_resolver_configuration()
    {
        config(['recommerce.resolver_host' => null]);
        $device = Device::unguarded(fn () => new Device(['device_code' => 'SB-DV-00000001-9']));

        $this->expectException(InvalidArgumentException::class);
        (new LabelPayloadBuilder())->forDevice($device, (new OpaqueScanToken())->issue());
    }

    public function test_label_payload_rejects_invalid_device_code()
    {
        config(['recommerce.resolver_host' => 'scan.saverbro.example']);
        $device = Device::unguarded(fn () => new Device(['device_code' => 'SB-DV-00000001-8']));

        $this->expectException(InvalidArgumentException::class);
        (new LabelPayloadBuilder())->forDevice($device, (new OpaqueScanToken())->issue());
    }

    public function test_strong_identifiers_normalize_and_hash_without_storing_raw_value()
    {
        config(['app.key' => 'test-only-app-key']);

        $normalized = StrongIdentifierHasher::normalize(' sn-01_a ');

        $this->assertSame('SN01A', $normalized);
        $this->assertSame(
            StrongIdentifierHasher::hash($normalized),
            StrongIdentifierHasher::hash('SN01A')
        );
        $this->assertSame(64, strlen(StrongIdentifierHasher::hash($normalized)));
    }

    public function test_strong_identifiers_reject_control_characters()
    {
        $this->expectException(InvalidArgumentException::class);

        StrongIdentifierHasher::normalize("SN-01\x00");
    }

    public function test_strong_identifiers_reject_empty_or_oversized_normalized_values()
    {
        try {
            StrongIdentifierHasher::normalize('---');
            $this->fail('Expected separator-only identifiers to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('outside the supported format', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        StrongIdentifierHasher::normalize(str_repeat('x', 256));
    }

    public function test_device_identifier_raw_value_is_encrypted_at_rest_and_hidden()
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $identifier = new DeviceIdentifier();
        $identifier->raw_value_encrypted = 'SN-SECRET-001';

        $this->assertSame('SN-SECRET-001', $identifier->raw_value_encrypted);
        $this->assertNotSame('SN-SECRET-001', $identifier->getRawOriginal('raw_value_encrypted'));
        $this->assertArrayNotHasKey('raw_value_encrypted', $identifier->toArray());
    }
}
