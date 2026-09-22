//This file contains all common functionality for the application
$(document).on('submit', 'form', function (e) {
    if (!__is_online()) {
        e.preventDefault();
        toastr.error(LANG.not_connected_to_a_network);
        return false;
    }

    $(this).find('button[type="submit"]').attr('disabled', true);
});
$(document).ready(function () {
    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);

    $.ajaxSetup({
        beforeSend: function (jqXHR, settings) {
            if (!__is_online()) {
                toastr.error(LANG.not_connected_to_a_network);
                return false;
            }
            if (settings.url.indexOf('http') === -1) {
                settings.url = base_path + settings.url;
            }
        },
    });

    update_font_size();
    if ($('#status_span').length) {
        var status = $('#status_span').attr('data-status');
        if (status === '1') {
            toastr.success($('#status_span').attr('data-msg'));
        } else if (status == '' || status === '0') {
            toastr.error($('#status_span').attr('data-msg'));
        }
    }

    //Default setting for select2
    $.fn.select2.defaults.set('minimumResultsForSearch', 6);
    if ($('html').attr('dir') == 'rtl') {
        $.fn.select2.defaults.set('dir', 'rtl');
    }
    $.fn.datepicker.defaults.todayHighlight = true;
    $.fn.datepicker.defaults.autoclose = true;
    $.fn.datepicker.defaults.format = datepicker_date_format;

    //Toastr setting
    toastr.options.preventDuplicates = true;
    toastr.options.timeOut = '3000';

    //Play notification sound on success, error and warning
    toastr.options.onShown = function () {
        if ($(this).hasClass('toast-success')) {
            var audio = $('#success-audio')[0];
            if (audio !== undefined) {
                audio.play();
            }
        } else if ($(this).hasClass('toast-error')) {
            var audio = $('#error-audio')[0];
            if (audio !== undefined) {
                audio.play();
            }
        } else if ($(this).hasClass('toast-warning')) {
            var audio = $('#warning-audio')[0];
            if (audio !== undefined) {
                audio.play();
            }
        }
    };

    //Default setting for jQuey validator
    jQuery.validator.setDefaults({
        errorPlacement: function (error, element) {
            if (element.hasClass('select2') && element.parent().hasClass('input-group')) {
                error.insertAfter(element.parent());
            } else if (element.hasClass('select2')) {
                error.insertAfter(element.next('span.select2-container'));
            } else if (element.parent().hasClass('input-group')) {
                error.insertAfter(element.parent());
            } else if (element.parent().hasClass('multi-input')) {
                error.insertAfter(element.closest('.multi-input'));
            } else if (element.parent().hasClass('input_inline')) {
                error.insertAfter(element.parent());
            } else if (element.hasClass('upload-element')) {
                error.insertAfter(element.closest('.input-group'));
            } else {
                error.insertAfter(element);
            }
        },

        invalidHandler: function () {
            toastr.error(LANG.some_error_in_input_field);
        },
    });

    jQuery.validator.addMethod(
        'max-value',
        function (value, element, param) {
            var is_draft = false;
            if (
                $(element).hasClass('pos_quantity') &&
                $('select#status').length &&
                $('select#status').val() !== 'final'
            ) {
                is_draft = true;
            }
            return is_draft || this.optional(element) || !(param < __number_uf(value));
        },
        function (params, element) {
            return $(element).data('msg-max-value');
        }
    );

    jQuery.validator.addMethod('abs_digit', function (value, element) {
        return this.optional(element) || Number.isInteger(Math.abs(__number_uf(value)));
    });

    //Set global currency to be used in the application
    __currency_symbol = $('input#__symbol').val();
    __currency_thousand_separator = $('input#__thousand').val();
    __currency_decimal_separator = $('input#__decimal').val();
    __currency_symbol_placement = $('input#__symbol_placement').val();
    if ($('input#__precision').length > 0) {
        __currency_precision = $('input#__precision').val();
    } else {
        __currency_precision = 2;
    }

    if ($('input#__quantity_precision').length > 0) {
        __quantity_precision = $('input#__quantity_precision').val();
    } else {
        __quantity_precision = 2;
    }

    //Set page level currency to be used for some pages. (Purchase page)
    if ($('input#p_symbol').length > 0) {
        __p_currency_symbol = $('input#p_symbol').val();
        __p_currency_thousand_separator = $('input#p_thousand').val();
        __p_currency_decimal_separator = $('input#p_decimal').val();
    }

    __currency_convert_recursively($(document), $('input#p_symbol').length);

    // Simple function to remove currency symbol and HTML tags from string
    function __remove_currency_symbol(str) {
        // DataTables can pass numbers, objects, null - convert all to string
        if (typeof str !== 'string') {
            str = String(str);
        }

        // HTML REMOVAL: Simple regex to remove HTML tags
        str = str.replace(/<[^>]*>/g, '');

        // Check 1: Variable exists, Check 2: Has value, Check 3: Symbol present in string
        if (
            typeof __currency_symbol !== 'undefined' &&
            __currency_symbol &&
            str.includes(__currency_symbol)
        ) {
            // SIMPLE REPLACEMENT: Replace all occurrences of currency symbol with empty string
            str = str.split(__currency_symbol).join('');
        }

        return str.trim();
    }

    var buttons = [
        // {
        //     extend: 'copy',
        //     text: '<i class="fa fa-files-o" aria-hidden="true"></i> ' + LANG.copy,
        //     className: 'btn-sm',
        //     exportOptions: {
        //         columns: ':visible',
        //     },
        //     footer: true,
        // },
        {
            extend: 'csv',
            text: '<i class="fa fa-file-csv" aria-hidden="true"></i> ' + LANG.export_to_csv,
            className: 'tw-dw-btn-xs  tw-dw-btn tw-dw-btn-outline tw-my-2',
            exportOptions: {
                columns: ':visible',
                format: {
                    body: function (data, row, column, node) {
                        // Check if the node or its children have data-is_quantity="true"
                        var $node = $(node);
                        var $quantityElement = $node.find('[data-is_quantity="true"]');

                        if ($quantityElement.length > 0) {
                            return $quantityElement.attr('data-orig-value');
                        }
                        // Remove currency symbol from the cell data
                        return __remove_currency_symbol(data);
                    },
                    footer: function (data, row, column, node) {
                        // Remove currency symbol from the footer data
                        return __remove_currency_symbol(data);
                    },
                },
            },
            footer: true,
            // Tables marked `hide-footer` (e.g. product list) skip the footer in CSV.
            action: function (e, dt, button, config) {
                if ($(dt.table().node()).hasClass('hide-footer')) config.footer = false;
                $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
            },
        },
        {
            extend: 'excel',
            text: '<i class="fa fa-file-excel" aria-hidden="true"></i> ' + LANG.export_to_excel,
            className: 'tw-dw-btn-xs  tw-dw-btn tw-dw-btn-outline tw-my-2',
            exportOptions: {
                columns: ':visible',
                format: {
                    body: function (data, row, column, node) {
                        // Check if the node or its children have data-is_quantity="true"
                        var $node = $(node);
                        var $quantityElement = $node.find('[data-is_quantity="true"]');
                        if ($quantityElement.length > 0) {
                            return $quantityElement.attr('data-orig-value');
                        }
                        // Remove currency symbol from the cell data
                        return __remove_currency_symbol(data);
                    },
                    footer: function (data, row, column, node) {
                        // Remove currency symbol from the footer data
                        return __remove_currency_symbol(data);
                    },
                },
            },
            footer: true,
            // Tables marked `hide-footer` (e.g. product list) skip the footer in Excel.
            action: function (e, dt, button, config) {
                if ($(dt.table().node()).hasClass('hide-footer')) config.footer = false;
                $.fn.dataTable.ext.buttons.excelHtml5.action.call(this, e, dt, button, config);
            },
        },
        {
            extend: 'print',
            text: '<i class="fa fa-print" aria-hidden="true"></i> ' + LANG.print,
            className: 'tw-dw-btn-xs  tw-dw-btn tw-dw-btn-outline tw-my-2',
            exportOptions: {
                columns: ':visible',
                stripHtml: true,
            },
            footer: true,
            customize: function (win) {
                if ($('.print_table_part').length > 0) {
                    $($('.print_table_part').html()).insertBefore(
                        $(win.document.body).find('table')
                    );
                }
                if ($(win.document.body).find('table.hide-footer').length) {
                    $(win.document.body).find('table.hide-footer tfoot').remove();
                }
                __currency_convert_recursively($(win.document.body).find('table'));
            },
        },
        {
            extend: 'colvis',
            text: '<i class="fa fa-columns" aria-hidden="true"></i> ' + LANG.col_vis,
            className: 'tw-dw-btn-xs  tw-dw-btn tw-dw-btn-outline tw-my-2',
            // Custom-field columns are hidden on init (see the init.dt handler
            // below). Leaving them out of this list too stops them being
            // switched back on from the dropdown.
            columns: ':not(.sb-custom-field)',
        },
    ];

    // PDF export button (portrait). To show images in PDF, add `data-pdf-include` to the column's <th>.
    var pdf_btn = {
        extend: 'pdf',
        text: '<i class="fa fa-file-pdf" aria-hidden="true"></i> ' + LANG.export_to_pdf,
        className: 'tw-dw-btn-xs  tw-dw-btn tw-dw-btn-outline tw-my-2',
        exportOptions: {
            // Skip hidden columns. Skip "not-export" columns unless they have data-pdf-include.
            columns: function (idx, data, node) {
                return (
                    $(node).is(':visible') &&
                    (!$(node).hasClass('not-export') || $(node).data('pdfInclude'))
                );
            },
            format: {
                // Use the cached image for image cells, plain text for everything else.
                body: function (data, row, column, node) {
                    var img = $(node).find('img')[0];
                    var cached = img && window._pdfImageCache && window._pdfImageCache[img.src];
                    return cached || $(node).text().trim();
                },
            },
        },
        footer: true,
        // Tables marked `hide-footer` (e.g. product list) skip the footer in PDF.
        action: function (e, dt, button, config) {
            if ($(dt.table().node()).hasClass('hide-footer')) config.footer = false;
            $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
        },
        // Turn the image data into real images in the PDF.
        customize: function (doc) {
            // Smaller font + tighter margins so wide tables fit on the page.
            doc.defaultStyle.fontSize = 8;
            doc.pageMargins = [20, 20, 20, 20];
            doc.content.forEach(function (b) {
                if (!b.table) return;
                b.table.widths = b.table.body[0].map(function () {
                    return '*';
                });
                b.table.body.forEach(function (row) {
                    row.forEach(function (c, j) {
                        var v = typeof c === 'string' ? c : c && c.text;
                        if (v && v.indexOf('data:image') === 0)
                            row[j] = { image: v, width: 40, height: 40 };
                    });
                });
            });
        },
    };

    // PDF dropdown — Portrait / Landscape choices, styled to match the toolbar's outline button.
    if (!document.getElementById('pdf-dropdown-style')) {
        var pdfDropdownStyle = document.createElement('style');
        pdfDropdownStyle.id = 'pdf-dropdown-style';
        pdfDropdownStyle.textContent =
            // Panel — vertical stack, white background (kills AdminLTE's #00c0ef cyan).
            '.dt-button-collection.pdf-orient-collection{' +
            'display:flex !important;flex-direction:column !important;gap:6px !important;padding:6px !important;' +
            'background:#fff !important;border:1px solid rgba(0,0,0,.15) !important;border-radius:.375rem !important;' +
            'box-shadow:0 4px 6px -1px rgba(0,0,0,.1) !important;min-width:160px !important;' +
            'margin:0 !important;list-style:none !important;column-count:1 !important}' +
            '.pdf-orient-collection,.pdf-orient-collection *{outline:0 !important}' +
            '.pdf-orient-collection>li{all:unset !important;display:block !important}' +
            // Items — only direct <a>/<button> of <li>, never the <li> itself.
            '.pdf-orient-collection>li>a,.pdf-orient-collection>li>button{' +
            'all:unset !important;box-sizing:border-box !important;display:flex !important;align-items:center !important;' +
            'width:100% !important;height:1.5rem !important;padding:0 .5rem !important;' +
            'font-size:.75rem !important;font-weight:600 !important;color:#000 !important;' +
            'background:#fff !important;border:1px solid #000 !important;border-radius:.375rem !important;' +
            'cursor:pointer !important;text-align:left !important}' +
            '.pdf-orient-collection>li>a:hover,.pdf-orient-collection>li>a:focus,' +
            '.pdf-orient-collection>li>a:active,.pdf-orient-collection>li>a.active,' +
            '.pdf-orient-collection>li>button:hover,.pdf-orient-collection>li>button:focus,' +
            '.pdf-orient-collection>li>button:active,.pdf-orient-collection>li>button.active{' +
            'background:#1f2937 !important;color:#fff !important;border-color:#1f2937 !important;' +
            'box-shadow:none !important;text-shadow:none !important}' +
            '.pdf-orient-collection>li>a>*,.pdf-orient-collection>li>button>*{' +
            'background:transparent !important;color:inherit !important;box-shadow:none !important}';
        document.head.appendChild(pdfDropdownStyle);
    }

    var pdf_item_class = 'tw-dw-btn-xs tw-dw-btn tw-dw-btn-outline';

    var pdf_dropdown = {
        extend: 'collection',
        text:
            '<i class="fa fa-file-pdf" aria-hidden="true"></i> ' +
            LANG.export_to_pdf +
            ' <i class="fa fa-caret-down" aria-hidden="true"></i>',
        className: 'tw-dw-btn-xs tw-dw-btn tw-dw-btn-outline tw-my-2',
        collectionLayout: 'pdf-orient-collection',
        autoClose: true,
        buttons: [
            $.extend(true, {}, pdf_btn, {
                text: LANG.portrait,
                className: pdf_item_class,
            }),
            $.extend(true, {}, pdf_btn, {
                text: LANG.landscape,
                className: pdf_item_class,
                orientation: 'landscape',
                pageSize: 'A4',
            }),
        ],
    };

    if (non_utf8_languages.indexOf(app_locale) == -1) {
        buttons.push(pdf_dropdown);
    }

    if ($('#view_export_buttons').length < 1) {
        buttons = [];
    }
    /**
     * Remembered "Show N entries" choice.
     *
     * DataTables reads iDisplayLength once, when a table is built, so the
     * stored value has to be in place before that happens. common.js is
     * loaded ahead of app.js and the per-view scripts, so this ready handler
     * runs first and the default below is already correct by the time any
     * table initialises.
     *
     * localStorage rather than the session: the choice has to survive logging
     * out and back in, and a session store is cleared on logout. Every access
     * is guarded because localStorage throws in private mode and when site
     * data is blocked - a table that cannot remember its length should still
     * work normally.
     */
    var SB_PAGE_LENGTH_KEY = 'sb-datatable-page-length';

    function sb_stored_page_length() {
        try {
            var stored = parseInt(window.localStorage.getItem(SB_PAGE_LENGTH_KEY), 10);

            // -1 is the "All" option; anything else non-positive is junk.
            if (stored === -1 || stored > 0) {
                return stored;
            }
        } catch (error) {
            // fall through to the business default
        }

        return null;
    }

    var sb_page_length = sb_stored_page_length();

    /**
     * Swap the "Show N entries" control for a select2.
     *
     * A native <select> hands its popup to the browser: Chrome sizes the panel
     * from the control and lays the rows out itself, so `text-align` on the
     * options is applied but never honoured, and the panel cannot be made
     * narrower. select2 is already bundled and themed here, and renders the
     * list as ordinary DOM, so width and alignment become ours to set.
     *
     * DataTables listens for a native change event on this select, and select2
     * dispatches one, so paging and the length.dt handler below keep working.
     */
    /**
     * Hide the "Custom Field" columns on every table.
     *
     * UltimatePOS ships 4-10 spare custom-field columns on most listings
     * (contacts, products, purchases, sells, shipments and four reports).
     * Where the business has not named one, the header falls back to
     * "Custom Field N" or renders empty, so the tables carry a run of dead
     * columns. They are hidden rather than deleted from the markup: the
     * <th>, the <tfoot> cell and the columns entry have to stay in lockstep
     * or DataTables throws, and hiding is one change instead of fifty.
     *
     * Columns are matched on their data/name key (custom_field1,
     * product_custom_field3, shipping_custom_field_2 ...) rather than on the
     * header text, which is blank on several of these tables. A column the
     * business HAS named keeps its label but still carries the custom_field
     * key, so it is hidden too - that is the point of the request.
     *
     * The .sb-custom-field class goes on the header cell so the colvis
     * button's `:not(.sb-custom-field)` selector skips them as well.
     */
    var SB_CUSTOM_FIELD_KEY = /custom_field_?\d+$/i;

    function sb_custom_field_columns(settings) {
        var found = [];
        var cols = settings.aoColumns || [];
        for (var i = 0; i < cols.length; i++) {
            var key = cols[i].sName || (typeof cols[i].mData === 'string' ? cols[i].mData : '');
            if (key && SB_CUSTOM_FIELD_KEY.test(key)) found.push(i);
        }
        return found;
    }

    /* The class has to be on the header before Buttons constructs colvis,
       which happens during init - by the time init.dt fires the dropdown has
       already resolved `:not(.sb-custom-field)` against an unmarked header
       and listed every column. preInit runs early enough. */
    /**
     * Shrink-wrap every Action column and centre its buttons.
     *
     * .sb-col-fit is the existing shrink-to-fit treatment (width:1% plus
     * nowrap in saverbro-layout.css, and a scrollWidth measurement in
     * table-colresize.js for the tables that assert a fixed layout). It was
     * only spelled out by hand on a handful of tables, so everywhere else the
     * Action column took an equal share of the table width and its buttons
     * floated in a wide, half-empty cell.
     *
     * The column is found by its data/name key, which is the literal string
     * "action" on 24 of them and is locale-independent. Tables that build
     * their columns positionally (no key) are matched on the header text
     * instead - that fallback is English-only, but every such table also
     * carries the key, so it is a belt-and-braces path.
     *
     * The classes go on sClass rather than the cells directly so DataTables
     * reapplies them to every row it draws, including after paging and ajax
     * reloads.
     */
    function sb_action_columns(settings) {
        var found = [];
        var cols = settings.aoColumns || [];
        for (var i = 0; i < cols.length; i++) {
            var key = cols[i].sName || (typeof cols[i].mData === 'string' ? cols[i].mData : '');
            var isAction = /(^|\.)action$/i.test(key);
            if (!isAction && cols[i].nTh) {
                isAction = /^action$/i.test($(cols[i].nTh).text().replace(/\s+/g, ' ').trim());
            }
            if (isAction) found.push(i);
        }
        return found;
    }

    /**
     * Description columns: ranged left, and sized to their longest entry.
     *
     * Same sb-col-fit treatment as Action, for the opposite reason. The
     * ordinary content measurement in table-colresize.js stops at
     * MAX_CONTENT_WIDTH (320px), so a long description wrapped to two or
     * three lines no matter how wide the table was; sb-col-fit routes the
     * column through the uncapped scrollWidth measurement instead.
     *
     * Matched on the data/name key ("description", "t.description" ...) with
     * the header text as a fallback, so it picks up tables that build their
     * columns positionally.
     */
    function sb_description_columns(settings) {
        var found = [];
        var cols = settings.aoColumns || [];
        for (var i = 0; i < cols.length; i++) {
            var key = cols[i].sName || (typeof cols[i].mData === 'string' ? cols[i].mData : '');
            var isDesc = /(^|\.)description$/i.test(key);
            if (!isDesc && cols[i].nTh) {
                isDesc = /^description$/i.test($(cols[i].nTh).text().replace(/\s+/g, ' ').trim());
            }
            if (isDesc) found.push(i);
        }
        return found;
    }

    /* Add classes to a column without letting an existing alignment class
       fight the new one: .text-left and .text-center weigh the same, so
       leaving both on the cell lets stylesheet order decide, and in Bootstrap
       that hands the win to .text-center. */
    function sb_set_column_classes(settings, idx, add, drop) {
        var col = settings.aoColumns[idx];
        var cls = ' ' + (col.sClass || '') + ' ';
        (drop || []).forEach(function (c) {
            cls = cls.split(' ' + c + ' ').join(' ');
        });
        add.forEach(function (c) {
            if (cls.indexOf(' ' + c + ' ') === -1) cls += c + ' ';
        });
        col.sClass = $.trim(cls);
        if (col.nTh) {
            $(col.nTh)
                .removeClass((drop || []).join(' '))
                .addClass(add.join(' '));
        }
    }

    $(document).on('preInit.dt', function (event, settings) {
        try {
            sb_action_columns(settings).forEach(function (idx) {
                sb_set_column_classes(settings, idx, ['text-center', 'sb-col-fit']);
            });
            sb_description_columns(settings).forEach(function (idx) {
                sb_set_column_classes(
                    settings,
                    idx,
                    ['text-left', 'sb-col-fit'],
                    ['text-center', 'text-right']
                );
            });
        } catch (e) {
            /* No column metadata - the table keeps its layout. */
        }
    });

    $(document).on('preInit.dt', function (event, settings) {
        try {
            sb_custom_field_columns(settings).forEach(function (idx) {
                var th = settings.aoColumns[idx].nTh;
                if (!th) th = $(settings.nTHead).find('tr').last().children().get(idx);
                $(th).addClass('sb-custom-field');
            });
        } catch (e) {
            /* No column metadata - the table keeps its columns. */
        }
    });

    function sb_hide_custom_fields(settings) {
        if (!settings || settings._sbCustomFieldsDone) return;
        settings._sbCustomFieldsDone = true;
        try {
            var hide = sb_custom_field_columns(settings);
            if (!hide.length) return;
            var api = new $.fn.dataTable.Api(settings);
            hide.forEach(function (idx) {
                $(settings.aoColumns[idx].nTh).addClass('sb-custom-field');
            });
            api.columns(hide).visible(false, false);
            api.columns.adjust();

            /* Buttons builds the colvis child buttons while the table is
               initialising - before preInit can mark the headers - so the
               dropdown has already resolved `:not(.sb-custom-field)` against
               an unmarked header and listed all of them. Drop the entries
               for the columns just hidden. */
            try {
                var cv = api.buttons('.buttons-columnVisibility').nodes();
                /* This build of Buttons tags the child buttons with nothing
                   but their label, so they are matched on position: colvis
                   creates exactly one, in column order. Bail out if that
                   one-to-one relationship does not hold. */
                if (cv.length === settings.aoColumns.length) {
                    for (var h = hide.length - 1; h >= 0; h--) {
                        api.button($(cv[hide[h]])).remove();
                    }
                }
            } catch (e) {
                /* Table without export buttons - nothing to prune. */
            }
        } catch (e) {
            /* No column metadata - the table keeps its columns. */
        }
    }

    $(document).on('init.dt', function (event, settings) {
        sb_hide_custom_fields(settings);
    });

    /* Some pages (the reports) build their table before this file has bound
       the handlers above, so those tables never see init.dt. Sweep whatever
       already exists once the page settles; the _sbCustomFieldsDone flag
       keeps it from running twice on the same table. */
    $(function () {
        setTimeout(function () {
            try {
                $($.fn.dataTable.tables()).each(function () {
                    sb_hide_custom_fields($(this).DataTable().settings()[0]);
                });
            } catch (e) {
                /* No tables on this page. */
            }
        }, 0);
    });

    $(document).on('init.dt', function (event, settings) {
        $(settings.nTableWrapper)
            .find('.dataTables_length select')
            .not('.select2-hidden-accessible')
            .select2({
                // No search box - there are ten short numeric choices.
                minimumResultsForSearch: Infinity,
                width: 'resolve',
            })
            // containerCssClass/dropdownCssClass are ignored by the select2
            // build bundled here, so the hooks are attached directly. The
            // dropdown is appended to <body> when it opens, which is why it
            // cannot be reached by a descendant selector.
            .each(function () {
                $(this).next('.select2-container').addClass('sb-length-select2');
            })
            .on('select2:open', function () {
                $('.select2-container--open .select2-dropdown').addClass('sb-length-select2-drop');
            });
    });

    // Save whatever is picked, on any table. DataTables passes the new length
    // as the third argument of length.dt.
    $(document).on('length.dt', function (event, settings, len) {
        try {
            window.localStorage.setItem(SB_PAGE_LENGTH_KEY, len);
        } catch (error) {
            // nothing to do - it just will not be remembered
        }
    });

    //Datables
    jQuery.extend($.fn.dataTable.defaults, {
        //Uncomment below line to enable save state of datatable.
        //stateSave: true,
        fixedHeader: true,
        dom: '<"row margin-bottom-20 text-center"<"col-sm-1"l><"col-sm-8"B><"col-sm-3"f> r>tip',
        buttons: buttons,
        aLengthMenu: [
            [5, 10, 15, 25, 50, 100, 200, 500, 1000, -1],
            [5, 10, 15, 25, 50, 100, 200, 500, 1000, LANG.all],
        ],
        iDisplayLength:
            sb_page_length !== null
                ? sb_page_length
                : parseInt(__default_datatable_page_entries, 10),
        language: {
            searchPlaceholder: LANG.search + ' ...',
            search: '',
            lengthMenu: LANG.show + ' _MENU_ ' + LANG.entries,
            emptyTable: LANG.table_emptyTable,
            info: LANG.table_info,
            infoEmpty: LANG.table_infoEmpty,
            loadingRecords: LANG.table_loadingRecords,
            processing: LANG.table_processing,
            zeroRecords: LANG.table_zeroRecords,
            paginate: {
                first: LANG.first,
                last: LANG.last,
                next: LANG.next,
                previous: LANG.previous,
            },
        },
    });

    if ($('input#iraqi_selling_price_adjustment').length > 0) {
        iraqi_selling_price_adjustment = true;
    } else {
        iraqi_selling_price_adjustment = false;
    }

    //Input number
    $(document).on(
        'click',
        '.input-number .quantity-up, .input-number .quantity-down',
        function () {
            var input = $(this).closest('.input-number').find('input');
            var qty = __read_number(input);
            var step = 1;
            if (input.data('step')) {
                step = input.data('step');
            }
            var min = parseFloat(input.data('min'));
            var max = parseFloat(input.data('max'));

            if ($(this).hasClass('quantity-up')) {
                //if max reached return false
                if (typeof max != 'undefined' && qty + step > max) {
                    return false;
                }

                __write_number(input, qty + step);
                input.change();
            } else if ($(this).hasClass('quantity-down')) {
                //if max reached return false
                if (typeof min != 'undefined' && qty - step < min) {
                    return false;
                }

                __write_number(input, qty - step);
                input.change();
            }
        }
    );

    $('div.pos-tab-menu>div.list-group>a').click(function (e) {
        e.preventDefault();
        $(this).siblings('a.active').removeClass('active');
        $(this).addClass('active');
        var index = $(this).index();
        $('div.pos-tab>div.pos-tab-content').removeClass('active');
        $('div.pos-tab>div.pos-tab-content').eq(index).addClass('active');
    });

    $('.scroll-top-bottom').each(function () {
        $(this).topScrollbar();
    });

    $('.datetimepicker').datetimepicker({
        format: moment_date_format + ' ' + moment_time_format,
        ignoreReadonly: true,
    });
});

