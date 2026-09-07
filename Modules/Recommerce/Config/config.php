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
    'module_version' => '1.0.0',
    'enabled' => env('RECOMMERCE_ENABLED', false),
    'writes_enabled' => env('RECOMMERCE_WRITES_ENABLED', false),
    'resolver_host' => env('RECOMMERCE_RESOLVER_HOST'),
    // Staging-only, server-to-server customer listing projection. There is no
    // default token, cohort or production fallback; all conditions must be
    // explicitly configured before the API exposes any data.
    'customer_projection' => [
        'enabled' => env('RECOMMERCE_CUSTOMER_PROJECTION_ENABLED', false),
        'bearer_token' => env('RECOMMERCE_CUSTOMER_PROJECTION_BEARER_TOKEN'),
        'contract_version' => env('RECOMMERCE_CUSTOMER_PROJECTION_CONTRACT_VERSION', '1.0'),
        'business_id' => env('RECOMMERCE_CUSTOMER_PROJECTION_BUSINESS_ID'),
        'location_ids' => array_values(array_filter(
            array_map('intval', explode(',', (string) env('RECOMMERCE_CUSTOMER_PROJECTION_LOCATION_IDS', ''))),
            fn (int $locationId): bool => $locationId > 0
        )),
        'variation_ids' => array_values(array_filter(
            array_map('intval', explode(',', (string) env('RECOMMERCE_CUSTOMER_PROJECTION_VARIATION_IDS', ''))),
            fn (int $variationId): bool => $variationId > 0
        )),
        'currency' => env('RECOMMERCE_CUSTOMER_PROJECTION_CURRENCY', 'MYR'),
    ],
    // Staging-only server-to-server website integration. This credential may
    // submit/link an intake, read its allowlisted projection, and record an
    // exact customer decision. It cannot approve an offer or call a generic
    // purchase/Device command; native completion occurs only inside SAVERPOS.
    'tradein_acquisition_command' => [
        'enabled' => env('RECOMMERCE_TRADEIN_COMMAND_ENABLED', false),
        'bearer_token' => env('RECOMMERCE_TRADEIN_COMMAND_BEARER_TOKEN'),
        'contract_version' => env('RECOMMERCE_TRADEIN_COMMAND_CONTRACT_VERSION', '1.0'),
        'actor_user_id' => env('RECOMMERCE_TRADEIN_COMMAND_ACTOR_USER_ID'),
        'business_id' => env('RECOMMERCE_TRADEIN_COMMAND_BUSINESS_ID'),
        'location_ids' => array_values(array_filter(
            array_map('intval', explode(',', (string) env('RECOMMERCE_TRADEIN_COMMAND_LOCATION_IDS', ''))),
            fn (int $locationId): bool => $locationId > 0
        )),
        'variation_ids' => array_values(array_filter(
            array_map('intval', explode(',', (string) env('RECOMMERCE_TRADEIN_COMMAND_VARIATION_IDS', ''))),
            fn (int $variationId): bool => $variationId > 0
        )),
    ],
    'tradein_website_evidence' => [
        'base_url' => env('RECOMMERCE_TRADEIN_WEBSITE_BASE_URL'),
        'bearer_token' => env('RECOMMERCE_TRADEIN_WEBSITE_EVIDENCE_TOKEN'),
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', strtolower((string) env('RECOMMERCE_TRADEIN_WEBSITE_ALLOWED_HOSTS', '')))))),
    ],
    // One SAVER Value implementation lives in SAVERPOS. Website requests use
    // the authenticated indicative endpoint and retain its immutable result.
    'saver_value' => [
        'engine_version' => 'SAVER-VALUE-ENGINE-1.0',
        'policy' => [
            'version' => 'SAVER-VALUE-POLICY-1.0',
            'status' => 'PROVISIONAL_MANAGEMENT_POLICY',
            'target_margin_percent' => 0.22,
            'minimum_margin_minor' => 25000,
            'warranty_reserve_minor' => 8000,
            'logistics_handling_minor' => 4500,
            'inventory_risk_minor' => 5000,
            'minimum_offer_minor' => 5000,
            'maximum_acquisition_ratio' => 1.0,
            'valid_days' => 7,
            'range_width_minor' => ['HIGH' => 8000, 'MEDIUM' => 13000, 'LOW' => 20000],
            'automatic_quote_min_confidence' => 60,
            'automatic_categories' => ['LAPTOP'],
            'condition_deductions_minor' => [
                'power' => ['intermittent' => 18000, 'no' => 40000, 'not_sure' => 12000],
                'screen' => ['minor' => 3500, 'lines' => 16000, 'cracked' => 28000, 'not_working' => 38000],
                'battery' => ['short' => 7000, 'plugged' => 14000, 'missing' => 18000, 'not_sure' => 6000],
                'physical' => ['good' => 2500, 'fair' => 7500, 'poor' => 18000],
            ],
            'charger_missing_minor' => 5000,
            'inspection_deductions_minor' => [
                'battery_below_80' => 6000,
                'battery_below_60' => 10000,
                'screen_fault' => 18000,
                'ports_fault' => 7000,
                'charger_missing' => 5000,
            ],
        ],
        'approved_references' => [
            'DISC-MODEL-T14-G2' => ['reference_id' => 'APR-T14G2-2026-09', 'amount_minor' => 150000, 'observed_at' => '2026-09-04'],
            'DISC-MODEL-ASPIRE-5-14' => ['reference_id' => 'APR-A514-2026-09', 'amount_minor' => 105000, 'observed_at' => '2026-09-04'],
            'DISC-MODEL-OPTIPLEX-7090' => ['reference_id' => 'APR-O7090-2026-09', 'amount_minor' => 185000, 'observed_at' => '2026-09-04'],
            'DISC-MODEL-IPAD-9' => ['reference_id' => 'APR-IPAD9-2026-09', 'amount_minor' => 105000, 'observed_at' => '2026-09-04'],
            'DISC-MODEL-GALAXY-S22' => ['reference_id' => 'APR-S22-2026-09', 'amount_minor' => 150000, 'observed_at' => '2026-09-04'],
            'DISC-MODEL-THINKSTATION-P350' => ['reference_id' => 'APR-P350-2026-09', 'amount_minor' => 290000, 'observed_at' => '2026-09-04'],
        ],
    ],
    'tradein_outbox' => [
        'enabled' => env('RECOMMERCE_TRADEIN_OUTBOX_ENABLED', false),
        'website_url' => env('RECOMMERCE_TRADEIN_OUTBOX_WEBSITE_URL'),
        'hmac_secret' => env('RECOMMERCE_TRADEIN_OUTBOX_HMAC_SECRET'),
        // Staging keeps the website behind HTTP Basic Auth. This credential
        // only crosses that perimeter; the HMAC headers remain the application
        // authentication and replay protection for the projection endpoint.
        'website_basic_authorization' => env('RECOMMERCE_TRADEIN_OUTBOX_WEBSITE_BASIC_AUTH'),
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', strtolower((string) env('RECOMMERCE_TRADEIN_OUTBOX_ALLOWED_HOSTS', '')))))),
        // Disposable local verification only; non-loopback HTTP remains denied.
        'allow_insecure_local' => env('RECOMMERCE_TRADEIN_OUTBOX_ALLOW_INSECURE_LOCAL', false),
        'timeout_seconds' => 10,
        'signature_ttl_seconds' => 300,
    ],
    // Public QR pages never infer an action URL from an untrusted request.
    // Configure one approved HTTPS customer-support endpoint before exposing
    // the warranty-service button.
    'public_warranty_service_url' => env('RECOMMERCE_PUBLIC_WARRANTY_SERVICE_URL'),
    // v2.2-3 increases printed QR module size for reliable mobile scanning
    // without changing the permanent QR identity.
    'label_template_version' => 'v2.2-3',
    // The browser-print dimensions live in one place so later thermal
    // templates do not need to change identity or receiving behavior.
    'label_template' => ['width' => '50mm', 'height' => '20mm'],
    // Product/legal teams own the final wording. V2 records the displayed
    // version and acknowledgement without exposing identity references in lists.
    'tradein_seller_declaration' => env('RECOMMERCE_TRADEIN_SELLER_DECLARATION', 'Seller declares that they have the right to offer this device and that the information provided is accurate.'),
    'tradein_quote_valid_days' => env('RECOMMERCE_TRADEIN_QUOTE_VALID_DAYS', 7),
    'receive_batch_limit' => 50,
    // Bounded visible-page bulk printing. This is deliberately not a
    // server-side "select all matching" capability.
    'bulk_label_limit' => 100,
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
        'recommerce.device.override_acquisition_cost',
        'recommerce.receiving.prepare',
        'recommerce.receiving.post',
        'recommerce.inspection.view',
        'recommerce.inspection.assign',
        'recommerce.inspection.complete',
        'recommerce.stock.reconcile',
        'recommerce.stock.reconcile.record',
        'recommerce.stockcount.view',
        'recommerce.stockcount.create',
        'recommerce.stockcount.count',
        'recommerce.stockcount.review',
        'recommerce.stockcount.approve',
        'recommerce.stockcount.reconcile',
        'recommerce.stockcount.close',
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
        'recommerce.tradein.view',
        'recommerce.tradein.manage',
        'recommerce.tradein.approve',
        'recommerce.tradein.override_economic_ceiling',
        'recommerce.tradein.accept',
        'recommerce.tradein.reverse',
        'recommerce.tradein.catalogue_create',
        'recommerce.tradein.catalogue_override_duplicate',
    ],
    'stock_count' => [
        // There is deliberately no monetary default. Operations must set a
        // local policy before generic-cost threshold approvals are enabled.
        'approval' => [
            'serialized_requires_approval' => env('RECOMMERCE_STOCK_COUNT_SERIALIZED_REQUIRES_APPROVAL', true),
            'generic_cost_threshold' => env('RECOMMERCE_STOCK_COUNT_GENERIC_COST_THRESHOLD'),
        ],
    ],
    'photo_ai' => [
        // This gates staff visibility/review only. Website evidence may still
        // be retained on intake so disabling the panel never blocks Trade-In.
        'staff_enabled' => env('RECOMMERCE_PHOTO_AI_STAFF_ENABLED', false),
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
        // New configurations can join the cohort through the authorised
        // Product tracking form without an environment-file deployment.
        // This remains constrained by the Recommerce cohort and the
        // `recommerce.receiving.post` permission.  Defaulting to enabled
        // makes the authorised product form usable on a deployed cohort when
        // the optional environment override is absent.
        'allow_approved_product_policies' => env('RECOMMERCE_ALLOW_APPROVED_PRODUCT_POLICIES', true),
    ],
];
