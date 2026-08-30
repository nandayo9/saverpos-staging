<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Recommerce feature boundary
    |--------------------------------------------------------------------------
    |
    | The module and all writes are disabled by default. Enabling the module
    | status alone must not expose a Recommerce route or mutation path.
    |
    */
    'name' => 'Recommerce',
    'enabled' => env('RECOMMERCE_ENABLED', false),
    'writes_enabled' => env('RECOMMERCE_WRITES_ENABLED', false),
    'resolver_host' => env('RECOMMERCE_RESOLVER_HOST'),
    // Public QR pages never infer an action URL from an untrusted request.
    // Configure one approved HTTPS customer-support endpoint before exposing
    // the warranty-service button.
    'public_warranty_service_url' => env('RECOMMERCE_PUBLIC_WARRANTY_SERVICE_URL'),
    'label_template_version' => 'alpha-1',
    'receive_batch_limit' => 50,
    'repair_intake_checklist' => [
        ['key' => 'powers_on', 'label' => 'Powers on'],
        ['key' => 'display', 'label' => 'Display / screen'],
        ['key' => 'buttons_ports', 'label' => 'Buttons and ports'],
        ['key' => 'camera_audio', 'label' => 'Camera and audio'],
        ['key' => 'physical_condition', 'label' => 'Physical condition recorded'],
        ['key' => 'accessories', 'label' => 'Accessories recorded'],
    ],
    'permissions' => [
        'recommerce.device.view',
        'recommerce.device.create',
        'recommerce.device.print_label',
        'recommerce.device.rotate_token',
        'recommerce.device.certify',
        'recommerce.device.sell',
        'recommerce.device.transfer',
        'recommerce.device.return',
        'recommerce.device.reverse_disposition',
        'recommerce.device.view_economics',
        'recommerce.receiving.prepare',
        'recommerce.receiving.post',
        'recommerce.stock.reconcile',
        'recommerce.stock.reconcile.record',
        'recommerce.audit.view',
        'recommerce.repair.view',
        'recommerce.repair.view_cost',
        'recommerce.repair.intake',
        'recommerce.repair.transition',
        'recommerce.diagnostic.view',
        'recommerce.diagnostic.submit',
        'recommerce.diagnostic.manage',
        'recommerce.repair.parts.reserve',
        'recommerce.repair.parts.use',
        'recommerce.repair.parts.resolve',
        'recommerce.repair.quote.manage',
        'recommerce.repair.billing',
        'recommerce.repair.collection',
        'recommerce.repair.collection.override',
        'recommerce.repair.archive',
        'recommerce.warranty.manage',
    ],
    'cohort' => [
        'business_id' => env('RECOMMERCE_COHORT_BUSINESS_ID'),
        'location_id' => env('RECOMMERCE_COHORT_LOCATION_ID'),
        // A transfer pilot may approve two explicit branches. The legacy
        // singular value remains the default for an initial one-branch pilot.
        'location_ids' => array_values(array_filter(
            array_map('intval', explode(',', (string) env('RECOMMERCE_COHORT_LOCATION_IDS', env('RECOMMERCE_COHORT_LOCATION_ID', '')))),
            fn (int $locationId): bool => $locationId > 0
        )),
        // Comma-separated POS variation IDs keep the pilot cohort explicit
        // without requiring a code/config deployment for each environment.
        'variation_ids' => array_values(array_filter(
            array_map('intval', explode(',', (string) env('RECOMMERCE_COHORT_VARIATION_IDS', ''))),
            fn (int $variationId): bool => $variationId > 0
        )),
    ],
];
