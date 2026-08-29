<?php

return [
    /*
     * These are persisted as codes, not labels, so reporting remains valid if
     * SaverBro changes its customer-facing wording later.
     */
    'reasons' => [
        'PRICE_OVER_BUDGET' => ['label' => 'Price / Over Budget', 'kind' => 'OPPORTUNITY'],
        'NO_SUITABLE_STOCK' => ['label' => 'No Suitable Stock', 'kind' => 'OPPORTUNITY'],
        'SPEC_MODEL_UNAVAILABLE' => ['label' => 'Spec / Model Not Available', 'kind' => 'OPPORTUNITY'],
        'FINANCING_PAYMENT_ISSUE' => ['label' => 'Financing / Payment Issue', 'kind' => 'OPPORTUNITY'],
        'STILL_CONSIDERING' => ['label' => 'Still Considering', 'kind' => 'OPPORTUNITY'],
        'COMPARING_ELSEWHERE' => ['label' => 'Comparing / Buying Elsewhere', 'kind' => 'OPPORTUNITY'],
        'JUST_BROWSING' => ['label' => 'Just Browsing', 'kind' => 'NON_SALES_VISIT'],
        'REPAIR_SERVICE_VISIT' => ['label' => 'Repair / Service Visit', 'kind' => 'NON_SALES_VISIT'],
        'COLLECTION_PICKUP' => ['label' => 'Collection / Pickup', 'kind' => 'NON_SALES_VISIT'],
        'OTHER' => ['label' => 'Other', 'kind' => 'OTHER'],
    ],

    'permissions' => [
        'walkin.create',
        'walkin.close',
        'walkin.assign',
        'walkin.view',
        'walkin.view_all',
    ],
];
