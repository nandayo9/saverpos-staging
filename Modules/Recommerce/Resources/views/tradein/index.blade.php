@extends('layouts.app')

@php
    $pageTitles = ['overview' => 'Trade-In Acquisition', 'acquisitions' => 'Acquisitions', 'approvals' => 'Approvals', 'reports' => 'Trade-In Reports', 'create' => 'New Acquisition', 'show' => 'Deal Desk'];
    $pageSubtitles = [
        'overview' => 'Assess, price and acquire customer devices for resale.',
        'acquisitions' => 'Resume active deals and review completed acquisition records.',
        'approvals' => 'Review offers that exceed staff authority or acquisition ceilings.',
        'reports' => 'Accurate acquisition, conversion and QC performance from recorded evidence.',
        'create' => 'One focused workspace from seller intake to a reviewable offer.',
        'show' => 'Understand the economics, negotiate and take the next valid action.',
    ];
@endphp

@section('title', $pageTitles[$workspacePage] ?? 'Trade-In Acquisition')

@section('content')
@include('recommerce::tradein.partials.styles')
<section class="container-fluid sb-ti" id="recommerce-trade-ins" data-workspace-page="{{ $workspacePage }}">
    <header class="sb-ti-header">
        <div>
            <p class="sb-ti-eyebrow">SAVERPOS · RECOMMERCE</p>
            <h1>{{ $pageTitles[$workspacePage] ?? 'Trade-In Acquisition' }}</h1>
            <p class="sb-ti-subtitle">{{ $pageSubtitles[$workspacePage] ?? '' }}</p>
        </div>
        @if($workspacePage !== 'create' && $canManage)
            <a class="btn btn-primary sb-ti-primary" href="{{ route('recommerce.tradeins.create') }}"><i class="fa fa-plus"></i> New Acquisition</a>
        @elseif($workspacePage === 'create')
            <a class="btn btn-default" href="{{ route('recommerce.tradeins.acquisitions') }}"><i class="fa fa-arrow-left"></i> Exit workspace</a>
        @endif
    </header>
    @include('recommerce::tradein.partials.navigation')
    @if(session('status'))<div class="alert alert-{{ data_get(session('status'), 'success') ? 'success' : 'warning' }}" role="status">{{ data_get(session('status'), 'msg') }}</div>@endif
    @if($variations->isEmpty())<div class="alert alert-warning">No approved catalogue match is available for this branch. A Trade-In cannot bypass the configured product cohort.</div>@endif
    @include('recommerce::tradein.partials.'.$workspacePage)
</section>
@endsection