//Default settings for daterangePicker
var ranges = {};
ranges[LANG.today] = [moment(), moment()];
ranges[LANG.yesterday] = [moment().subtract(1, 'days'), moment().subtract(1, 'days')];
ranges[LANG.last_7_days] = [moment().subtract(6, 'days'), moment()];
ranges[LANG.last_30_days] = [moment().subtract(29, 'days'), moment()];
ranges[LANG.this_month] = [moment().startOf('month'), moment().endOf('month')];
ranges[LANG.last_month] = [
    moment().subtract(1, 'month').startOf('month'),
    moment().subtract(1, 'month').endOf('month'),
];
ranges[LANG.this_month_last_year] = [
    moment().subtract(1, 'year').startOf('month'),
    moment().subtract(1, 'year').endOf('month'),
];
ranges[LANG.this_year] = [moment().startOf('year'), moment().endOf('year')];
ranges[LANG.last_year] = [
    moment().startOf('year').subtract(1, 'year'),
    moment().endOf('year').subtract(1, 'year'),
];
ranges[LANG.this_financial_year] = [financial_year.start, financial_year.end];
ranges[LANG.last_financial_year] = [
    moment(financial_year.start._i).subtract(1, 'year'),
    moment(financial_year.end._i).subtract(1, 'year'),
];

