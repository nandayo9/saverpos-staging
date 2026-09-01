<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SAVERBRO labels · {{ count($labels) }} devices</title>
    <style>
        @page { size: {{ config('recommerce.label_template.width') }} {{ config('recommerce.label_template.height') }}; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: var(--sb-surface, #0f172a); color: var(--sb-text, #e2e8f0); font-family: Arial, sans-serif; }
        body { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .label-page { break-after: page; page-break-after: always; }
        .label-page:last-of-type { break-after: auto; page-break-after: auto; }
        .label { width: {{ config('recommerce.label_template.width') }}; min-height: {{ config('recommerce.label_template.height') }}; height: {{ config('recommerce.label_template.height') }}; overflow: hidden; background: var(--sb-surface-raised, #162235); border: 1px solid var(--sb-border, #34435b); border-radius: 1.5mm; padding: .25mm; }
        .brand { font-size: 6pt; line-height: 1; font-weight: 700; letter-spacing: .08em; }
        .sub { color: var(--sb-muted, #9aaabe); font-size: 4.5pt; line-height: 1; margin-top: .3mm; }
        .content { display: flex; gap: 1mm; align-items: center; height: 19.5mm; }
        .qr { width: 19mm; height: 19mm; display: grid; place-items: center; flex: 0 0 19mm; }
        .qr svg { width: 19mm; height: 19mm; }
        .details { min-width: 0; flex: 1; }
        .description { font-size: 6.5pt; font-weight: 700; line-height: 1.05; overflow-wrap: anywhere; }
        .code { font-family: monospace; font-size: 6.5pt; margin-top: .5mm; letter-spacing: .02em; }
        .barcode { margin-top: .5mm; height: 5mm; overflow: hidden; }
        .barcode svg { width: 100%; height: 5mm; }
        .screen-note { color: var(--sb-muted, #9aaabe); font-size: 9pt; margin: 14px 0 0; text-align: center; }
        @media print {
            html, body { background: #fff; color: #111827; }
            body { padding: 0; display: block; }
            .label { background: #fff; border: 0; border-radius: 0; }
            .sub { color: #64748b; }
            .screen-note { display: none; }
        }
    </style>
</head>
<body>
    @foreach ($labels as $entry)
        <section class="label-page" aria-label="Print-safe SAVERPOS device label {{ $loop->iteration }} of {{ count($labels) }}">
            <main class="label">
                <div class="content">
                    <div class="qr" aria-label="Opaque QR code">{!! $entry['rendered']['qr_svg'] !!}</div>
                    <div class="details">
                        <div class="brand">SAVERBRO</div>
                        <div class="sub">{{ $entry['label']['template_version'] }} · permanent device identity</div>
                        <div class="description">{{ $entry['label']['safe_description'] }}</div>
                        <div class="code">{{ $entry['label']['device_code'] }}</div>
                        <div class="barcode" aria-label="Code 128 barcode">{!! $entry['rendered']['code128_svg'] !!}</div>
                    </div>
                </div>
            </main>
        </section>
    @endforeach
    <div class="screen-note">
        <button type="button" onclick="window.print()">Print {{ count($labels) }} SAVERBRO {{ \Illuminate\Support\Str::plural('label', count($labels)) }}</button>
        <a href="{{ url('/recommerce/devices') }}">Back to Device Registry</a>
        <p>Confirm the printer and label stock, then attach each label to its exact Device.</p>
    </div>
</body>
</html>
