@extends('layouts.app')
@section('title', 'Woocommerce')

@section('content')

    <!-- Content Header (Page header) -->
    <section class="content-header">
        @include('woocommerce.partials.tabs')
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Woocommerce</h1>
    </section>

    <!-- Main content -->
    <section class="content">

        {{-- This screen is a visual rebuild of the live Woocommerce module,
             which is not shipped in this repository. Nothing here talks to a
             WooCommerce store yet, so the buttons are deliberately inert and
             the figures come from the controller's placeholder array. --}}
        <div class="row">

            {{-- Left column ------------------------------------------------ --}}
            <div class="col-sm-6">

                <div class="col-sm-12">
                    @component('components.widget', ['class' => 'box-primary', 'title' => 'Sync Product Categories:'])
                        @if (!empty($summary['categories']['not_synced']))
                            <div class="alert alert-warning alert-dismissible">
                                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                                {{ $summary['categories']['not_synced'] }} Categories not synced
                            </div>
                        @endif

                        <button type="button" class="btn btn-primary btn-block wc-sync-action"
                            data-sync="categories">Sync</button>

                        <p class="text-muted tw-mt-2 tw-mb-3">
                            Last Synced: {{ $summary['categories']['last_synced'] }}
                        </p>

                        <button type="button" class="btn btn-danger btn-xs wc-sync-action" data-sync="reset-categories">
                            <i class="fa fa-undo" aria-hidden="true"></i> Reset synced categories
                        </button>
                    @endcomponent
                </div>

                <div class="col-sm-12">
                    @component('components.widget', ['class' => 'box-primary', 'title' => 'Map Tax Rates:'])
                        <div class="table-responsive">
                            <table class="table table-condensed" id="wc_tax_rate_table">
                                <thead>
                                    <tr>
                                        <th>POS Tax Rate</th>
                                        <th>Equivalent Woocommerce Tax Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($summary['tax_rates'] as $rate)
                                        <tr>
                                            <td>{{ $rate['pos'] }}</td>
                                            <td>{{ $rate['woocommerce'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="2" class="text-center text-muted">
                                                No tax rates to map
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <div class="text-right">
                            <button type="button" class="btn btn-danger wc-sync-action" data-sync="save-tax-rates">
                                Save
                            </button>
                        </div>
                    @endcomponent
                </div>

            </div>

            {{-- Right column ----------------------------------------------- --}}
            <div class="col-sm-6">

                <div class="col-sm-12">
                    @component('components.widget', ['class' => 'box-primary', 'title' => 'Sync Products:'])
                        @if (!empty($summary['products']['not_synced']) || !empty($summary['products']['updated_since_sync']))
                            <div class="alert alert-warning alert-dismissible">
                                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                                {{ $summary['products']['not_synced'] }} Products not synced<br>
                                {{ $summary['products']['updated_since_sync'] }} Products have been updated after last sync
                            </div>
                        @endif

                        <div class="row">
                            <div class="col-xs-6">
                                <button type="button" class="btn btn-warning wc-sync-action" data-sync="products-new">
                                    Sync only new
                                </button>
                                <i class="fa fa-info-circle text-info hover-q no-print" aria-hidden="true"
                                    data-container="body" data-toggle="popover" data-placement="auto bottom"
                                    data-content="Sends only products that have never been synced." data-html="true"
                                    data-trigger="hover"></i>
                                <p class="text-muted tw-mt-2">
                                    Last Synced: {{ $summary['products']['last_synced_new'] }}
                                </p>
                            </div>
                            <div class="col-xs-6">
                                <button type="button" class="btn btn-primary wc-sync-action" data-sync="products-all">
                                    Sync all
                                </button>
                                <i class="fa fa-info-circle text-info hover-q no-print" aria-hidden="true"
                                    data-container="body" data-toggle="popover" data-placement="auto bottom"
                                    data-content="Sends every product, replacing what is already in the store."
                                    data-html="true" data-trigger="hover"></i>
                                <p class="text-muted tw-mt-2">
                                    Last Synced: {{ $summary['products']['last_synced_all'] }}
                                </p>
                            </div>
                        </div>

                        <button type="button" class="btn btn-danger btn-xs wc-sync-action" data-sync="reset-products">
                            <i class="fa fa-undo" aria-hidden="true"></i> Reset synced products
                        </button>
                    @endcomponent
                </div>

                <div class="col-sm-12">
                    @component('components.widget', ['class' => 'box-primary', 'title' => 'Sync Orders:'])
                        <button type="button" class="btn btn-success btn-block wc-sync-action" data-sync="orders">
                            Sync
                        </button>
                        <p class="text-muted tw-mt-2 tw-mb-0">
                            Last Synced: {{ $summary['orders']['last_synced'] }}
                        </p>
                    @endcomponent
                </div>

            </div>
        </div>

    </section>
@stop

@section('javascript')
    <script type="text/javascript">
        $(document).ready(function () {
            // No sync engine exists in this build, so say so plainly rather
            // than letting a button look like it worked.
            $(document).on('click', '.wc-sync-action', function () {
                toastr.info('Woocommerce sync is not configured in this environment.');
            });
        });
    </script>
@endsection
