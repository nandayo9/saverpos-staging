@extends('layouts.app')
@section('title', 'Create Stock Count')
@section('content')
<section class="container" aria-labelledby="create-stock-count-title"><div class="box box-primary"><div class="box-header with-border"><h3 id="create-stock-count-title" class="box-title">Create Stock Count</h3></div><form method="post" action="{{ route('recommerce.stock-counts.store') }}"><div class="box-body">@csrf
    <input type="hidden" name="location_id" value="{{ $locationId }}">
    <div class="form-group"><label for="count_type">Count type</label><select id="count_type" name="count_type" class="form-control"><option value="FULL_BRANCH">Full branch (authorized inventory scope)</option><option value="CYCLE_COUNT">Cycle count (selected products)</option></select></div>
    <div class="checkbox"><label><input type="checkbox" name="blind_count" value="1"> Blind count — hide expected totals while counting</label></div>
    <div class="form-group"><label>Cycle scope</label><p class="help-block">Leave all selected for the full authorized branch scope. Only configured Recommerce variations can be counted in V1.</p><div class="well">@forelse($variations as $variation)<label style="display:block"><input type="checkbox" name="variation_ids[]" value="{{ $variation->id }}" checked> {{ optional($variation->product)->name ?: 'Product' }} · {{ $variation->name ?: $variation->id }}</label>@empty<span class="text-warning">No authorized variations are configured.</span>@endforelse</div></div>
    <div class="alert alert-info">Starting the count creates an immutable expected snapshot. Normal operations may continue, but post-snapshot Device movements must be reviewed and block automatic reconciliation in V1.</div>
</div><div class="box-footer"><button class="btn btn-primary">Create draft</button> <a class="btn btn-default" href="{{ route('recommerce.stock-counts.index', ['location_id' => $locationId]) }}">Cancel</a></div></form></div></section>
@endsection
