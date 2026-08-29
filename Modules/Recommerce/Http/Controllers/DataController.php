<?php

namespace Modules\Recommerce\Http\Controllers;

use Illuminate\Routing\Controller;

/**
 * Exposes named Recommerce permissions to Ultimate POS's native role editor.
 * ModuleUtil calls this only for installed modules; it does not grant any
 * permission or bypass the Recommerce cohort gates.
 */
class DataController extends Controller
{
    public function user_permissions(): array
    {
        $labels = [
            'recommerce.device.view' => 'View Recommerce devices',
            'recommerce.device.create' => 'Create Recommerce devices',
            'recommerce.device.print_label' => 'Print Recommerce labels',
            'recommerce.device.rotate_token' => 'Rotate Recommerce label tokens',
            'recommerce.receiving.prepare' => 'Prepare tracked receiving',
            'recommerce.receiving.post' => 'Post tracked receiving',
            'recommerce.stock.reconcile' => 'View stock reconciliation',
            'recommerce.stock.reconcile.record' => 'Record reconciliation evidence',
            'recommerce.audit.view' => 'View Recommerce audit trail',
            'recommerce.repair.view' => 'View repair workbench',
            'recommerce.repair.view_cost' => 'View repair financial evidence',
            'recommerce.repair.intake' => 'Create customer repair and internal refurbishment',
            'recommerce.repair.transition' => 'Transition repair states',
            'recommerce.diagnostic.view' => 'View diagnostics',
            'recommerce.diagnostic.submit' => 'Submit diagnostics',
            'recommerce.repair.parts.reserve' => 'Reserve repair parts',
            'recommerce.repair.parts.use' => 'Issue and install repair parts',
            'recommerce.repair.parts.resolve' => 'Resolve repair part consumption',
            'recommerce.repair.quote.manage' => 'Create and approve repair quotes',
            'recommerce.repair.collection' => 'Collect customer repair devices',
            'recommerce.repair.collection.override' => 'Override unpaid repair collection',
            'recommerce.repair.billing' => 'Bill customer repair through POS',
        ];

        return collect(config('recommerce.permissions', []))
            ->map(fn (string $permission): array => [
                'name' => $permission,
                'label' => $labels[$permission] ?? $permission,
            ])
            ->values()
            ->all();
    }
}
