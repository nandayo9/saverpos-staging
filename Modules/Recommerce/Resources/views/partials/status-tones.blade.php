{{--
    Shared status tone system for Recommerce screens.

    Status is the field people scan first, so it carries meaning by colour
    rather than decoration. Each pair is chosen for AA contrast rather than
    inherited from Bootstrap's label classes (label-warning is white on
    orange and fails). Views map their own state names onto a tone:

      intake   something is held or newly received
      active   work is under way
      blocked  waiting on a person, part or billing step
      done     finished and evidenced
      closed   ended without completing, or returned

    Include once per view: @include('recommerce::partials.status-tones')
--}}
<style>
    .sb-status { display:inline-block; border-radius:999px; padding:4px 10px; font-size:11px; font-weight:700; letter-spacing:.02em; white-space:nowrap; }
    .sb-status-intake { background:#e0e7ff; color:#3730a3; }
    .sb-status-active { background:#dbeafe; color:#1e40af; }
    .sb-status-blocked { background:#fef3c7; color:#92400e; }
    .sb-status-done { background:#d1fae5; color:#065f46; }
    .sb-status-closed { background:#e5e7eb; color:#374151; }
</style>
