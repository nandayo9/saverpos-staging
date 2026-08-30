<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A placeholder is not an accessible name: it disappears the moment someone
 * types, and it is not reliably announced. Six controls in this module had
 * nothing else -- three POS-sale-id fields and two reason fields on the parts
 * workbench, and the customer search box on repair intake, whose visible label
 * pointed at the select next to it rather than at the search input.
 */
class RecommerceFormLabellingTest extends TestCase
{
    /** @dataProvider moduleViews */
    public function test_every_visible_form_control_has_an_accessible_name(string $path, string $relative): void
    {
        // Blade expressions contain `->`, which would end an attribute match
        // early and produce phantom findings, so neutralise them first.
        $source = file_get_contents($path);
        $scan = preg_replace(['/\{!!.*?!!\}/s', '/\{\{.*?\}\}/s'], 'BLADE', $source);
        $missing = [];

        preg_match_all('/<(input|select|textarea)\b([^>]*)>/i', $scan, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            [$whole, $offset] = $tag[0];
            $attrs = $tag[2][0];

            if (preg_match('/type\s*=\s*"(hidden|submit|button|reset)"/i', $attrs)) {
                continue;
            }
            if (preg_match('/aria-label\s*=|aria-labelledby\s*=|title\s*=/i', $attrs)) {
                continue;
            }
            // A control wrapped in its own <label> is associated implicitly.
            if ($this->isWrappedInLabel($scan, $offset)) {
                continue;
            }

            if (! preg_match('/\bid\s*=\s*"([^"]+)"/i', $attrs, $id)) {
                $missing[] = trim(substr($whole, 0, 70)).' (no id, no aria-label)';

                continue;
            }

            if (! str_contains($scan, 'for="'.$id[1].'"')) {
                $missing[] = 'id="'.$id[1].'" has no <label for>';
            }
        }

        $this->assertSame([], $missing, $relative.' has form controls without an accessible name.');
    }

    public static function moduleViews(): array
    {
        $root = dirname(__DIR__, 2).'/Modules/Recommerce/Resources/views';
        $cases = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $cases[$relative] = [$file->getPathname(), $relative];
            }
        }
        ksort($cases);

        return $cases;
    }

    /** True when the nearest preceding <label> has not been closed yet. */
    private function isWrappedInLabel(string $scan, int $offset): bool
    {
        $before = substr($scan, 0, $offset);
        $open = strripos($before, '<label');

        return $open !== false && stripos($before, '</label>', $open) === false;
    }
}
