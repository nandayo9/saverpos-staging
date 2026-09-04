@once
<style>
    /* POS workbench: scoped so reports and general UltimatePOS administration stay untouched. */
    #add_pos_sell_form, #edit_pos_sell_form { --sb-pos-surface: #0f1b2d; --sb-pos-surface-raised: #16253a; --sb-pos-border: #29405f; --sb-pos-muted: #9db0c9; --sb-pos-text: #edf4ff; --sb-pos-focus: #22b8f0; --sb-pos-ready: #24c889; --sb-pos-pending: #f3a83b; --sb-pos-danger: #ef6670; }
    #add_pos_sell_form .box-body, #edit_pos_sell_form .box-body { background: var(--sb-pos-surface); color: var(--sb-pos-text); }

    #add_pos_sell_form #walk-in-pos-controls, #edit_pos_sell_form .pos-cashier-context { margin: 0; padding: 10px 12px 4px; }
    #add_pos_sell_form #walk-in-pos-controls > div { min-height: 38px; }
    #add_pos_sell_form #walk-in-pos-controls .btn-success { min-height: 38px; border-radius: 8px; font-weight: 700; letter-spacing: .01em; }
    #add_pos_sell_form #walk-in-pos-controls .form-control, #add_pos_sell_form #walk-in-pos-controls .select2-selection,
    #edit_pos_sell_form .pos-cashier-context .form-control, #edit_pos_sell_form .pos-cashier-context .select2-selection { min-height: 38px; border-radius: 8px; }
    #add_pos_sell_form #walk-in-pos-controls + hr { margin: 6px 12px; opacity: .28; }

    #add_pos_sell_form #search_product, #edit_pos_sell_form #search_product { min-height: 46px; border: 2px solid #315071; border-radius: 10px !important; font-size: 15px; font-weight: 600; color: var(--sb-pos-text); background: #0d1829; box-shadow: none; }
    #add_pos_sell_form #search_product:focus, #edit_pos_sell_form #search_product:focus { border-color: var(--sb-pos-focus); box-shadow: 0 0 0 3px rgba(34,184,240,.18); }
    #add_pos_sell_form #search_product::placeholder, #edit_pos_sell_form #search_product::placeholder { color: #8ba2bf; font-weight: 500; }

    .pos-device-workbench { display: grid; grid-template-columns: minmax(190px, .9fr) minmax(180px, .7fr) minmax(280px, 1.4fr) minmax(220px, 1fr) auto; gap: 12px; align-items: center; margin: 10px 12px 8px; padding: 13px 14px; color: var(--sb-pos-text); background: linear-gradient(100deg, #172d43 0%, #14253a 56%, #102033 100%); border: 1px solid #2b6282; border-radius: 12px; box-shadow: 0 9px 24px rgba(1,10,23,.2); }
    .pos-device-workbench[hidden] { display: none; }
    .pos-device-workbench__heading { display: flex; gap: 10px; align-items: center; min-width: 0; }
    .pos-device-workbench__heading > div { min-width: 0; }
    .pos-device-workbench__icon { display: inline-flex; width: 38px; height: 38px; align-items: center; justify-content: center; flex: 0 0 auto; border-radius: 10px; color: #ffc05a; background: rgba(243,168,59,.16); }
    .pos-device-workbench__eyebrow { display: block; font-size: 10px; line-height: 1.1; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #aebfd3; }
    .pos-device-workbench h2 { margin: 2px 0; font-size: 17px; line-height: 1.1; font-weight: 800; color: #fff; }
    .pos-device-workbench__heading p { max-width: 260px; margin: 0; overflow: hidden; color: #aebfd3; font-size: 12px; line-height: 1.25; text-overflow: ellipsis; white-space: nowrap; }
    .pos-device-workbench__progress { padding: 8px 10px; border-left: 3px solid var(--sb-pos-pending); border-radius: 7px; background: rgba(243,168,59,.09); }
    .pos-device-workbench__progress.is-complete { border-color: var(--sb-pos-ready); background: rgba(36,200,137,.10); }
    .pos-device-workbench__progress strong, .pos-device-workbench__progress span { display: block; }
    .pos-device-workbench__progress strong { color: #fff; font-size: 14px; }
    .pos-device-workbench__progress span { margin-top: 2px; color: #aec0d5; font-size: 11px; }
    .pos-device-workbench__scan label { display: block; margin: 0 0 4px; color: #b9c9dc; font-size: 11px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
    .pos-device-workbench__scan .input-group-addon, .pos-device-workbench__scan .form-control { height: 40px; border-color: #3b5b7d; color: #f7fbff; background: #0c1727; }
    .pos-device-workbench__scan .form-control:focus { border-color: var(--sb-pos-focus); box-shadow: 0 0 0 3px rgba(34,184,240,.18); }
    .pos-device-workbench__scan .btn { height: 40px; border-radius: 0 7px 7px 0; font-weight: 800; }
    .pos-device-workbench__feedback { min-height: 14px; margin: 4px 0 0; color: #afc1d5; font-size: 11px; line-height: 1.25; }
    .pos-device-workbench__feedback.is-error { color: #ffc2c8; }
    .pos-device-workbench__feedback.is-success { color: #87e9bd; }
    .pos-device-workbench__devices { display: flex; flex-wrap: wrap; align-content: center; gap: 5px; min-height: 30px; }
    .pos-device-workbench__device { display: inline-flex; align-items: center; max-width: 100%; gap: 5px; padding: 5px 7px; color: #cbf8df; background: rgba(36,200,137,.13); border: 1px solid rgba(78,218,154,.35); border-radius: 6px; font-size: 11px; font-weight: 700; }
    .pos-device-workbench__device code { overflow: hidden; color: inherit; font: inherit; text-overflow: ellipsis; white-space: nowrap; }
    .pos-device-workbench__remove { padding: 0; color: inherit; border: 0; background: transparent; cursor: pointer; opacity: .8; }
    .pos-device-workbench__close { display: inline-flex; align-items: center; justify-content: center; gap: 5px; min-height: 34px; padding: 0 9px; color: #c3d3e7; background: transparent; border: 1px solid #3c5877; border-radius: 7px; font-size: 11px; font-weight: 700; cursor: pointer; }
    .pos-device-workbench__close:hover { color: #fff; border-color: #6e9bc4; }

    #add_pos_sell_form .pos_product_div, #edit_pos_sell_form .pos_product_div { padding: 0 12px; }
    #add_pos_sell_form #pos_table, #edit_pos_sell_form #pos_table { margin: 0; border-collapse: separate; border-spacing: 0 5px; }
    #add_pos_sell_form #pos_table thead th, #edit_pos_sell_form #pos_table thead th { padding: 8px 7px; color: #9db0c9; border: 0; background: transparent; font-size: 10px; letter-spacing: .07em; text-transform: uppercase; }
    #add_pos_sell_form #pos_table tbody .product_row > td, #edit_pos_sell_form #pos_table tbody .product_row > td { padding: 10px 7px; vertical-align: middle; border-top: 1px solid var(--sb-pos-border); border-bottom: 1px solid var(--sb-pos-border); background: var(--sb-pos-surface-raised); }
    #add_pos_sell_form #pos_table tbody .product_row > td:first-child, #edit_pos_sell_form #pos_table tbody .product_row > td:first-child { border-left: 1px solid var(--sb-pos-border); border-radius: 9px 0 0 9px; }
    #add_pos_sell_form #pos_table tbody .product_row > td:last-child, #edit_pos_sell_form #pos_table tbody .product_row > td:last-child { border-right: 1px solid var(--sb-pos-border); border-radius: 0 9px 9px 0; }
    #add_pos_sell_form #pos_table .pos_unit_price_inc_tax, #edit_pos_sell_form #pos_table .pos_unit_price_inc_tax { color: #eff6ff; background: #0c1727; border-color: #385473; font-weight: 700; }
    #add_pos_sell_form #pos_table .pos_line_total_text, #edit_pos_sell_form #pos_table .pos_line_total_text { color: #eff6ff !important; font-weight: 800; font-variant-numeric: tabular-nums; }
    #add_pos_sell_form .recommerce-device-scan, #edit_pos_sell_form .recommerce-device-scan { margin-top: 7px; }
    #add_pos_sell_form .recommerce-device-open, #edit_pos_sell_form .recommerce-device-open { display: inline-flex; align-items: center; max-width: 100%; gap: 6px; padding: 4px 7px; color: #ffd18a; background: rgba(243,168,59,.13); border: 1px solid rgba(243,168,59,.55); border-radius: 6px; font-size: 11px; font-weight: 800; cursor: pointer; }
    #add_pos_sell_form .recommerce-device-open:hover, #edit_pos_sell_form .recommerce-device-open:hover { background: rgba(243,168,59,.22); }
    #add_pos_sell_form .recommerce-device-scan.is-complete .recommerce-device-open, #edit_pos_sell_form .recommerce-device-scan.is-complete .recommerce-device-open { color: #a3f2ca; background: rgba(36,200,137,.14); border-color: rgba(80,222,158,.58); }
    .recommerce-device-state-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .recommerce-device-scan-count { flex: 0 0 auto; font-variant-numeric: tabular-nums; }
    .recommerce-device-scan-summary { display: block; margin-top: 4px; overflow: hidden; color: #9fb3ca; font-size: 10px; line-height: 1.2; text-overflow: ellipsis; white-space: nowrap; }
    .recommerce-device-codes.pos-device-code-store { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); border: 0; white-space: nowrap; }

    .pos-checkout-status { display: inline-flex; align-items: center; min-width: 190px; gap: 8px; padding: 7px 10px; color: #9db0c9; border: 1px solid #38516e; border-radius: 9px; background: #0c1828; }
    .pos-checkout-status > i { font-size: 16px; }
    .pos-checkout-status strong, .pos-checkout-status span { display: block; }
    .pos-checkout-status strong { color: #e8f0fb; font-size: 12px; line-height: 1.15; }
    .pos-checkout-status span { margin-top: 1px; font-size: 10px; line-height: 1.2; }
    .pos-checkout-status.is-ready { color: #93e8bd; border-color: rgba(36,200,137,.55); background: rgba(36,200,137,.09); }
    .pos-checkout-status.is-pending { color: #ffd28e; border-color: rgba(243,168,59,.58); background: rgba(243,168,59,.10); }
    .pos-checkout-status.is-empty { color: #aabbd0; }
    .pos-payment-action { min-height: 40px !important; padding: 8px 13px !important; border-radius: 9px !important; font-weight: 800 !important; box-shadow: none !important; }
    .pos-payment-action[disabled] { cursor: not-allowed !important; opacity: .42 !important; filter: grayscale(.25); }
    .pos-transaction-action { opacity: .8; }
    .pos-destructive-action { opacity: .8; }

    #pos_sidebar_wrap .product_box { min-height: 96px; padding: 7px !important; text-align: left !important; color: var(--sb-pos-text) !important; background: var(--sb-pos-surface-raised) !important; border-color: var(--sb-pos-border) !important; box-shadow: none !important; }
    #pos_sidebar_wrap .image-container { float: left; width: 34px !important; height: 34px !important; margin: 0 8px 0 0 !important; opacity: .7; }
    #pos_sidebar_wrap .text_div { min-width: 0; }
    #pos_sidebar_wrap .text_div small:first-child { display: -webkit-box !important; min-height: 29px; max-height: 29px !important; overflow: hidden !important; -webkit-box-orient: vertical; -webkit-line-clamp: 2; color: #e2ecf9 !important; font-size: 12px !important; line-height: 14px !important; white-space: normal !important; }
    #pos_sidebar_wrap .text_div small { color: #9db0c9 !important; font-size: 10px; }
    #pos_sidebar_wrap .product_price { color: #7ee9b2 !important; font-size: 11px !important; }
    #pos_sidebar_wrap .product_box:hover { border-color: #3b96c2 !important; background: #182b43 !important; }

    @media (min-width: 1024px) { #pos_cart_workspace { width: 64% !important; flex: 0 0 64% !important; } #pos_sidebar_wrap { width: 36% !important; flex: 0 0 36% !important; } }
    @media (min-width: 1280px) { #pos_sidebar_wrap .col-md-3 { width: 50%; } }
    @media (min-width: 1800px) { #pos_sidebar_wrap .col-md-3 { width: 33.333%; } }
    @media (max-width: 1599px) and (min-width: 768px) { .pos-device-workbench { grid-template-columns: minmax(190px, 1fr) minmax(250px, 1.35fr) minmax(190px, .9fr); } .pos-device-workbench__devices { grid-column: 1 / span 2; } .pos-device-workbench__close { grid-column: 3; } }
    @media (max-width: 1199px) { .pos-device-workbench { grid-template-columns: 1fr 1fr; } .pos-device-workbench__scan, .pos-device-workbench__devices { grid-column: span 1; } }
    @media (max-width: 767px) { .pos-device-workbench { grid-template-columns: 1fr; margin: 8px; } .pos-device-workbench__scan, .pos-device-workbench__devices { grid-column: auto; } .pos-device-workbench__close { width: 100%; } #add_pos_sell_form .pos_product_div, #edit_pos_sell_form .pos_product_div { padding: 0 7px; } #add_pos_sell_form #pos_table thead th:nth-child(4), #edit_pos_sell_form #pos_table thead th:nth-child(4), #add_pos_sell_form #pos_table tbody .product_row > td:nth-child(4), #edit_pos_sell_form #pos_table tbody .product_row > td:nth-child(4) { display: none; } }
</style>
@endonce
