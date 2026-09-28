@extends('layouts.app')

@php
    $pageTitles = ['overview' => 'Trade-In Acquisition', 'acquisitions' => 'Acquisitions', 'approvals' => 'Approvals', 'reports' => 'Trade-In Reports', 'create' => 'New Acquisition', 'walk-in' => 'Walk-In Trade-In', 'show' => 'Deal Desk', 'website' => 'Website Trade-In Request'];
    $pageSubtitles = [
        'overview' => 'Assess, price and acquire customer devices for resale.',
        'acquisitions' => 'Resume active deals and review completed acquisition records.',
        'approvals' => 'Review offers that exceed staff authority or acquisition ceilings.',
        'reports' => 'Accurate acquisition, conversion and QC performance from recorded evidence.',
        'create' => 'One focused workspace from seller intake to a reviewable offer.',
        'walk-in' => 'Calculate a buyback estimate for a customer at the counter.',
        'show' => 'Understand the economics, negotiate and take the next valid action.',
        'website' => 'Review the immutable customer submission, evidence, native valuation, approval, and decision in SAVERPOS.',
    ];
@endphp

@section('title', $pageTitles[$workspacePage] ?? 'Trade-In Acquisition')

@section('content')
@include('recommerce::tradein.partials.styles')
<section class="container-fluid sb-ti" id="recommerce-trade-ins" data-workspace-page="{{ $workspacePage }}" style="margin-top:24px">
    <header class="sb-ti-header">
    @if(app()->environment('staging'))<a href="{{ route('recommerce.tradeins.intelligence') }}">Pricing evidence</a>@endif
        <div>
            <p class="sb-ti-eyebrow">SAVERPOS · RECOMMERCE</p>
            <h1>{{ $pageTitles[$workspacePage] ?? 'Trade-In Acquisition' }}</h1>
            <p class="sb-ti-subtitle">{{ $pageSubtitles[$workspacePage] ?? '' }}</p>
        </div>
        @if(! in_array($workspacePage, ['create', 'walk-in'], true) && $canManage)
            <a class="tw-inline-flex tw-items-center tw-bg-gradient-to-r tw-from-indigo-500 tw-to-blue-500 tw-font-semibold tw-text-xs tw-px-3 tw-py-1 tw-rounded-lg tw-shadow-md hover:tw-from-indigo-600 hover:tw-to-blue-600 hover:tw-shadow-lg tw-transition tw-duration-200 focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-blue-500 focus:tw-ring-offset-2 active:tw-from-indigo-700 active:tw-to-blue-700" href="{{ route('recommerce.tradeins.create') }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-plus tw-mr-1">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M12 5l0 14" />
                    <path d="M5 12l14 0" />
                </svg> New Acquisition
            </a>
        @elseif($workspacePage === 'create')
            <a class="btn btn-default" href="{{ route('recommerce.tradeins.acquisitions') }}"><i class="fa fa-arrow-left"></i> Exit workspace</a>
        @elseif($workspacePage === 'walk-in')
            <a class="btn btn-default" href="{{ route('recommerce.tradeins.index') }}"><i class="fa fa-arrow-left"></i> Exit workspace</a>
        @endif
    </header>
    @if(session('status'))<div class="alert alert-{{ data_get(session('status'), 'success') ? 'success' : 'warning' }}" role="status">{{ data_get(session('status'), 'msg') }}</div>@endif
    @if($savedWalkInQuote ?? null)
        @php($walkInPricing = (array) $savedWalkInQuote->pricing_snapshot_json)
        <div class="alert alert-{{ ($walkInPricing['rejected'] ?? false) ? 'warning' : 'success' }}" role="status">
            @if($walkInPricing['rejected'] ?? false)
                Walk-In recorded as ineligible: {{ $walkInPricing['rejected_reason'] ?? '' }}
            @else
                Walk-In estimate saved for {{ data_get($savedWalkInQuote->specifications_json, 'brand') }} {{ data_get($savedWalkInQuote->specifications_json, 'model') }}:
                <strong>RM {{ number_format((float) ($walkInPricing['final_amount'] ?? $savedWalkInQuote->estimated_high_amount), 2) }}</strong>
                (market price RM {{ number_format((float) $savedWalkInQuote->expected_resale_amount, 2) }}, {{ $savedWalkInQuote->market_price_source === 'API' ? 'from Lazada and Shopee' : 'entered manually' }})
            @endif
        </div>
    @endif
    @if($variations->isEmpty())<div class="alert alert-warning">No approved catalogue match is available for this branch. A Trade-In cannot bypass the configured product cohort.</div>@endif
    @include('recommerce::tradein.partials.'.$workspacePage)
</section>
@endsection
