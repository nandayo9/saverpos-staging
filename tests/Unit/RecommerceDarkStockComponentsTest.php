<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The shared dark stylesheet is what stops stock Bootstrap components looking
 * like light islands. The first dark pass covered alert-info/warning/danger and
 * the primary/success buttons and labels, and left the rest on Bootstrap's
 * defaults -- white type on orange or pale blue. Rendered and measured on the
 * dark ground, those came out at 1.86-2.04:1, well under the 4.5:1 AA floor.
 */
class RecommerceDarkStockComponentsTest extends TestCase
{
    /** @dataProvider stockSelectors */
    public function test_shared_stylesheet_darkens_stock_component(string $selector): void
    {
        $css = file_get_contents(base_path('public/css/saverbro-dark-pos.css'));

        $this->assertMatchesRegularExpression(
            '/'.preg_quote($selector, '/').'\s*[,{]/',
            $css,
            $selector.' is left on its Bootstrap default, which is a light surface on a dark page.'
        );
    }

    public static function stockSelectors(): array
    {
        return array_map(fn (string $s): array => [$s], [
            '.btn-primary', '.btn-success', '.btn-warning', '.btn-info', '.btn-danger',
            '.label-primary', '.label-success', '.label-warning', '.label-info', '.label-danger', '.label-default',
            '.alert-info', '.alert-warning', '.alert-danger', '.alert-success',
            '.help-block', 'pre', 'code',
        ]);
    }
}
