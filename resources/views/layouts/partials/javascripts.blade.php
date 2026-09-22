<script type="text/javascript">
    base_path = "{{ url('/') }}";
    //used for push notification
    APP = {};
    APP.PUSHER_APP_KEY = '{{ config('broadcasting.connections.pusher.key') }}';
    APP.PUSHER_APP_CLUSTER = '{{ config('broadcasting.connections.pusher.options.cluster') }}';
    APP.INVOICE_SCHEME_SEPARATOR = '{{ config('constants.invoice_scheme_separator') }}';
    //variable from app service provider
    APP.PUSHER_ENABLED = '{{ $__is_pusher_enabled }}';
    @auth
    @php
        $user = Auth::user();
    @endphp
    APP.USER_ID = "{{ $user->id }}";
    @else
        APP.USER_ID = '';
    @endauth
</script>

<!--[if lt IE 9]>
<script src="https://oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js?v=$asset_v"></script>
<script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js?v=$asset_v"></script>
<![endif]-->

<script src="{{ asset('js/vendor.js?v=' . $asset_v) }}"></script>

@if (file_exists(public_path('js/lang/' . session()->get('user.language', config('app.locale')) . '.js')))
    <script src="{{ asset('js/lang/' . session()->get('user.language', config('app.locale')) . '.js?v=' . $asset_v) }}">
    </script>
@else
    <script src="{{ asset('js/lang/en.js?v=' . $asset_v) }}"></script>
@endif
@php
    $business_date_format = session('business.date_format', config('constants.default_date_format'));
    $datepicker_date_format = str_replace('d', 'dd', $business_date_format);
    $datepicker_date_format = str_replace('m', 'mm', $datepicker_date_format);
    $datepicker_date_format = str_replace('Y', 'yyyy', $datepicker_date_format);

    $moment_date_format = str_replace('d', 'DD', $business_date_format);
    $moment_date_format = str_replace('m', 'MM', $moment_date_format);
    $moment_date_format = str_replace('Y', 'YYYY', $moment_date_format);

    $business_time_format = session('business.time_format');
    $moment_time_format = 'HH:mm';
    if ($business_time_format == 12) {
        $moment_time_format = 'hh:mm A';
    }

    $common_settings = !empty(session('business.common_settings')) ? session('business.common_settings') : [];

    $default_datatable_page_entries = !empty($common_settings['default_datatable_page_entries'])
        ? $common_settings['default_datatable_page_entries']
        : 25;
@endphp

<script>
    Dropzone.autoDiscover = false;
    moment.tz.setDefault('{{ Session::get('business.time_zone') }}');
    $(document).ready(function() {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });

        @if (config('app.debug') == false)
            $.fn.dataTable.ext.errMode = 'throw';
        @endif
    });

    var financial_year = {
        start: moment('{{ Session::get('financial_year.start') }}'),
        end: moment('{{ Session::get('financial_year.end') }}'),
    }
    @if (file_exists(public_path('AdminLTE/plugins/select2/lang/' . session()->get('user.language', config('app.locale')) . '.js')))
        //Default setting for select2
        $.fn.select2.defaults.set("language", "{{ session()->get('user.language', config('app.locale')) }}");
    @endif

    var datepicker_date_format = "{{ $datepicker_date_format }}";
    var moment_date_format = "{{ $moment_date_format }}";
    var moment_time_format = "{{ $moment_time_format }}";

    var app_locale = "{{ session()->get('user.language', config('app.locale')) }}";

    var non_utf8_languages = [
        @foreach (config('constants.non_utf8_languages') as $const)
            "{{ $const }}",
        @endforeach
    ];

    var __default_datatable_page_entries = "{{ $default_datatable_page_entries }}";

    var __new_notification_count_interval = "{{ config('constants.new_notification_count_interval', 60) }}000";
</script>

@if (file_exists(public_path('js/lang/' . session()->get('user.language', config('app.locale')) . '.js')))
    <script src="{{ asset('js/lang/' . session()->get('user.language', config('app.locale')) . '.js?v=' . $asset_v) }}">
    </script>
