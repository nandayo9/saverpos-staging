<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Recommerce screens ship their own <style> blocks, and the shared dark
 * stylesheet has no rules for the module's own classes -- it was written for
 * stock POS surfaces. So a module screen that hardcodes a light surface renders
 * as a white slab inside the dark POS chrome, and nothing else catches it.
 *
 * In-app screens must take their colours from the --sb-* palette. The three
 * standalone documents are exempt: the public certification page, the public
 * status page and the print label carry no layout and no shared stylesheet, and
 * the label in particular has to stay light because it prints on white stock.
 */
class RecommerceDarkPaletteTest extends TestCase
{
    private const STANDALONE = [
        'device/public-certification.blade.php',
        'repair/public-status.blade.php',
        'labels/device.blade.php',
    ];

    /**
     * The shared tone partial is exempt from the palette rule on purpose: its
     * five pairs are semantic hues (indigo, blue, amber, emerald, slate) that
     * the --sb-* palette does not carry, and each is a self-contained
     * ground/type pair chosen for measured contrast, with a light variant for
     * print. Pointing them at palette tokens would mean inventing tokens.
     */
    private const PALETTE_EXEMPT = [
        'partials/status-tones.blade.php',
    ];

    /** @dataProvider inAppViews */
    public function test_in_app_view_takes_its_colours_from_the_shared_palette(string $path, string $relative): void
    {
        if (in_array($relative, self::PALETTE_EXEMPT, true)) {
            $this->addToAssertionCount(1);

            return;
        }

        $screen = $this->screenStyles(file_get_contents($path));

        if (! preg_match('/(background|color|border[a-z-]*)\s*:/i', $screen)) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertMatchesRegularExpression(
            '/var\(--sb-[a-z-]+/',
            $screen,
            $relative.' styles its own surfaces but never references the shared --sb-* palette.'
        );
    }

    /** @dataProvider inAppViews */
    public function test_in_app_view_does_not_paint_a_light_surface_on_screen(string $path, string $relative): void
    {
        $screen = $this->screenStyles(file_get_contents($path));

        preg_match_all('/background\s*:\s*(#(?:fff|ffffff|f8fafc|f9fbfd|fbfdff|f1f5f9|eef4ff)\b)/i', $screen, $matches);

        $this->assertSame(
            [],
            array_values(array_unique($matches[1])),
            $relative.' paints a light background outside a print block; use a --sb-* surface token.'
        );
    }

    public static function inAppViews(): array
    {
        $root = dirname(__DIR__, 2).'/Modules/Recommerce/Resources/views';
        $cases = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($relative, self::STANDALONE, true)) {
                continue;
            }
            $cases[$relative] = [$file->getPathname(), $relative];
        }
        ksort($cases);

        return $cases;
    }

    /** Everything except the print block, which is meant to be light. */
    private function screenStyles(string $source): string
    {
        return (string) preg_replace('/@media\s+print\s*\{.*?\n\s*\}/s', '', $source);
    }
}
