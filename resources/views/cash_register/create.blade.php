@extends('layouts.app')
@section('title',  __('cash_register.open_cash_register'))

@section('content')
<style type="text/css">



</style>
<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('cash_register.open_cash_register')</h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
</section>

<!-- Main content -->
<section class="content">
{!! Form::open(['url' => action([\App\Http\Controllers\CashRegisterController::class, 'store']), 'method' => 'post', 
'id' => 'add_cash_register_form' ]) !!}
  <div class="box box-solid">
    <div class="box-body">
    <br><br><br>
    <input type="hidden" name="sub_type" value="{{$sub_type}}">
      <div class="row">
        @if($business_locations->count() > 0)
        <div class="col-sm-8 col-sm-offset-2">
          <div class="form-group">
            {!! Form::label('amount', __('cash_register.cash_in_hand') . ':*') !!}
            {!! Form::text('amount', null, ['class' => 'form-control input_number',
              'placeholder' => __('cash_register.enter_amount'), 'required']); !!}
          </div>
        </div>
        @if(count($business_locations) > 1)
        <div class="clearfix"></div>
        <div class="col-sm-8 col-sm-offset-2">
          <div class="form-group">
            {!! Form::label('location_id', __('business.business_location') . ':') !!}
              {!! Form::select('location_id', $business_locations, null, ['class' => 'form-control select2',
              'placeholder' => __('lang_v1.select_location')]); !!}
          </div>
        </div>
        @else
          {!! Form::hidden('location_id', array_key_first($business_locations->toArray()) ); !!}
        @endif
        <div class="col-sm-8 col-sm-offset-2">
          <button type="submit" class="tw-inline-flex tw-items-center tw-bg-gradient-to-r tw-from-indigo-500 tw-to-blue-500 tw-text-white tw-font-semibold tw-text-sm tw-px-4 tw-py-2 tw-rounded-xl tw-shadow-md hover:tw-from-indigo-600 hover:tw-to-blue-600 hover:tw-shadow-lg tw-transition tw-duration-200 focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-blue-500 focus:tw-ring-offset-2 active:tw-from-indigo-700 active:tw-to-blue-700 pull-right">@lang('cash_register.open_register')</button>
        </div>
        @else
        <div class="col-sm-8 col-sm-offset-2 text-center">
          <h3>@lang('lang_v1.no_location_access_found')</h3>
        </div>
      @endif
      </div>
      <br><br><br>
    </div>
  </div>
  {!! Form::close() !!}
</section>
<!-- /.content -->
@endsection