@else
    <script src="{{ asset('js/lang/en.js?v=' . $asset_v) }}"></script>
@endif

<script src="{{ asset('js/functions.js?v=' . $asset_v) }}"></script>
<script src="{{ asset('js/common.js?v=' . $asset_v . '&mtime=' . filemtime(public_path('js/common.js'))) }}"></script>
<script src="{{ asset('js/app.js?v=' . $asset_v . '&mtime=' . filemtime(public_path('js/app.js'))) }}"></script>
<script src="{{ asset('js/help-tour.js?v=' . $asset_v) }}"></script>
<script src="{{ asset('js/documents_and_note.js?v=' . $asset_v) }}"></script>
<script src="{{ asset('js/table-colresize.js?v=' . $asset_v . '&mtime=' . filemtime(public_path('js/table-colresize.js'))) }}"></script>

<!-- TODO -->
@if (file_exists(public_path('AdminLTE/plugins/select2/lang/' . session()->get('user.language', config('app.locale')) . '.js')))
    <script
        src="{{ asset('AdminLTE/plugins/select2/lang/' . session()->get('user.language', config('app.locale')) . '.js?v=' . $asset_v) }}">
    </script>
@endif
@php
    $validation_lang_file = 'messages_' . session()->get('user.language', config('app.locale')) . '.js';
@endphp
@if (file_exists(public_path() . '/js/jquery-validation-1.16.0/src/localization/' . $validation_lang_file))
    <script src="{{ asset('js/jquery-validation-1.16.0/src/localization/' . $validation_lang_file . '?v=' . $asset_v) }}">
    </script>
@endif

@if (!empty($__system_settings['additional_js']))
    {!! $__system_settings['additional_js'] !!}
@endif
@yield('javascript')

@if (Module::has('Essentials'))
    @includeIf('essentials::layouts.partials.footer_part')
@endif