var dateRangeSettings = {
    showDropdowns: true,
    linkedCalendars: false,
    ranges: ranges,
    startDate: financial_year.start,
    endDate: financial_year.end,
    locale: {
        cancelLabel: LANG.clear,
        applyLabel: LANG.apply,
        customRangeLabel: LANG.custom_range,
        format: moment_date_format,
        toLabel: '~',
    },
};

//Check for number string in input field, if data-decimal is 0 then don't allow decimal symbol and if no_neg then don't allow  negative value
$(document).on('keypress', 'input.input_number', function (event) {
    var is_decimal = $(this).data('decimal');

    if (is_decimal == 0) {
        if (__currency_decimal_separator == '.') {
            var regex = new RegExp(/^[0-9,-]+$/);
        } else {
            var regex = new RegExp(/^[0-9.-]+$/);
        }
    } else {
        var regex = new RegExp(/^[0-9.,-]+$/);
    }

    // Check for no negative values
    if (is_decimal == 'no_neg') {
        var regex = new RegExp(/^[0-9.,]+$/);
    }

    var key = String.fromCharCode(!event.charCode ? event.which : event.charCode);
    if (!regex.test(key)) {
        event.preventDefault();
        return false;
    }
});

//Select all input values on click
$(document).on('click', 'input', function (event) {
    $(this).select();
});

