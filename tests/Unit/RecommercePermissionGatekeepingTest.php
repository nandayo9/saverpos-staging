<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The structural guard owed since RC-006: no Recommerce service or controller
 * may decide a permission on its own.
 *
 * Every authorisation decision belongs to `AuthorizationGate`, which is the only
 * place that intersects the permission with the tenant/location/variation cohort.
 * A service that checks `$user->can()` directly satisfies the permission half and
 * silently skips the cohort half — which is precisely the defect that kept RC-041
 * blocked, and it is invisible to a test that only exercises the happy path.
 *
 * This guard is deliberately structural. It reads the permission catalogue from
 * the module config file rather than `config()`, so a test elsewhere that
 * narrows `recommerce.permissions` cannot quietly weaken it.
 */
class RecommercePermissionGatekeepingTest extends TestCase
{
    /**
     * Ways PHP code can reach an authorisation answer without the gate.
     * `AuthorizationGate` itself lives in Support/ and is never scanned here.
     */
    private const BYPASS_PRIMITIVES = [
        '->can(',
        '->cannot(',
        'Gate::allows',
        'Gate::denies',
        'Gate::authorize',
        'Gate::check',
        'hasPermissionTo',
        'hasAnyPermission',
        'hasDirectPermission',
    ];

    /**
     * Native Ultimate POS permissions a Recommerce class may check inline.
     *
     * These are not Recommerce permissions and are deliberately NOT routed
     * through `AuthorizationGate`: the gate answers "may this user act on this
     * cohort", and it denies anything outside `recommerce.permissions`, so
     * passing a native permission to it would always deny. A native check
     * guards a native side effect — here, creating an Ultimate POS contact.
     *
     * The allowlist exists so these cannot proliferate silently. Adding an
     * entry is a deliberate act with a reason attached.
     */
    private const ALLOWED_NATIVE_PERMISSIONS = [
        // TradeInSellerService registers a walk-in seller as a POS customer.
        'customer.create',
    ];

    /**
     * `DataController::user_permissions()` names every catalogued permission
     * because it renders the labels for the native role editor. It decides
     * nothing, which the first test proves rather than assumes.
     */
    private const LABEL_ONLY_FILES = ['DataController.php'];

