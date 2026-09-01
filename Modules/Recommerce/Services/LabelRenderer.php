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
            // A 64-character opaque token plus the resolver URL produces a
            // 53-module QR at H correction. On the 50 × 20 mm thermal label
            // that compressed each module to about 0.32 mm, which phones
            // cannot decode reliably. M yields 41 modules while retaining
            // practical physical-label recovery and the exact same QR value.
            $qrSvg = app('DNS2D')->getBarcodeSVG($qrUrl, 'QRCODE,M', 4, 4, '#111827');
        } catch (\Throwable $exception) {
            throw new LogicException('Label renderer is unavailable.', 0, $exception);
        }

        if (! is_string($code128Svg) || ! str_contains($code128Svg, '<svg')
            || ! is_string($qrSvg) || ! str_contains($qrSvg, '<svg')
            || str_contains($code128Svg, $qrUrl)
            || str_contains($qrSvg, $qrUrl)) {
            throw new LogicException('Label renderer returned an invalid image.');
        }

        $qrSvg = $this->withQuietZone($qrSvg);

        return [
            'code128_svg' => $code128Svg,
            'qr_svg' => $qrSvg,
        ];
    }

    /**
     * QR readers require clear space on every side of the symbol. DNS2D
     * places modules at the SVG edge, so reserve four module widths in the
     * viewBox without modifying the encoded QR value or its black modules.
     */
    private function withQuietZone(string $svg): string
    {
        if (preg_match('/<svg width="(\d+)" height="\1"/', $svg, $matches) !== 1) {
            throw new LogicException('QR renderer returned an unexpected canvas.');
        }

        $canvas = (int) $matches[1];
        $quietZone = 16; // Four 4-unit QR modules, per ISO/IEC QR guidance.
        $viewBoxSize = $canvas + ($quietZone * 2);
        $replacement = '<svg width="'.$canvas.'" height="'.$canvas.'" viewBox="-'.$quietZone.' -'.$quietZone.' '.$viewBoxSize.' '.$viewBoxSize.'"';

        return preg_replace('/<svg width="\d+" height="\d+"/', $replacement, $svg, 1) ?? $svg;
    }
}