$(document).on('click', '.toggle-font-size', function (event) {
    localStorage.setItem('upos_font_size', $(this).data('size'));
    update_font_size();
});
$(document).on('click', '.sidebar-toggle', function () {
    var sidebar_collapse = localStorage.getItem('upos_sidebar_collapse');
    if ($('body').hasClass('sidebar-collapse')) {
        localStorage.setItem('upos_sidebar_collapse', 'false');
    } else {
        localStorage.setItem('upos_sidebar_collapse', 'true');
    }
});

//Ask for confirmation for links
$(document).on('click', 'a.link_confirmation', function (e) {
    e.preventDefault();
    swal({
        title: LANG.sure,
        icon: 'warning',
        buttons: true,
        dangerMode: true,
    }).then((confirmed) => {
        if (confirmed) {
            window.location.href = $(this).attr('href');
        }
    });
});

//Change max quantity rule if lot number changes
$('table#stock_adjustment_product_table tbody').on('change', 'select.lot_number', function () {
    var tr = $(this).closest('tr');
    var qty_element = tr.find('input.product_quantity');
    var qty_available_el = tr.find('.qty_available_text');

    var multiplier = 1;
    var unit_name = '';
    var sub_unit_length = tr.find('select.sub_unit').length;
    if (sub_unit_length > 0) {
        var select = tr.find('select.sub_unit');
        multiplier = parseFloat(select.find(':selected').data('multiplier'));
        unit_name = select.find(':selected').data('unit_name');
    }

    if ($(this).val()) {
        var lot_qty = $('option:selected', $(this)).data('qty_available');
        var max_err_msg = $('option:selected', $(this)).data('msg-max');

        if (sub_unit_length > 0) {
            lot_qty = lot_qty / multiplier;
            var lot_qty_formated = __number_f(lot_qty, false);
            max_err_msg = __translate('lot_max_qty_error', {
                max_val: lot_qty_formated,
                unit_name: unit_name,
            });
        }

        qty_element.attr('data-rule-max-value', lot_qty);
        qty_element.attr('data-msg-max-value', max_err_msg);

        qty_element.rules('add', {
            'max-value': lot_qty,
            messages: {
                'max-value': max_err_msg,
            },
        });
        if (qty_available_el.length) {
            qty_available_el.text(__currency_trans_from_en(lot_qty, false));
        }
    } else {
        var default_qty = qty_element.data('qty_available');
        var default_err_msg = qty_element.data('msg_max_default');

        if (sub_unit_length > 0) {
            default_qty = default_qty / multiplier;
            var lot_qty_formated = __number_f(default_qty, false);
            default_err_msg = __translate('pos_max_qty_error', {
                max_val: lot_qty_formated,
                unit_name: unit_name,
            });
        }

        qty_element.attr('data-rule-max-value', default_qty);
        qty_element.attr('data-msg-max-value', default_err_msg);

        qty_element.rules('add', {
            'max-value': default_qty,
            messages: {
                'max-value': default_err_msg,
            },
        });

        if (qty_available_el.length) {
            qty_available_el.text(__currency_trans_from_en(default_qty, false));
        }
    }
    qty_element.trigger('change');
});
//POS header: same bootstrap tooltip on every action button, instead of the
//native title tooltip most of them were falling back to.
//Bound on body, not document: the popover handler in app.js returns false for
//.popover-default buttons, which stops the event before it reaches document.
var pos_header_tooltips = '.pos-header a[title], .pos-header button[title]';
$('body').on('mouseenter', pos_header_tooltips, function () {
    var el = $(this);
    if (!el.data('bs.tooltip')) {
        el.tooltip({
            container: 'body',
            placement: el.data('placement') || 'bottom',
            trigger: 'manual',
        });
    }
    el.tooltip('show');
});
$('body').on('mouseleave click', pos_header_tooltips, function () {
    $(this).tooltip('hide');
});
$('button#return_sale').click(function () {
    $(this).popover('toggle');
});
$('button#service_staff_replacement').click(function () {
    $(this).popover('toggle');
});

