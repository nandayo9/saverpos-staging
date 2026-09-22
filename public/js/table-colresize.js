/**
 * Drag-to-resize columns for every DataTable in the app.
 *
 * DataTables 1.10 has no built-in column resizing and this project has no
 * package manager to pull a plugin in, so this is self-contained.
 *
 * The tricky part is scrollX mode: header, body and footer live in three
 * separate <table> elements, so one resize has to update the matching cell in
 * each of them plus every wrapper width, or the columns drift out of alignment.
 * The footer is also allowed to use colspans, so its cell is located by walking
 * cumulative colspan rather than by index.
 */
(function ($) {
    'use strict';

    if (!$ || !$.fn || !$.fn.dataTable) {
        return;
    }

    var MIN_WIDTH = 40;
    var HANDLE = 'sb-col-resizer';

    // Modifier for the handle on the first column's left edge. It grows the
    // column when dragged left, so its delta runs opposite to every other
    // handle, which grows its column when dragged right.
    var HANDLE_START = 'sb-col-resizer--start';

    // Breathing room added to DataTables' content-fit measurement. A multiplier
    // is wrong here: headings are nowrap, so a column holding a long heading is
    // already fitted to it, and scaling that again runs to several hundred px.
    var EXTRA_ROOM = 56;

    // No column starts narrower than this, so short headings like "Pay term"
    // still get a usable amount of room.
    var MIN_START_WIDTH = 120;

    // Room added beside a heading for the sort arrows and the resize handle,
    // which sit inline after the text.
    var LABEL_ALLOWANCE = 28;

    // Padding around the longest value in a column, and the ceiling on how far
    // one long value is allowed to widen its column.
    var CONTENT_ALLOWANCE = 24;
    var MAX_CONTENT_WIDTH = 320;

    function px(value) {
        return Math.round(value) + 'px';
    }

    /** The three tables + width-bearing wrappers that make up one DataTable. */
    function regions(api) {
        var wrapper = $(api.table().container());
        return {
            wrapper: wrapper,
            headTable: wrapper.find('.dataTables_scrollHead table').first(),
            bodyTable: $(api.table().node()),
            footTable: wrapper.find('.dataTables_scrollFoot table').first(),
            headInner: wrapper.find('.dataTables_scrollHeadInner').first(),
            footInner: wrapper.find('.dataTables_scrollFootInner').first(),
        };
    }

    /**
     * Swallow clicks in the capture phase until explicitly released.
     *
     * The trap has to stay armed for the whole drag, however long the user
     * takes over it. Timing it from mousedown means a leisurely drag outlives
     * the trap, the trailing click reaches the <th>, and DataTables sorts and
     * refetches - which is exactly the "table refreshes when I drag" symptom.
     */
    function trapClicks() {
        var released = false;

        function swallow(event) {
            event.stopPropagation();
            event.preventDefault();
        }

        function remove() {
            if (!released) {
                released = true;
                document.removeEventListener('click', swallow, true);
                $(window).off('blur.sbcoltrap', remove);
            }
        }

        document.addEventListener('click', swallow, true);

        // If the pointer is released outside the window there may be no mouseup
        // to release the trap, and a permanently armed trap would eat every
        // click on the page.
        $(window).on('blur.sbcoltrap', remove);

        return function release() {
            // The click is dispatched right after mouseup, so yield once to let
            // it arrive and be swallowed before the listener is torn down.
            setTimeout(remove, 0);
        };
    }

    /** Footer cells may span several columns, so index alone is not enough. */
    function footCell(footRow, index) {
        var seen = 0;
        var match = null;

        footRow.children().each(function () {
            var span = parseInt($(this).attr('colspan') || 1, 10);
            if (index >= seen && index < seen + span) {
                match = $(this);
                return false;
            }
            seen += span;
        });

        return match;
    }

    /**
     * Read and write the same box. jQuery's outerWidth() is border-box while
     * css('width') writes content-box, so mixing them re-adds padding on every
     * call and the header slowly drifts away from the body.
     */
    /**
     * Inline widths have to be written with !important.
     *
     * .sb-col-fit declares `width: 1% !important`, the shrink-to-fit idiom,
     * which only means "as narrow as the content" under auto layout. Once the
     * table is switched to fixed layout that 1% is taken literally and the
     * column collapses, so the width computed here has to outrank it - and
     * only an important inline declaration beats an important stylesheet one.
     */
    function setWidth(el, value) {
        if (!el || !el.length) {
            return;
        }
        el[0].style.setProperty('width', px(value), 'important');
    }

    function widen(el, delta) {
        if (!el || !el.length) {
            return;
        }
        setWidth(el, el.width() + delta);
    }

    function resizeColumn(r, index, delta) {
        // The body is authoritative: a cell refuses to shrink below its own
        // content, so resize there first and mirror whatever the browser
        // actually granted. Driving the header independently lets the two
        // tables disagree, which is what knocks the columns out of alignment.
        // table-layout:fixed (see saverbro-dark-pos.css) makes these widths
        // authoritative, so the same delta applied to each table keeps the
        // three of them in lockstep.
        widen(r.headTable.find('thead tr').first().children().eq(index), delta);
        widen(r.bodyTable.find('thead tr').first().children().eq(index), delta);

        r.footTable
            .add(r.bodyTable)
            .find('tfoot tr')
            .each(function () {
                var cell = footCell($(this), index);
                widen(cell, delta);
            });

        [r.headTable, r.bodyTable, r.footTable, r.headInner, r.footInner].forEach(function (el) {
            widen(el, delta);
        });
    }

    /**
     * DataTables sizes each column to fit its content, which leaves them
     * cramped. Scale those measurements up so every column starts roomier while
     * keeping their relative proportions - Address still gets more room than
     * Pay term. A flat CSS min-width cannot do this: once every column is
     * pushed to the same floor DataTables equalises them all.
     *
     * Every width is read before any is written, because writing one reflows
     * the table and would corrupt the measurements still to be taken.
     */
    var STORAGE_PREFIX = 'sb-col-widths:';

    /**
     * Widths are remembered per table id, so a column dragged wider stays wider
     * across refreshes, logouts and browser restarts.
     *
     * Every access is guarded: localStorage throws in private mode and when
     * site data is blocked, and a table that cannot remember its widths should
     * still work normally rather than break the page.
     */
    function storageKey(r) {
        var id = r.bodyTable.attr('id');

        return id ? STORAGE_PREFIX + id : null;
    }

    function readSavedWidths(r, expectedCount) {
        var key = storageKey(r);

        if (!key) {
            return null;
        }

        try {
            var stored = JSON.parse(window.localStorage.getItem(key));

            // A stale entry from before a column was added or hidden would
            // misalign every column after it, so only use an exact match.
            if (Array.isArray(stored) && stored.length === expectedCount) {
                return stored;
            }
        } catch (error) {
            return null;
        }

        return null;
    }

    function saveWidths(r, headerRow) {
        var key = storageKey(r);

        if (!key) {
            return;
        }

        try {
            window.localStorage.setItem(
                key,
                JSON.stringify(
                    headerRow
                        .children()
                        .map(function () {
                            return Math.round($(this).width());
                        })
                        .get()
                )
            );
        } catch (error) {
            // Nothing to do - the widths just will not be remembered.
        }
    }

    /**
     * How wide the heading text is on a single line.
     *
     * Measured in a detached probe rather than from the cell itself: a cell's
     * scrollWidth is never smaller than the cell, so reading it from an already
     * widened column reports the column's width instead of the text's, and each
     * pass inflates the column further.
     */
    function labelWidth(th) {
        return textWidth(th.text().trim(), th);
    }

    function textWidth(text, th) {
        var style = window.getComputedStyle(th[0]);
        var probe = $('<span>')
            .text(text)
            .css({
                position: 'absolute',
                top: '-9999px',
                left: '-9999px',
                visibility: 'hidden',
                whiteSpace: 'nowrap',
                fontFamily: style.fontFamily,
                fontSize: style.fontSize,
                fontWeight: style.fontWeight,
                letterSpacing: style.letterSpacing,
                textTransform: style.textTransform,
            })
            .appendTo(document.body);

        var width = probe[0].getBoundingClientRect().width;
        probe.remove();

        var padding = parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);

        return Math.ceil(width + padding);
    }

    /**
     * DataTables' own measurements, captured once.
     *
     * It re-measures on every adjust, so reading live widths would mean scaling
     * an already-scaled column and compounding it on each pass. The first
     * reading is taken while the widths are still DataTables' own.
     */
    function baseWidths(r, headerRow) {
        var stored = r.bodyTable.data('sbBaseWidths');
        var count = headerRow.children().length;

        if (stored && stored.length === count) {
            return stored;
        }

        var widths = headerRow
            .children()
            .map(function () {
                var th = $(this);
                var index = th.index();

                // Widest value in the column, measured on one line. Without
                // this a wrapping column is only ever sized to its longest
                // word, so an address sits on three lines forever.
                var widest = '';
                var sampleCell = null;
                r.bodyTable.find('tbody tr').each(function () {
                    var cell = $(this).children().eq(index);
                    var text = cell.text().trim();
                    if (text.length > widest.length) {
                        widest = text;
                        sampleCell = cell;
                    }
                });

                return {
                    fitted: th.width(),
                    // Measured before fixed layout is asserted, so for an
                    // .sb-col-fit column this is its true shrink-to-fit width.
                    fit: th.hasClass('sb-col-fit'),
                    label: labelWidth(th),
                    // Measured against a body cell, not the heading: headings
                    // are bold and uppercased, so they would overstate it.
                    content: widest && sampleCell ? textWidth(widest, sampleCell) : 0,
                };
            })
            .get();

        // Only keep the reading once there is something to read. widenColumns
        // can run on the very first draw, while the body still holds the
        // "Processing..." placeholder, and a set of measurements taken then
        // records content: 0 for every column and would be cached for good.
        if (r.bodyTable.find('tbody tr').length) {
            r.bodyTable.data('sbBaseWidths', widths);
        }

        return widths;
    }

    function widenColumns(r, headerRow) {
        var headCells = headerRow.children();
        var bodyCells = r.bodyTable.find('thead tr').first().children();

        // Every column gets room for its content plus a fixed margin, and never
        // less than its heading needs to sit on one line.
        // Widths the user set by dragging win over anything measured.
        var saved = readSavedWidths(r, headCells.length);
        var bases = baseWidths(r, headerRow);

        var targets = saved
            ? saved.map(function (width, index) {
                  // A shrink-to-fit column is never restored from a saved
                  // width. Action columns have to hug their buttons always,
                  // and a width dragged (or stored) before the column became
                  // shrink-to-fit would keep it wide forever. The real width
                  // is measured below. Every other column keeps exactly what
                  // the user dragged it to.
                  if (bases[index] && bases[index].fit) {
                      return MIN_WIDTH;
                  }

                  // Saved widths win, but never below the heading: an entry saved
                  // before the table gained horizontal scrolling (or before a
                  // heading got longer) would otherwise clip that heading for
                  // good, since table-layout is fixed.
                  return Math.max(width, bases[index].label + LABEL_ALLOWANCE);
              })
            : bases.map(function (base) {
                  // A shrink-to-fit column is sized to exactly what it holds -
                  // a button pair, say - so none of the roomier minimums below
                  // apply to it. Its real width is measured further down,
                  // because the cached reading is taken on the first draw,
                  // before there are any rows to measure against.
                  if (base.fit) {
                      return MIN_WIDTH;
                  }

                  var want = Math.max(
                      MIN_START_WIDTH,
                      Math.round(base.fitted) + EXTRA_ROOM,
                      base.label + LABEL_ALLOWANCE,
                      // Fit the longest value on one line, but capped: one stray long
                      // note in a column should not push the table out by 800px.
                      Math.min(base.content + CONTENT_ALLOWANCE, MAX_CONTENT_WIDTH)
                  );

                  // The cap above only bounded the content term, so a column
                  // whose fitted measurement was itself huge - a full postal
                  // address, say - still ran away with the table. Bound the
                  // result, but never below what the heading needs.
                  return Math.max(base.label + LABEL_ALLOWANCE, Math.min(want, MAX_CONTENT_WIDTH));
              });

        if (!targets.length) {
            return;
        }

        // Shrink-to-fit columns: measure what the cells actually need.
        //
        // scrollWidth reports the content width even while the cell is
        // clipped, so this reads the same figure whether the column is
        // currently too narrow or already correct - no compounding across
        // redraws. This runs whether or not there are saved widths: the
        // saved branch above deliberately leaves these columns at MIN_WIDTH
        // so the measurement taken here is what they end up with.
        headCells.each(function (index) {
            if (!$(this).hasClass('sb-col-fit')) {
                return;
            }

            var need = this.scrollWidth;

            r.bodyTable.find('tbody tr').each(function () {
                var cell = this.children[index];

                if (cell && cell.scrollWidth > need) {
                    need = cell.scrollWidth;
                }
            });

            if (need) {
                targets[index] = need;
            }
        });

        var footRows = r.footTable.add(r.bodyTable).find('tfoot tr');

        targets.forEach(function (width, index) {
            setWidth(headCells.eq(index), width);
            setWidth(bodyCells.eq(index), width);
        });

        // The footer's "Total:" cell spans six columns, so it needs the sum of
        // everything it covers or the totals drift out of line with the data.
        //
        // Sum the *rendered* widths, not the targets: css('width') is
        // content-box, so each real column also carries its padding. Adding six
        // content widths together loses six lots of padding, which is exactly
        // how far the footer ends up shifted.
        var rendered = headCells
            .map(function () {
                return this.getBoundingClientRect().width;
            })
            .get();

        footRows.each(function () {
            var column = 0;

            $(this)
                .children()
                .each(function () {
                    var cell = $(this);
                    var span = parseInt(cell.attr('colspan') || 1, 10);
                    var width = 0;

                    for (var i = column; i < column + span && i < rendered.length; i++) {
                        width += rendered[i];
                    }

                    if (width) {
                        // Convert the border-box total back to the content-box
                        // value css('width') expects.
                        var chrome = cell.outerWidth() - cell.width();
                        cell.css('width', px(Math.max(0, width - chrome)));
                    }

                    column += span;
                });
        });

        // Declared widths only bind under fixed layout; the default auto layout
        // redistributes them from cell content, so a dragged column springs
        // straight back. saverbro-dark-pos.css sets this for the three
        // scroll-mode tables, but a table without scrollX has no such wrapper
        // and was left on auto - which is why those tables could not be
        // resized at all. Widths have just been written above, so this is the
        // point where fixed layout is safe to assert.
        //
        // .sb-table-fit is the exception: it shrink-wraps to its content by
        // design and needs auto layout to do it.
        if (!r.bodyTable.hasClass('sb-table-fit')) {
            r.bodyTable.css('table-layout', 'fixed');
        }

        var total = targets.reduce(function (sum, width) {
            return sum + width;
        }, 0);

        [r.headTable, r.bodyTable, r.footTable, r.headInner, r.footInner].forEach(function (el) {
            if (el && el.length) {
                el.css('width', px(total));
            }
        });
    }

    function attach(api) {
        var r = regions(api);

        // With scrollX the visible headers are in the scrollHead table; without
        // it they are in the main table.
        var headerRow = r.headTable.length
            ? r.headTable.find('thead tr').first()
            : r.bodyTable.find('thead tr').first();

        // Presence of the handles is the real test, not a flag: DataTables
        // rebuilds the scroll header on every redraw, so a row that was set up
        // earlier can come back stripped of them.
        if (!headerRow.length || headerRow.find('.' + HANDLE).length) {
            return;
        }

        // Deferred: DataTables runs its own columns.adjust() after init and
        // after each draw, which overwrites anything written during the event
        // itself. Yielding first lets those measurements settle so the scaled
        // widths are the ones that survive.
        setTimeout(function () {
            widenColumns(r, headerRow);
        }, 0);

        // Every column gets a right-edge handle, the last one included: it has
        // no neighbour to push against, but resizeColumn() also widens the
        // three tables and both scroll wrappers, so dragging it simply grows
        // or shrinks the table at its trailing edge.
        headerRow.children().each(function () {
            $(this)
                .css('position', 'relative')
                .append($('<span>').addClass(HANDLE).attr('title', 'Drag to resize column'));
        });

        // ...and the first column gets a second handle on its leading edge, so
        // the table can be dragged from its start as well as its end.
        headerRow
            .children()
            .first()
            .append(
                $('<span>')
                    .addClass(HANDLE + ' ' + HANDLE_START)
                    .attr('title', 'Drag to resize column')
            );

        headerRow.on('mousedown', '.' + HANDLE, function (event) {
            event.preventDefault();
            event.stopPropagation();

            // A drag still ends in a click, and that click lands on the <th>,
            // where DataTables reads it as "sort this column" - which refetches
            // the table and throws the new width away. The sort handler is bound
            // on the <th> itself, so it bubbles before anything we could attach
            // further up; the only way to beat it is the capture phase.
            var releaseClickTrap = trapClicks();

            var th = $(this).parent();
            var index = th.index();
            var startX = event.pageX;
            var startWidth = th.width();

            // Dragging the leading edge outwards means moving left, which is a
            // negative pageX delta, so flip it.
            var direction = $(this).hasClass(HANDLE_START) ? -1 : 1;

            $('body').addClass('sb-col-resizing');

            function onMove(moveEvent) {
                var target = Math.max(
                    MIN_WIDTH,
                    startWidth + direction * (moveEvent.pageX - startX)
                );
                var applied = target - th.width();
                if (applied) {
                    resizeColumn(r, index, applied);
                }
            }

            function onUp() {
                $(document).off('mousemove.sbcol', onMove).off('mouseup.sbcol', onUp);
                $('body').removeClass('sb-col-resizing');
                releaseClickTrap();
                saveWidths(r, headerRow);
            }

            $(document).on('mousemove.sbcol', onMove).on('mouseup.sbcol', onUp);
        });
    }

    // Tables initialised after this file loads.
    $(document).on('init.dt', function (event, settings) {
        attach(new $.fn.dataTable.Api(settings));
    });

    // Sorting, searching and paging regenerate the scroll header, which drops
    // the handles, so they have to be put back after every redraw.
    $(document).on('draw.dt', function (event, settings) {
        attach(new $.fn.dataTable.Api(settings));
    });

    /**
     * DataTables recalculates column widths well after init and after each
     * draw - on window resize, on responsive recalcs, and on its own deferred
     * adjust - overwriting the widened values every time. Reapplying whenever
     * it reports new sizing is what makes the widths actually stick; without
     * this the table looks right for a moment and then snaps back to narrow.
     */
    $(document).on('column-sizing.dt', function (event, settings) {
        var api = new $.fn.dataTable.Api(settings);
        var r = regions(api);
        var headerRow = r.headTable.length
            ? r.headTable.find('thead tr').first()
            : r.bodyTable.find('thead tr').first();

        if (headerRow.length) {
            widenColumns(r, headerRow);
        }
    });

    // Tables already initialised by the time this runs. tables() returns a
    // plain array of nodes; the api:true form has no every() in DataTables
    // 1.10.16, so calling it threw on every page load.
    $(function () {
        if (!$.fn.dataTable || !$.fn.dataTable.tables) {
            return;
        }

        $.each($.fn.dataTable.tables(), function (index, node) {
            attach($(node).DataTable());
        });
    });
})(window.jQuery);