    /**
     * The invariant is about *Recommerce* authorisation. An inline check of a
     * catalogued Recommerce permission is the RC-041 defect: it satisfies the
     * permission half and silently skips the cohort half. An inline check of a
     * native POS permission is a different thing and is allowlisted above.
     *
     * A non-literal argument — `->can($permission)` — is always an offender,
     * because nothing here can tell which half it is, and it would otherwise be
     * the obvious way around this guard.
     */
    public function test_no_service_or_controller_decides_a_recommerce_permission_inline(): void
    {
        $catalogue = $this->cataloguedPermissions();
        $this->assertGreaterThan(30, count($catalogue), 'The permission catalogue failed to load.');

        $offenders = [];

        foreach ($this->scannedFiles() as $path) {
            $source = file_get_contents($path);
            foreach (self::BYPASS_PRIMITIVES as $primitive) {
                $offset = 0;
                while (($at = strpos($source, $primitive, $offset)) !== false) {
                    $offset = $at + strlen($primitive);
                    $argument = $this->firstStringArgument(substr($source, $at, 200));
                    $name = basename($path);

                    if ($argument === null) {
                        $offenders[] = $name.' calls '.$primitive.' with a non-literal permission';
                        continue;
                    }
                    if (in_array($argument, $catalogue, true)) {
                        $offenders[] = $name.' decides Recommerce permission '.$argument.' inline';
                        continue;
                    }
                    if (! in_array($argument, self::ALLOWED_NATIVE_PERMISSIONS, true)) {
                        $offenders[] = $name.' checks undocumented native permission '.$argument;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Recommerce authorisation must be decided by AuthorizationGate, not inline.');
    }

    /**
     * Every allowlisted native permission must still be a real Ultimate POS
     * permission that stock code itself checks. An entry that matches nothing
     * in `app/` is either a typo or a Recommerce permission smuggled in.
     */
    public function test_each_allowlisted_native_permission_is_a_real_pos_permission(): void
    {
        $catalogue = $this->cataloguedPermissions();

        foreach (self::ALLOWED_NATIVE_PERMISSIONS as $permission) {
            $this->assertNotContains(
                $permission,
                $catalogue,
                $permission.' is a Recommerce permission and must go through the gate, not the native allowlist.'
            );

            $found = false;
            foreach ((glob(base_path('app/*.php')) ?: []) + (glob(base_path('app/Http/Middleware/*.php')) ?: []) as $file) {
                if (str_contains((string) file_get_contents($file), "'".$permission."'")) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, $permission.' is not a permission stock Ultimate POS code checks.');
        }
    }

    /**
     * Extract the first single-quoted argument from a call fragment, or null
     * when the argument is not a literal.
     */
    private function firstStringArgument(string $fragment): ?string
    {
        $open = strpos($fragment, '(');
        if ($open === false) {
            return null;
        }
        $rest = ltrim(substr($fragment, $open + 1));

        return preg_match("/^'([^']*)'/", $rest, $m) === 1 ? $m[1] : null;
    }

    public function test_everything_that_names_a_catalogued_permission_holds_the_gate(): void
    {
        $permissions = $this->cataloguedPermissions();
        $this->assertGreaterThan(30, count($permissions), 'The permission catalogue failed to load.');

        $offenders = [];
        foreach ($this->scannedFiles() as $path) {
            if (in_array(basename($path), self::LABEL_ONLY_FILES, true)) {
                continue;
            }
            $source = file_get_contents($path);
            $named = array_filter($permissions, fn (string $p): bool => str_contains($source, "'".$p."'"));
            if ($named !== [] && ! str_contains($source, 'AuthorizationGate')) {
                $offenders[] = basename($path).' names '.count($named).' permission(s)';
            }
        }

        $this->assertSame([], $offenders, 'A class naming a permission must route the decision through AuthorizationGate.');
    }

    /**
     * The exemption above is only safe while the label-only files really are
     * label-only. If one ever starts deciding access, the first test catches the
     * primitive and this one records why the exemption existed.
     */
    public function test_the_label_only_exemption_covers_files_that_decide_nothing(): void
    {
        foreach (self::LABEL_ONLY_FILES as $name) {
            $path = base_path('Modules/Recommerce/Http/Controllers/'.$name);
            $this->assertFileExists($path);
            $source = file_get_contents($path);
            foreach (self::BYPASS_PRIMITIVES as $primitive) {
                $this->assertStringNotContainsString($primitive, $source, $name.' is exempt only because it decides nothing.');
            }
            $this->assertStringNotContainsString('AuthorizationException', $source, $name.' must not refuse access either.');
        }
    }

    /**
     * Reading the catalogue is how a class would re-implement the gate's own
     * permission half. Only the gate, the role-editor labels, and the migrations
     * that register the rows may do it.
     */
    public function test_only_the_gate_and_the_role_editor_read_the_permission_catalogue(): void
    {
        $allowed = ['AuthorizationGate.php', 'DataController.php'];
        $offenders = [];

        foreach ($this->moduleFiles() as $path) {
            if (str_contains($path, '/Database/Migrations/')) {
                continue; // Registration migrations create the rows from the catalogue.
            }
            if (in_array(basename($path), $allowed, true)) {
                continue;
            }
            if (str_contains((string) file_get_contents($path), "config('recommerce.permissions'")) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame([], $offenders, 'Only AuthorizationGate may read the permission catalogue to decide access.');
    }

    /** @return array<int, string> */
    private function scannedFiles(): array
    {
        return array_merge(
            glob(base_path('Modules/Recommerce/Services/*.php')) ?: [],
            glob(base_path('Modules/Recommerce/Http/Controllers/*.php')) ?: []
        );
    }

    /** @return array<int, string> */
    private function moduleFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('Modules/Recommerce')));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Read from the module config file, not `config()`: a test that narrows
     * `recommerce.permissions` must not be able to shrink what this guard checks.
     *
     * @return array<int, string>
     */
    private function cataloguedPermissions(): array
    {
        $config = require base_path('Modules/Recommerce/Config/config.php');

        return array_values((array) ($config['permissions'] ?? []));
    }
}