jQuery.validator.addMethod(
    'min-value',
    function (value, element, param) {
        return this.optional(element) || !(param > __number_uf(value));
    },
    function (params, element) {
        return $(element).data('min-value');
    }
);

$(document).on('click', '.view_uploaded_document', function (e) {
    e.preventDefault();
    var src = $(this).data('href');
    var html =
        '<div class="modal-dialog" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div><div class="modal-body"><img src="' +
        src +
        '" class="img-responsive" alt="Uploaded Document"></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Close</button> <a href="' +
        src +
        '" class="btn btn-success" download=""><i class="fa fa-download"></i> Download</a></div></div></div>';
    $('div.view_modal').html(html).modal('show');
});

$(document).on('click', '#accordion .box-header', function (e) {
    if (e.target.tagName == 'A' || e.target.tagName == 'I') {
        return false;
    }
    $(this).find('.box-title a').click();
});

$(document).on('shown.bs.modal', '.contains_select2, .view_modal', function () {
    $(this)
        .find('.select2')
        .each(function () {
            var $p = $(this).parent();
            $(this).select2({ dropdownParent: $p });
        });
});

//common configuration : tinyMCE editor

//Language packs shipped in public/js/lang/tiny. English is built into tinyMCE
//and has no pack; pointing language_url at a file that 404s leaves the
//translation table empty, which renders every label as "!not found!".
var tinymce_language_packs = [
    'ar',
    'ce',
    'de',
    'es',
    'fr',
    'id',
    'nl',
    'pt',
    'ro',
    'sq',
    'tr',
    'vi',
];

