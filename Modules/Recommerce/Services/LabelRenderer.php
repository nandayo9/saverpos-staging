<?php

namespace Modules\Recommerce\Services;

use LogicException;

/**
 * Render one print-ready device label from the already-issued safe payload.
 * The opaque QR value is encoded into SVG geometry and is never printed as
 * human-readable text or returned as a separate field by this renderer.
 */
class LabelRenderer
{
    public function render(array $payload): array
    {
        $code = $payload['code128_value'] ?? null;
        $qrUrl = $payload['qr_url'] ?? null;

        if (! is_string($code) || $code === '' || ! is_string($qrUrl) || $qrUrl === '') {
            throw new LogicException('Label payload is incomplete.');
        }

        try {
            $code128Svg = app('DNS1D')->getBarcodeSVG($code, 'C128', 2, 42, '#111827', false, true);
            $qrSvg = app('DNS2D')->getBarcodeSVG($qrUrl, 'QRCODE,H', 4, 4, '#111827');
        } catch (\Throwable $exception) {
            throw new LogicException('Label renderer is unavailable.', 0, $exception);
        }

        if (! is_string($code128Svg) || ! str_contains($code128Svg, '<svg')
            || ! is_string($qrSvg) || ! str_contains($qrSvg, '<svg')
            || str_contains($code128Svg, $qrUrl)
            || str_contains($qrSvg, $qrUrl)) {
            throw new LogicException('Label renderer returned an invalid image.');
        }

        return [
            'code128_svg' => $code128Svg,
            'qr_svg' => $qrSvg,
        ];
    }
}