<script type="text/javascript">
    $(document).ready(function() {
        var locale = "{{ session()->get('user.language', config('app.locale')) }}";
        var isRTL =
            @if (in_array(session()->get('user.language', config('app.locale')), config('constants.langs_rtl')))
                true;
            @else
                false;
            @endif

        $('#calendar').fullCalendar('option', {
            locale: locale,
            isRTL: isRTL
        });

        // Initialize popovers and close them when clicking outside
        $('[data-toggle="popover"]').popover();
        $(document).on('click', function (e) {
            $('[data-toggle="popover"]').each(function () {
                if (!$(this).is(e.target) && $(this).has(e.target).length === 0 && $('.popover').has(e.target).length === 0) {
                    $(this).popover('hide');
                }
            });
        });

        //Strip Bootstrap's btn/btn-default from the DataTables export bar, which
        //otherwise joins the buttons into a btn-group and renders them larger
        //than the daisyUI classes intend. Tables inside tabs (Profit by day,
        //Profit by service staff...) initialise after ready, so re-run on each
        //table init rather than only once here.
        function __normalize_dt_buttons() {
            $('.dt-buttons.btn-group').find('a.btn').removeClass('btn-default').removeClass('btn');
        }

        __normalize_dt_buttons();
        $(document).on('init.dt draw.dt', __normalize_dt_buttons);

        // Light/dark toggle. The dark palette is an overlay stylesheet, so
        // switching is just enabling or disabling that one <link> - instant, and
        // no repaint of the whole page. The cookie lets the server render the
        // correct state on the next load instead of flashing the wrong theme.
        (function () {
            var root = document.documentElement;
            var btn = document.getElementById('sb-theme-toggle');

            function paint() {
                var dark = root.getAttribute('data-theme') !== 'light';

                if (btn) {
                    var sun = btn.querySelector('.sb-theme-icon-sun');
                    var moon = btn.querySelector('.sb-theme-icon-moon');

                    if (sun) {
                        sun.style.display = dark ? '' : 'none';
                    }

                    if (moon) {
                        moon.style.display = dark ? 'none' : '';
                    }

                    btn.setAttribute('title', dark ? 'Switch to light mode' : 'Switch to dark mode');
                    btn.setAttribute('aria-pressed', dark ? 'false' : 'true');
                }
            }

            paint();

            // TinyMCE bakes its skin into a stylesheet it injects once at
            // init, so the editor keeps the old chrome after a theme switch.
            // There is no API to swap a skin, so tear the editor down and
            // build it again, carrying the content across.
            function retheme_tinymce() {
                if (typeof tinymce === 'undefined' || !tinymce.editors.length) {
                    return;
                }

                var light = root.getAttribute('data-theme') === 'light';
                var targets = [];

                tinymce.editors.slice().forEach(function (ed) {
                    targets.push({ id: ed.id, html: ed.getContent() });
                    ed.remove();
                });

                targets.forEach(function (t) {
                    tinymce.init({
                        selector: '#' + t.id,
                        skin: light ? 'oxide' : 'oxide-dark',
                        content_css: light ? 'default' : 'dark',
                        init_instance_callback: function (ed) {
                            ed.setContent(t.html);
                        },
                    });
                });
            }

            if (btn) {
                btn.addEventListener('click', function () {
                    var light = root.getAttribute('data-theme') !== 'light';
                    root.setAttribute('data-theme', light ? 'light' : 'dark');
                    document.cookie =
                        'sb_theme=' +
                        (light ? 'light' : 'dark') +
                        ';path=/;max-age=31536000;samesite=lax';
                    paint();
                    retheme_tinymce();
                });
            }
        })();

        // The sidebar scrolls independently and resets to the top on every page
        // load, so after clicking something far down the menu (Activity Log,
        // Payment by Age...) the highlighted item is off screen. Put the scroll
        // position back, then make sure the current item is actually visible.
        (function () {
            var bar = document.getElementById('side-bar');

            if (!bar) {
                return;
            }

            var KEY = 'sb-sidebar-scroll';

            try {
                var saved = parseInt(sessionStorage.getItem(KEY), 10);

                if (!isNaN(saved)) {
                    bar.scrollTop = saved;
                }
            } catch (e) {
                // private mode or blocked storage: fall through to the active item
            }

            // Save on click too, not only on scroll: clicking a link the menu
            // already had in view fires no scroll event, so there would be
            // nothing stored and the page would come back at the top.
            bar.addEventListener(
                'click',
                function () {
                    try {
                        sessionStorage.setItem(KEY, bar.scrollTop);
                    } catch (e) {
                        // ignore
                    }
                },
                true
            );

            var current =
                bar.querySelector('a.theme-sidebar-child-active') ||
                bar.querySelector('.theme-sidebar-active');

            if (current) {
                // Compare rectangles, not offsetTop: the item and the sidebar do
                // not share an offsetParent, so subtracting their offsetTops
                // gives a wrong figure and scrolls to the wrong place.
                var barBox = bar.getBoundingClientRect();
                var itemBox = current.getBoundingClientRect();

                if (itemBox.top < barBox.top || itemBox.bottom > barBox.bottom) {
                    // Sit it about a third down rather than flush against the edge.
                    bar.scrollTop += itemBox.top - barBox.top - bar.clientHeight / 3;
                }
            }

            // Submenus expand and fonts settle after ready, which changes the
            // sidebar's scrollHeight; a position restored before that lands
            // somewhere else. Put it back once the page has fully loaded.
            $(window).on('load', function () {
                try {
                    var again = parseInt(sessionStorage.getItem(KEY), 10);

                    if (!isNaN(again) && Math.abs(bar.scrollTop - again) > 2) {
                        bar.scrollTop = again;
                    }
                } catch (e) {
                    // ignore
                }
            });

            var pending = null;
            bar.addEventListener('scroll', function () {
                if (pending) {
                    return;
                }

                pending = setTimeout(function () {
                    pending = null;

                    try {
                        sessionStorage.setItem(KEY, bar.scrollTop);
                    } catch (e) {
                        // nothing to do; the active-item fallback still runs
                    }
                }, 150);
            });
        })();
        
        // $('.date_range').on('show.daterangepicker', function (ev, picker) {
        //     $(picker.container).insertAfter($(this));
        // });
   
    });
</script>