//The theme is written onto <html data-theme> by a script in the page head,
//before any of this runs, so it is safe to read at load time.
function __sb_is_light() {
    return document.documentElement.getAttribute('data-theme') === 'light';
}

var tinymce_defaults = {
    height: 300,
    theme: 'silver',
    //Match the app theme; skins are served from public/js/skins. Read at init
    //time from the same data-theme attribute the stylesheets are keyed on, so
    //the editor chrome and the text area follow light/dark like everything else.
    skin: __sb_is_light() ? 'oxide' : 'oxide-dark',
    //hide the "Powered by Tiny" label in the status bar
    branding: false,
    content_css: __sb_is_light() ? 'default' : 'dark',
    plugins: [
        'advlist autolink link image lists charmap print preview hr anchor pagebreak',
        'searchreplace wordcount visualblocks visualchars code fullscreen insertdatetime media nonbreaking',
        'table template paste help',
    ],
    toolbar:
        'undo redo | styleselect | bold italic | alignleft aligncenter alignright alignjustify |' +
        ' bullist numlist outdent indent | link image | print preview media fullpage | ' +
        'forecolor backcolor',
    menu: {
        favs: { title: 'My Favorites', items: 'code | searchreplace' },
    },
    menubar: 'favs file edit view insert format tools table help',
};

