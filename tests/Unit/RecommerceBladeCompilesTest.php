<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Tests\TestCase;

/**
 * Blade's compileStatements matches `\B@directive`, so a directive that follows
 * a word character -- including the "f" of a preceding `@endif` -- is emitted as
 * literal text rather than compiled. On these dense single-line templates that
 * is easy to write and invisible to a source-string assertion: it either leaks
 * "@else...@endif" into the page or, when the leftovers no longer balance,
 * fatals the screen with a PHP parse error. Both happened in this module.
 */
class RecommerceBladeCompilesTest extends TestCase
{
    /** @dataProvider recommerceViews */
    public function test_view_compiles_to_valid_php(string $relativePath): void
    {
        $compiled = $this->compile($relativePath);

        $file = tempnam(sys_get_temp_dir(), 'blade').'.php';
        file_put_contents($file, $compiled);
        $lint = (string) shell_exec(PHP_BINARY.' -l '.escapeshellarg($file).' 2>&1');
        @unlink($file);

        $this->assertStringContainsString('No syntax errors', $lint, $relativePath.' does not compile: '.trim($lint));
    }

    /** @dataProvider recommerceViews */
    public function test_view_leaves_no_uncompiled_directive(string $relativePath): void
    {
        $compiled = $this->compile($relativePath);

        preg_match_all(
            '/@(?:if|else|elseif|endif|foreach|endforeach|forelse|empty|endforelse|isset|endisset|unless|endunless)\b/',
            $compiled,
            $matches
        );

        $this->assertSame(
            [],
            array_values(array_unique($matches[0])),
            $relativePath.' leaves Blade directives uncompiled; separate them from the preceding word character.'
        );
    }

    public static function recommerceViews(): array
    {
        $root = dirname(__DIR__, 2).'/Modules/Recommerce/Resources/views';
        $cases = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $cases[$relative] = [$file->getPathname()];
            }
        }
        ksort($cases);

        return $cases;
    }

    private function compile(string $path): string
    {
        $compiler = new BladeCompiler(new Filesystem(), sys_get_temp_dir());

        return $compiler->compileString(file_get_contents($path));
    }
}
