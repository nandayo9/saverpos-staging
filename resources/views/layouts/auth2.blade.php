<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!-- Tell the browser to be responsive to screen width -->
    <meta content="width=device-width, initial-scale=1" name="viewport">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title') - {{ config('app.name', 'SAVERPOS') }}</title>

    @include('layouts.partials.css')

    @include('layouts.partials.extracss_auth')

    <!--[if lt IE 9]>
    <script src="https://oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js"></script>
    <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->
    <script src='https://www.google.com/recaptcha/api.js'></script>

</head>

<body class="pace-done" data-new-gr-c-s-check-loaded="14.1172.0" data-gr-ext-installed="" cz-shortcut-listen="true">
    @inject('request', 'Illuminate\Http\Request')
    @if (session('status') && session('status.success'))
        <input type="hidden" id="status_span" data-status="{{ session('status.success') }}"
            data-msg="{{ session('status.msg') }}">
    @endif
    <div class="container-fluid">
        <div class="row eq-height-row">
            <div class="col-md-12 col-sm-12 col-xs-12 right-col tw-pt-20 tw-pb-10 tw-px-5">
                <div class="row">
                    {{-- <div
                        class="lg:tw-w-16 md:tw-h-16 tw-w-12 tw-h-12 tw-flex tw-items-center tw-justify-center tw-mx-auto tw-overflow-hidden tw-bg-white tw-rounded-full tw-p-0.5 tw-mb-4">
                                <img src="{{ asset('img/saverpos-logo.png')}}" alt="SAVERPOS logo" class="tw-rounded-full tw-object-fill" />
                    </div> --}}

                    <div class="tw-absolute tw-top-2 md:tw-top-5 tw-left-4 md:tw-left-8 tw-flex tw-items-center tw-gap-4"
                        style="text-align: left">
                        <a href="{{ url('/') }}">
                            <div
                                class="lg:tw-w-16 md:tw-h-16 tw-w-12 tw-h-12 tw-flex tw-items-center tw-justify-center tw-mx-auto tw-overflow-hidden tw-p-0.5 tw-mb-4">
                                {{-- SVG, not the PNG: the PNG is an opaque
                                     white square, which reads as a white tile
                                     on the dark login page. --}}
                                <img src="{{ asset('img/saverpos-logo.svg')}}" alt="SAVERPOS logo" class="tw-object-contain tw-w-full tw-h-full" />
                            </div>
                        </a>
                        @if(config('constants.SHOW_REPAIR_STATUS_LOGIN_SCREEN') && Route::has('repair-status'))
                            <a class="tw-text-white tw-font-medium tw-text-sm md:tw-text-base hover:tw-text-white"
                                href="{{ action([\Modules\Repair\Http\Controllers\CustomerRepairStatusController::class, 'index']) }}">
                                @lang('repair::lang.repair_status')
                            </a>
                        @endif
                        
                        @if(Route::has('member_scanner'))
                            <a class="tw-text-white tw-font-medium tw-text-sm md:tw-text-base hover:tw-text-white"
                                href="{{ action([\Modules\Gym\Http\Controllers\MemberController::class, 'member_scanner']) }}">
                                @lang('gym::lang.gym_member_profile')
                            </a>
                        @endif
                    </div>

                    <div class="sb-auth-actions tw-absolute tw-top-5 md:tw-top-8 tw-right-5 md:tw-right-10 tw-flex tw-items-center tw-gap-3 md:tw-gap-4"
                        style="text-align: left">

                        {{-- Theme toggle. Same id and markup as the header's
                             button, so the handler in partials/javascripts
                             picks it up with no extra script, and it writes
                             the same sb_theme cookie - which is why the choice
                             survives logging in. --}}
                        <button type="button" id="sb-theme-toggle" title="Theme" aria-label="Theme"
                            class="sb-auth-theme-toggle">
                            {{-- Sun shows in dark mode (click for light); moon in light mode. --}}
                            <svg class="sb-theme-icon-sun tw-w-5 tw-h-5" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="4" />
                                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
                            </svg>
                            <svg class="sb-theme-icon-moon tw-w-5 tw-h-5" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                            </svg>
                        </button>

                        @if (!($request->segment(1) == 'business' && $request->segment(2) == 'register'))
                            <!-- Register Url -->
                            @if (config('constants.allow_registration'))
                            {{-- <span
                                class="tw-text-white tw-font-medium tw-text-sm md:tw-text-base">{{ __('business.not_yet_registered') }}
                            </span> --}}

                            {{-- sb-auth-pill carries the border and hover from
                                 the theme tokens; the old tw-border-white was
                                 invisible against the light background. --}}
                            {{-- 40px tall at every breakpoint (was 48px on
                                 desktop) and px-4 instead of a fixed w-24, so
                                 it hugs its label. Height is held at 40px
                                 rather than shrunk further: it is the tap
                                 target, and it was already 40px on mobile. --}}
                            <div class="sb-auth-pill tw-border tw-h-10 tw-px-4 tw-flex tw-items-center tw-justify-center">
                             <a href="{{ route('business.getRegister')}}@if(!empty(request()->lang)){{'?lang='.request()->lang}}@endif"
                                    class="tw-font-medium tw-text-xs md:tw-text-sm">
                                    {{ __('business.register') }}</a>
                            </div>

                                <!-- pricing url -->
                                @if (Route::has('pricing') && config('app.env') != 'demo' && $request->segment(1) != 'pricing')
                                    &nbsp; <a class="tw-text-white tw-font-medium tw-text-sm md:tw-text-base hover:tw-text-white"
                                        href="{{ action([\Modules\Superadmin\Http\Controllers\PricingController::class, 'index']) }}">@lang('superadmin::lang.pricing')</a>
                                @endif
                            @endif
                        @endif
                        @if ($request->segment(1) != 'login')
                            <a class="tw-text-white tw-font-medium tw-text-sm md:tw-text-base hover:tw-text-white"
                                href="{{ action([\App\Http\Controllers\Auth\LoginController::class, 'login'])}}@if(!empty(request()->lang)){{'?lang='.request()->lang}}@endif">{{ __('business.sign_in') }}</a>
                        @endif
                        @include('layouts.partials.language_btn')
                    </div>
                    <div class="col-md-10 col-xs-8" style="text-align: right;">

                    </div>
                </div>
                @yield('content')
            </div>
        </div>
    </div>


    @include('layouts.partials.javascripts')

    <script type="text/javascript">
        // The shared toggle (partials/javascripts) flips data-theme and writes
        // the sb_theme cookie, which is enough for the authenticated app. This
        // layout's backgrounds are Tailwind utilities that do not all restyle
        // from an attribute change alone - the card switched but the page
        // behind it stayed light - so reload and let the pre-paint bootstrap
        // in partials/css apply the theme properly. This listener is added
        // after the shared one, so the cookie is already written by the time
        // it runs.
        (function () {
            var btn = document.getElementById('sb-theme-toggle');

            if (!btn) {
                return;
            }

            btn.addEventListener('click', function () {
                window.setTimeout(function () {
                    window.location.reload();
                }, 0);
            });
        })();
    </script>

    <!-- Scripts -->
    <script src="{{ asset('js/login.js?v=' . $asset_v) }}"></script>

    @yield('javascript')

    <script type="text/javascript">
        $(document).ready(function() {
            $('.select2_register').select2();

            // $('input').iCheck({
            //     checkboxClass: 'icheckbox_square-blue',
            //     radioClass: 'iradio_square-blue',
            //     increaseArea: '20%' // optional
            // });
        });
    </script>
    {{-- The wizard's content panel used to be pinned to white here, which
         left the registration form white-on-white-ish in dark mode. It is
         now transparent in saverbro-dark-pos.css so the card behind it shows
         through, which gives white in light mode and the dark surface in
         dark mode. --}}
</body>

</html>