if ($.inArray(app_locale, tinymce_language_packs) !== -1) {
    tinymce_defaults.language = app_locale;
    tinymce_defaults.language_url = base_path + '/js/lang/tiny/' + app_locale + '.js';
}

tinymce.overrideDefaults(tinymce_defaults);

// Prevent Bootstrap dialog from blocking focusin
$(document).on('focusin', function (e) {
    if (
        $(e.target).closest(
            '.tox-tinymce-aux, .moxman-window, .tam-assetmanager-root, .select2-container'
        ).length
    ) {
        e.stopImmediatePropagation();
    }
});

//search parameter in url
function urlSearchParam(param) {
    var results = new RegExp('[?&]' + param + '=([^&#]*)').exec(window.location.href);
    if (results == null) {
        return null;
    } else {
        return results[1];
    }
}

// For dropdown hidden issue
// (function() {
//   var dropdownMenu;
//   $('table').on('show.bs.dropdown', function(e) {
//     dropdownMenu = $(e.target).find('.dropdown-menu');
//     $('body').append(dropdownMenu.detach());
//     var eOffset = $(e.target).offset();
//     if(dropdownMenu.hasClass('dropdown-menu-right')) {
//         dropdownMenu.css({
//             'display': 'block',
//             'top': eOffset.top + $(e.target).outerHeight(),
//             'left': 'auto',
//             'right': 0
//         });
//     } else {
//         dropdownMenu.css({
//             'display': 'block',
//             'top': eOffset.top + $(e.target).outerHeight(),
//             'left': eOffset.left
//         });
//     }
//   });
//   $('table').on('hide.bs.dropdown', function(e) {
//     $(e.target).append(dropdownMenu.detach());
//     dropdownMenu.hide();
//   });
// })();

function updateOnlineStatus() {
    if (!__is_online()) {
        $('#online_indicator').removeClass('text-success');
        $('#online_indicator').addClass('text-danger');
    } else {
        $('#online_indicator').removeClass('text-danger');
        $('#online_indicator').addClass('text-success');
    }
}

$(document).on('change', '.cash_denomination', function () {
    var total = 0;
    var table = $(this).closest('table');
    table.find('tbody tr').each(function () {
        var denomination = parseFloat($(this).find('.cash_denomination').attr('data-denomination'));
        var count = $(this).find('.cash_denomination').val()
            ? parseInt($(this).find('.cash_denomination').val())
            : 0;
        var subtotal = denomination * count;
        total = total + subtotal;
        $(this).find('span.denomination_subtotal').text(__currency_trans_from_en(subtotal, true));
    });

    table.find('span.denomination_total').text(__currency_trans_from_en(total, true));
    table.find('input.denomination_total_amount').val(total);
});

//autofocus select2 search input
let forceFocusFn = function () {
    // Gets the search input of the opened select2
    var searchInput = document.querySelector('.select2-container--open .select2-search__field');
    // If exists
    if (searchInput) searchInput.focus(); // focus
};

// Every time a select2 is opened
$(document).on('select2:open', () => {
    // We use a timeout because when a select2 is already opened and you open a new one, it has to wait to find the appropiate
    setTimeout(() => forceFocusFn(), 200);
});

function copyToClipboard(element_id) {
    var temp = $('<input>');
    $('body').append(temp);
    temp.val($('#' + element_id).text()).select();
    document.execCommand('copy');
    temp.remove();
    toastr.success(LANG.copied_to_clipboard);
}

