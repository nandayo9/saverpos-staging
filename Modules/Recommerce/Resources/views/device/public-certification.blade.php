<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>SaverBro Certified Device</title>
    <style>
        body { margin: 0; padding: 32px 16px; background: #f3f4f6; color: #111827; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        main { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 28px; box-shadow: 0 8px 28px rgba(17,24,39,.09); }
        h1 { margin: 0 0 6px; font-size: 25px; } h2 { margin: 28px 0 12px; font-size: 17px; }
        .brand { color: #047857; font-weight: 700; letter-spacing: .02em; } .muted { color: #6b7280; }
        dl { margin: 0; } dt { color: #6b7280; font-size: 13px; margin-top: 15px; } dd { margin: 3px 0 0; font-size: 16px; font-weight: 600; }
        .ok { color: #047857; } .button { display: inline-block; margin-top: 26px; padding: 12px 16px; border-radius: 8px; background: #047857; color: #fff; text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
<main>
    <div class="brand">SAVERBRO</div>
    <h1>Certified Device</h1>
    <p class="muted">This is the customer-safe device and warranty record.</p>
    <h2>{{ $profile['device_name'] }}</h2>
    <dl>
        @if ($profile['masked_serial'])<dt>Serial</dt><dd>{{ $profile['masked_serial'] }}</dd>@endif
        <dt>Grade</dt><dd>{{ $profile['grade'] }}</dd>
        <dt>Quality control</dt><dd class="ok">Passed ✓</dd>
        <dt>Battery health</dt><dd>{{ $profile['battery_health_percent'] }}%</dd>
        <dt>Purchased</dt><dd>{{ $profile['purchased_at']->format('d M Y') }}</dd>
        <dt>Warranty</dt><dd class="{{ $profile['warranty_active'] ? 'ok' : '' }}">{{ $profile['warranty_active'] ? 'Valid until' : 'Expired on' }} {{ $profile['warranty_expires_at']->format('d M Y') }}</dd>
    </dl>
    @if ($profile['warranty_service_url'])
        <a class="button" href="{{ $profile['warranty_service_url'] }}" rel="noreferrer">Request Warranty Service</a>
    @endif
</main>
</body>
</html>
