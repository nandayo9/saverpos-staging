{{--
    Shared status tone system for Recommerce screens.

    Status is the field people scan first, so it carries meaning by colour
    rather than decoration. Views map their own state names onto a tone:

      intake   something is held or newly received
      active   work is under way
      blocked  waiting on a person, part or billing step
      done     finished and evidenced
      closed   ended without completing, or returned

    These pills sit on the dark POS surface (--sb-surface-raised, #162235), so
    each pair is a deep ground with light type rather than the pale ground with
    dark type a white card wanted. Measured text-on-pill contrast, all above the
    7:1 AAA threshold: intake 7.66, active 7.29, blocked 7.28, done 7.58,
    closed 8.40. Against the surface itself each pill sits at 1.4-1.8:1, which
    reads as a distinct chip without glaring the way the old pale grounds did
    (those measured 13-14:1 against this background).

    Include once per view: @include('recommerce::partials.status-tones')
--}}
<style>
    .sb-status { display:inline-block; border-radius:999px; padding:4px 10px; font-size:11px; font-weight:700; letter-spacing:.02em; white-space:nowrap; }
    .sb-status-intake { background:#312e81; color:#c7d2fe; }
    .sb-status-active { background:#1e3a8a; color:#bfdbfe; }
    .sb-status-blocked { background:#78350f; color:#fde68a; }
    .sb-status-done { background:#064e3b; color:#a7f3d0; }
    .sb-status-closed { background:#334155; color:#e2e8f0; }

    @media print {
        /* A printed record goes on white stock: restore the light pairs. */
        .sb-status-intake { background:#e0e7ff; color:#3730a3; }
        .sb-status-active { background:#dbeafe; color:#1e40af; }
        .sb-status-blocked { background:#fef3c7; color:#92400e; }
        .sb-status-done { background:#d1fae5; color:#065f46; }
        .sb-status-closed { background:#e5e7eb; color:#374151; }
    }
</style>
