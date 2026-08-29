<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Repair status {{ $publicJob->job_code }} · SAVERPOS</title>
    <style>
        body{margin:0;background:#f7f8fc;color:#172033;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        .wrap{max-width:640px;margin:48px auto;padding:0 18px}
        .brand{color:#4f46e5;font-weight:800;letter-spacing:.04em}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:24px;box-shadow:0 8px 24px rgba(15,23,42,.06)}
        h1{font-size:24px;margin:8px 0 18px}
        .state{display:inline-block;border-radius:999px;background:#eef2ff;color:#4338ca;padding:6px 12px;font-weight:700;font-size:13px}
        .summary{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:22px 0}
        .label{color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.06em}
        .value{font-weight:600;margin-top:3px}
        .note{border-left:3px solid #6366f1;padding-left:12px;margin-top:22px}
        .safe{color:#64748b;font-size:13px;margin-top:24px}
        @media(max-width:520px){.wrap{margin:22px auto}.summary{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <main class="wrap">
        <div class="brand">SAVERPOS REPAIR</div>
        <div class="card">
            <h1>Repair status</h1>
            <div class="state">{{ str_replace('_', ' ', $publicJob->state) }}</div>
            <div class="summary">
                <div><div class="label">Repair code</div><div class="value">{{ $publicJob->job_code }}</div></div>
                <div><div class="label">Device</div><div class="value">{{ implode(' · ', $deviceSummary) }}</div></div>
                @if ($publicJob->due_date)
                    <div><div class="label">Due date</div><div class="value">{{ $publicJob->due_date }}</div></div>
                @endif
            </div>
            @if ($publicJob->customer_facing_update)
                <div class="note"><div class="label">Latest update</div><div class="value">{{ $publicJob->customer_facing_update }}</div></div>
            @endif
            <div class="safe">This page shows a limited status summary. Customer contact details, internal notes, diagnostics, pricing, payments, and access details are not displayed.</div>
        </div>
    </main>
</body>
</html>
