{{-- A readonly secret with a show/hide toggle.

     type="password" keeps the value dotted until the eye is clicked. The real
     value is in the markup - that is what makes revealing possible at all, and
     it means anyone who can open this page, or is watching a screen share, can
     read it. Readonly with autocomplete="off": nothing here saves, and the
     browser should not offer to remember it. --}}
<div class="col-sm-6">
    <div class="form-group">
        <label>{{ $label }}:</label>
        <div class="input-group">
            <input type="password" class="form-control wc-secret" value="{{ $value }}" readonly
                autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
            <span class="input-group-btn">
                <button type="button" class="btn btn-default wc-secret-toggle" aria-pressed="false"
                    aria-label="Show {{ $label }}" title="Show {{ $label }}">
                    <i class="fa fa-eye" aria-hidden="true"></i>
                </button>
            </span>
        </div>
    </div>
</div>
