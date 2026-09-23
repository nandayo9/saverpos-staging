@extends('layouts.app')
@section('title', 'API Settings')

@section('content')

    <section class="content-header">
        @include('woocommerce.partials.tabs')
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">API Settings</h1>
    </section>

    <section class="content">
        {{-- "box" as well as "box-primary": the theme sheets carry .box, not
             .box-primary, so without it the card kept a white background in
             dark mode and the pane text sat on it unreadably. --}}
        @component('components.widget', ['class' => 'box box-primary'])

            <div class="row wc-settings">
                {{-- Left rail, matching the live page's vertical pill nav. --}}
                <div class="col-sm-3">
                    <ul class="nav nav-pills nav-stacked wc-settings-nav">
                        <li class="active"><a href="#wc-instructions" data-toggle="tab">Instructions</a></li>
                        <li><a href="#wc-api" data-toggle="tab">API Settings</a></li>
                        <li><a href="#wc-product-sync" data-toggle="tab">Product Sync Settings</a></li>
                        <li><a href="#wc-order-sync" data-toggle="tab">Order Sync Settings</a></li>
                        <li><a href="#wc-webhook" data-toggle="tab">Webhook Settings</a></li>
                    </ul>
                </div>

                <div class="col-sm-9">
                    <div class="tab-content">

                        <div class="tab-pane active" id="wc-instructions">
                            <p>Do not refresh or leave the page while synchronizing</p>
                            <p>Timezone of POS should be same as timezone of the Woocommerce App</p>
                            <p>
                                Get WooCommerce API details from,
                                <code>WooCommerce -&gt; Settings -&gt; Advance -&gt; REST API</code>.
                                Enter description, select User &amp; Provide <code>Read/Write</code> Permission.
                            </p>
                            <p>Change the permalinks option to <code>"Post Name"</code> in WordPress permalink option.</p>
                            <p>If still doesn't work try to reset the permalink</p>
                        </div>

                        <div class="tab-pane" id="wc-api">
                            <div class="alert alert-info">
                                Read-only. The sync engine that saves these lives in the Woocommerce module,
                                which is not installed here; the values below are the ones already stored for
                                this business.
                            </div>
                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Woocommerce app URL:</label>
                                        <input type="text" class="form-control"
                                            value="{{ $settings['woocommerce_app_url'] ?? '' }}" readonly>
                                    </div>
                                </div>
                                @include('woocommerce.partials.secret_field', [
                                    'label' => 'Consumer key',
                                    'value' => $settings['woocommerce_consumer_key'] ?? '',
                                ])
                                @include('woocommerce.partials.secret_field', [
                                    'label' => 'Consumer secret',
                                    'value' => $settings['woocommerce_consumer_secret'] ?? '',
                                ])
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Default location:</label>
                                        <input type="text" class="form-control"
                                            value="{{ optional(\App\BusinessLocation::find($settings['location_id'] ?? null))->name ?? ($settings['location_id'] ?? '') }}"
                                            readonly>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Enable auto sync:</label>
                                        <input type="text" class="form-control"
                                            value="{{ !empty($settings['enable_auto_sync']) ? 'Yes' : 'No' }}" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane" id="wc-product-sync">
                            <div class="row">
                                @foreach ([
                                    'default_tax_class' => 'Default tax class',
                                    'product_tax_type' => 'Product tax type',
                                    'default_selling_price_group' => 'Default selling price group',
                                    'sync_description_as' => 'Sync description as',
                                    'manage_stock_for_create' => 'Manage stock (create)',
                                    'in_stock_for_create' => 'In stock (create)',
                                    'manage_stock_for_update' => 'Manage stock (update)',
                                    'in_stock_for_update' => 'In stock (update)',
                                ] as $key => $label)
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label>{{ $label }}:</label>
                                            <input type="text" class="form-control"
                                                value="{{ is_array($settings[$key] ?? null) ? implode(', ', $settings[$key]) : ($settings[$key] ?? '') }}"
                                                readonly>
                                        </div>
                                    </div>
                                @endforeach

                                @foreach (['product_fields_for_create' => 'Fields synced on create', 'product_fields_for_update' => 'Fields synced on update'] as $key => $label)
                                    <div class="col-sm-12">
                                        <div class="form-group">
                                            <label>{{ $label }}:</label>
                                            <input type="text" class="form-control"
                                                value="{{ is_array($settings[$key] ?? null) ? implode(', ', $settings[$key]) : ($settings[$key] ?? '') }}"
                                                readonly>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="tab-pane" id="wc-order-sync">
                            <div class="row">
                                @foreach (['order_statuses' => 'Order statuses', 'shipping_statuses' => 'Shipping statuses'] as $key => $label)
                                    <div class="col-sm-12">
                                        <div class="form-group">
                                            <label>{{ $label }}:</label>
                                            <input type="text" class="form-control"
                                                value="{{ is_array($settings[$key] ?? null) ? implode(', ', array_map(fn($v) => is_array($v) ? implode('/', $v) : $v, $settings[$key])) : ($settings[$key] ?? '') }}"
                                                readonly>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="tab-pane" id="wc-webhook">
                            <div class="alert alert-info">
                                These are shared secrets for the live store, hidden by default &mdash; use the
                                eye to reveal one. Nothing here can receive a webhook.
                            </div>
                            <div class="row">
                                @foreach ([
                                    'woocommerce_wh_oc_secret' => 'Order created secret',
                                    'woocommerce_wh_ou_secret' => 'Order updated secret',
                                    'woocommerce_wh_od_secret' => 'Order deleted secret',
                                    'woocommerce_wh_or_secret' => 'Order restored secret',
                                ] as $key => $label)
                                    @include('woocommerce.partials.secret_field', [
                                        'label' => $label,
                                        'value' => $settings[$key] ?? '',
                                    ])
                                @endforeach
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        @endcomponent

        <p class="text-muted"><i>WooCommerce module version -</i> <code>not installed</code></p>
    </section>
@stop

@section('javascript')
    <script type="text/javascript">
        $(document).ready(function () {
            // Delegated, so it covers the secrets in both panes including the
            // ones inside the hidden tab-panes.
            $(document).on('click', '.wc-secret-toggle', function () {
                var $btn = $(this);
                var $input = $btn.closest('.input-group').find('.wc-secret');
                var reveal = $input.attr('type') === 'password';
                var label = ($btn.attr('aria-label') || '').replace(/^(Show|Hide) /, '');

                $input.attr('type', reveal ? 'text' : 'password');

                $btn.attr('aria-pressed', reveal ? 'true' : 'false')
                    .attr('aria-label', (reveal ? 'Hide ' : 'Show ') + label)
                    .attr('title', (reveal ? 'Hide ' : 'Show ') + label)
                    .find('i')
                    .toggleClass('fa-eye', !reveal)
                    .toggleClass('fa-eye-slash', reveal);
            });
        });
    </script>
@endsection
