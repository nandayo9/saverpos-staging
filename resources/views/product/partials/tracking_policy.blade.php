@if(!empty($recommerceProductTrackingEnabled))
@php
    $trackingMode = old('inventory_tracking_mode', $productTrackingMode ?? 'BULK');
    $trackingLocked = !empty($productTrackingLocked);
@endphp
<div class="saverpos-tracking-policy" data-tracking-locked="{{ $trackingLocked ? '1' : '0' }}">
    <h3>How should SAVERPOS track this product?</h3>
    <p class="help-block">Choose once. Purchases inherit this policy automatically; physical Devices are registered only when stock is received.</p>
    <div class="row">
        <div class="col-sm-6">
            <label class="saverpos-tracking-choice">
                <input type="radio" name="inventory_tracking_mode" value="SERIALIZED_DEVICE" @checked($trackingMode === 'SERIALIZED_DEVICE') @disabled($trackingLocked)>
                <span><strong>Individual Device</strong><small>Track every physical unit separately. Use for laptops, phones, tablets and other items with their own serial, IMEI, condition or history.</small></span>
            </label>
        </div>
        <div class="col-sm-6">
            <label class="saverpos-tracking-choice">
                <input type="radio" name="inventory_tracking_mode" value="BULK" @checked($trackingMode !== 'SERIALIZED_DEVICE') @disabled($trackingLocked)>
                <span><strong>Quantity</strong><small>Track stock quantity only. Use for cables, bags, mice, adapters and generic accessories.</small></span>
            </label>
        </div>
    </div>
    @if($trackingLocked)
        <input type="hidden" name="inventory_tracking_mode" value="{{ $trackingMode }}">
        <p class="text-warning"><i class="fa fa-lock" aria-hidden="true"></i> Tracking is locked because this product already has purchase or Device history.</p>
    @else
        <p class="text-info saverpos-tracking-recommendation" aria-live="polite">Choose the method that matches how staff handle this stock.</p>
    @endif
    <input type="hidden" name="enable_sr_no" class="saverpos-enable-sr-no" value="{{ $trackingMode === 'SERIALIZED_DEVICE' ? '1' : '0' }}">
    <p class="help-block"><strong>Configuration</strong> describes a version or specification of this product. Individual physical Devices are created when the purchase is received—not here.</p>
</div>

@once
<style>
/* Product screens use SAVERPOS' dark workspace palette.  Keep this component
   self-contained so a light background can never inherit low-contrast text. */
.saverpos-tracking-policy{border:2px solid #2f80ed;border-radius:10px;padding:20px;margin:0 0 20px;background:#111c2e;color:#e8f0ff}.saverpos-tracking-policy h3{margin:0 0 6px;color:#f5f8ff;font-size:25px;line-height:1.2}.saverpos-tracking-policy .help-block{margin:0 0 18px;color:#b8c9e3;font-size:16px;line-height:1.45}.saverpos-tracking-choice{display:flex;gap:12px;min-height:112px;padding:16px;border:1px solid #38506f;border-radius:8px;background:#0d1727;color:#e8f0ff;cursor:pointer;transition:border-color .15s ease,background .15s ease,box-shadow .15s ease}.saverpos-tracking-choice:hover{border-color:#5d9cff;background:#101e32}.saverpos-tracking-choice input{margin-top:4px;transform:scale(1.25);accent-color:#2f80ed}.saverpos-tracking-choice strong,.saverpos-tracking-choice small{display:block}.saverpos-tracking-choice strong{font-size:17px;margin-bottom:6px;color:#f5f8ff}.saverpos-tracking-choice small{font-weight:400;line-height:1.45;color:#b8c9e3}.saverpos-tracking-choice:has(input:checked){border-color:#2f80ed;background:#102747;box-shadow:0 0 0 2px rgba(47,128,237,.28)}.saverpos-tracking-policy .saverpos-tracking-recommendation{margin:14px 0 8px;color:#31c7f4;font-size:16px;font-weight:600}.saverpos-tracking-policy .saverpos-tracking-definition{margin:0;color:#b8c9e3;font-size:16px;line-height:1.45}.saverpos-tracking-policy .saverpos-tracking-definition strong{color:#e8f0ff}
</style>
@endonce
@endif
