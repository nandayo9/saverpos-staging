@extends('layouts.auth2')
{{-- layouts.auth2 already appends " - {app name}" to the title. --}}
@section('title', 'Welcome')
@section('meta_description', config('app.name', 'SAVERPOS') . ' is a point-of-sale and inventory platform: sales, stock, purchases, repairs and device trade-ins in one place.')
@inject('request', 'Illuminate\Http\Request')
@section('content')
<div class="col-md-12 col-sm-12 col-xs-12 right-col tw-pt-20 tw-pb-10 tw-px-5 tw-flex tw-flex-col tw-items-center tw-justify-center">
    {{-- The page's one <h1>; tw-my-0 cancels Bootstrap's heading margins so it looks as before.
         The inline colour outranks saverbro-dark-pos.css's global `h1 { color: ... !important }`. --}}
    <h1 class="tw-my-0 tw-text-6xl tw-font-extrabold tw-text-center tw-text-white tw-shadow-lg tw-px-4 tw-py-2 tw-bg-blue-700 tw-rounded-md" style="color: #fff !important;">
        {{ config('app.name', 'SAVERPOS') }}
    </h1>
    
    <p class="tw-text-lg tw-font-medium tw-text-center tw-text-white tw-mt-2 tw-shadow-md tw-bg-blue-600 tw-rounded-md tw-px-3 tw-py-1">
        {{ env('APP_TITLE', '') }}
    </p>
</div>

@endsection
            