// This function escapes HTML characters in a given string to prevent XSS attacks.
function escapeHtml(str) {
    if (typeof str !== 'string') return '';
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Sidebar interactions (search, dropdowns, mobile toggle, collapse)
$(function () {
    // --- Sidebar menu search filter ---
    function filterSidebar(q) {
        q = q.trim().toLowerCase();
        var anyVisible = false;

        $('#side-bar')
            .children()
            .each(function () {
                if (!q) {
                    $(this).show();
                    anyVisible = true;
                } else {
                    var match = $(this).text().toLowerCase().indexOf(q) !== -1;
                    $(this).toggle(match);
                    if (match) anyVisible = true;
                }
            });

        $('#sidebar-no-results').toggleClass('tw-hidden', anyVisible || !q);
        $('#sidebar-search-clear').toggleClass('tw-hidden', !q);
    }

    $('#sidebar-search').on('input', function () {
        filterSidebar($(this).val());
    });

    $('#sidebar-search-clear').on('click', function () {
        $('#sidebar-search').val('').focus();
        filterSidebar('');
    });

    // --- Sidebar dropdown toggle ---
    $(document).on('click', '.drop_down', function (event) {
        event.preventDefault();
        var $chiled = $(this).next('.chiled');
        $('.chiled').not($chiled).slideUp();
        $chiled.slideToggle(function () {
            $('.svg').each(function () {
                var $currentSvgElement = $(this);
                if ($currentSvgElement.closest('.drop_down').next('.chiled').is(':visible')) {
                    $currentSvgElement.html(
                        '<path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M6 9l6 6l6 -6" />'
                    );
                } else {
                    $currentSvgElement.html(
                        '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 6l-6 6l6 6" />'
                    );
                }
            });
        });
    });

    // --- Sidebar mobile open / overlay close ---
    $(document).on('click', '.small-view-button', function () {
        $('.side-bar').addClass('small-view-side-active');
        $('.overlay').fadeIn('slow');
    });

    $(document).on('click', '.overlay', function () {
        $('.overlay').fadeOut('slow');
        $('.side-bar').removeClass('small-view-side-active');
    });

    // --- Sidebar responsive resize ---
    $(window).on('resize', function () {
        if ($(window).width() >= 992) {
            $('.overlay').fadeOut('slow');
            $('.side-bar').removeClass('small-view-side-active');
        }
        if ($('.side-bar').hasClass('small-view-side-active')) {
            $('.overlay').fadeIn('slow');
        }
    });

    // --- Sidebar collapse toggle ---
    $(document).on('click', '.side-bar-collapse', function () {
        $('.side-bar').toggle('slow');
    });
});

/**
 * Keep body attached dropdowns glued to their input while the content scrolls.
 *
 * daterangepicker appends its calendar to <body>, positions it once from
 * element.offset() and only re-runs move() on window resize. The content area
 * scrolls inside #scrollable-container rather than the window, so without this
 * the calendar stays put while its input scrolls away.
 *
 * select2 walks its own scroll parents, and datetimepicker places its widget
 * next to the input, so neither needs help here.
 */
$(function () {
    var $scroll_area = $('#scrollable-container');

    if (!$scroll_area.length) {
        return;
    }

    var open_pickers = [];

    $(document).on('show.daterangepicker', function (e, picker) {
        if (picker && $.inArray(picker, open_pickers) === -1) {
            open_pickers.push(picker);
        }
    });

    $(document).on('hide.daterangepicker', function (e, picker) {
        open_pickers = $.grep(open_pickers, function (open_picker) {
            return open_picker !== picker;
        });
    });

    $scroll_area.on('scroll', function () {
        if (!open_pickers.length) {
            return;
        }

        //remove() destroys a picker without firing hide or clearing isShowing,
        //so drop the ones whose input or calendar has left the page
        open_pickers = $.grep(open_pickers, function (picker) {
            return (
                picker.element &&
                $.contains(document.documentElement, picker.element[0]) &&
                picker.container &&
                $.contains(document.documentElement, picker.container[0])
            );
        });

        var area_rect = $scroll_area[0].getBoundingClientRect();

        $.each(open_pickers, function (index, picker) {
            if (!picker.isShowing) {
                return;
            }

            var input_rect = picker.element[0].getBoundingClientRect();
            //Where the top of the calendar lands once it follows the input
            var calendar_top =
                picker.drops == 'up'
                    ? input_rect.top - picker.container.outerHeight()
                    : input_rect.bottom;

            //The top navbar is a static block, so the calendar (absolute, z-index
            //3001) paints straight over it. Close instead of letting it ride up.
            if (calendar_top < area_rect.top || input_rect.top > area_rect.bottom) {
                picker.hide();

                return;
            }

            picker.move();
        });
    });
});

/**
 * Centre a DataTable that is narrower than its scroller.
 *
 * Once columns are hidden a scrollX table can be much narrower than the space
 * it sits in, and it was left flush against the left edge with dead space to
 * its right. Auto margins on the tables cannot fix this: DataTables sizes
 * .dataTables_scrollHeadInner independently of the table inside it (on the
 * users list, 708px of wrapper around a 776px table), so centring the wrapper
 * moves the header 34px away from the body.
 *
 * Padding the three scroll panes by the same amount shifts header, body and
 * footer together, so they stay in lockstep whatever the slack. Padding is
 * cleared before measuring, otherwise each pass would measure the width left
 * over from the previous one.
 */
function sb_centre_narrow_table(wrapper) {
    var $wrapper = $(wrapper);
    var $body = $wrapper.find('.dataTables_scrollBody').first();

    // No scroll panes means the table is not using scrollX, so there is
    // nothing to pad - the table itself can simply be centred. Auto margins
    // are inert once it fills or overflows its container, so this only bites
    // in the squashed case.
    if (!$body.length) {
        $wrapper.find('table.dataTable').first().css({
            'margin-left': 'auto',
            'margin-right': 'auto',
        });

        return;
    }

    var $table = $body.children('table').first();

    if (!$table.length) {
        return;
    }

    var $panes = $wrapper.find(
        '.dataTables_scrollHead, .dataTables_scrollBody, .dataTables_scrollFoot'
    );

    $panes.css({ 'padding-left': '', 'padding-right': '' });

    // Measure against .dataTables_scroll, which this function never pads.
    // Reading the padded scroll body instead makes each pass measure the
    // width left over from the previous one and the table drifts left.
    var $scroll = $body.parent().hasClass('dataTables_scroll')
        ? $body.parent()
        : $wrapper;
    var slack = $scroll[0].clientWidth - $table[0].offsetWidth;

    if (slack > 2) {
        var pad = Math.floor(slack / 2) + 'px';
        $panes.css({ 'padding-left': pad, 'padding-right': pad });
    }
}

$(document).on('draw.dt column-visibility.dt', function (e) {
    var wrapper = $(e.target).closest('.dataTables_wrapper');

    // Deferred a tick: on the draw itself DataTables has not finished sizing
    // the columns, so the table still reports its pre-draw width and the
    // padding comes out half what it should be.
    setTimeout(function () {
        sb_centre_narrow_table(wrapper);
    }, 0);
});

/**
 * Re-fit every visible DataTable after the window is resized.
 *
 * DataTables measures column widths once, at init. Resize the window and those
 * widths stay as they were, so a table sized in a narrow window stays narrow in
 * a wide one - columns keep their cramped widths and long values wrap a word
 * per line instead of the table simply scrolling. columns.adjust() recomputes
 * them against the new container.
 *
 * Debounced because resize fires continuously while dragging, and each adjust
 * forces a full re-measure of every table on the page.
 */
var sb_resize_timer = null;

$(window).on('resize', function () {
    clearTimeout(sb_resize_timer);

    sb_resize_timer = setTimeout(function () {
        if (!$.fn.dataTable) {
            return;
        }

        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();

        // Re-centre afterwards. A resize is exactly when a table stops
        // matching its container - widen the window and a table that used to
        // overflow can end up narrower than the space it sits in - and
        // columns.adjust() only recomputes widths, it does not reposition.
        // Deferred so the adjust above has finished writing them.
        setTimeout(function () {
            $('.dataTables_wrapper').each(function () {
                sb_centre_narrow_table(this);
            });
        }, 0);
    }, 250);
});
