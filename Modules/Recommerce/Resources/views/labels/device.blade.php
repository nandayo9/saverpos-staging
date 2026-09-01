<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SAVERBRO label · {{ $label['device_code'] }}</title>
    <style>
        @page { size: {{ config('recommerce.label_template.width') }} {{ config('recommerce.label_template.height') }}; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #eef2f7; color: #111827; font-family: Arial, sans-serif; }
        body { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .label { width: {{ config('recommerce.label_template.width') }}; min-height: {{ config('recommerce.label_template.height') }}; height: {{ config('recommerce.label_template.height') }}; overflow: hidden; background: #fff; border: 1px solid #cbd5e1; border-radius: 1.5mm; padding: .7mm; }
        .brand { font-size: 6pt; line-height: 1; font-weight: 700; letter-spacing: .08em; }
        .sub { color: #64748b; font-size: 4.5pt; line-height: 1; margin-top: .3mm; }
        .content { display: flex; gap: 2mm; align-items: center; height: 17mm; }
        .qr { width: 17mm; height: 17mm; display: grid; place-items: center; flex: 0 0 17mm; }
        .qr svg { width: 17mm; height: 17mm; }
        .details { min-width: 0; flex: 1; }
        .description { font-size: 6.5pt; font-weight: 700; line-height: 1.05; overflow-wrap: anywhere; }
        .code { font-family: monospace; font-size: 6.5pt; margin-top: .5mm; letter-spacing: .02em; }
        .barcode { margin-top: .5mm; height: 5mm; overflow: hidden; }
        .barcode svg { width: 100%; height: 5mm; }
        .footer { display: none; }
        .screen-note { color: #64748b; font-size: 9pt; margin-top: 14px; text-align: center; }
        @media print { html, body { background: #fff; } body { padding: 0; display: block; } .label { border: 0; border-radius: 0; } .screen-note { display: none; } }
    </style>
</head>
<body>
    <main class="label" aria-label="Print-safe SAVERPOS device label">
        <div class="content">
            <div class="qr" aria-label="Opaque QR code">{!! $rendered['qr_svg'] !!}</div>
            <div class="details">
                <div class="brand">SAVERBRO</div>
                <div class="sub">{{ $label['template_version'] }} · permanent device identity</div>
                <div class="description">{{ $label['safe_description'] }}</div>
                <div class="code">{{ $label['device_code'] }}</div>
                <div class="barcode" aria-label="Code 128 barcode">{!! $rendered['code128_svg'] !!}</div>
            </div>
        </div>
        <div class="footer">Permanent Device code · QR destination is opaque and not printed as text</div>
    </main>
    <div class="screen-note">Print view opened. Confirm the printer and label stock, then attach the label to this exact device.</div>
</body>
</html>
