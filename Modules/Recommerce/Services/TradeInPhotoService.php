<?php

namespace Modules\Recommerce\Services;

use App\Media;
use App\User;
use Illuminate\Http\UploadedFile;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\TradeInValuation;

/** Stores acquisition photos through UltimatePOS media, with a Recommerce audit link and purpose. */
class TradeInPhotoService
{
    public const PURPOSES = ['FRONT_OPEN', 'KEYBOARD_PALMREST', 'POWERED_SCREEN', 'BOTTOM_REAR', 'SERIAL_LABEL', 'DEFECT_DAMAGE'];

    /** @param array<int, UploadedFile> $files @param array<int, string> $purposes */
    public function attach(User $user, Device $device, TradeInValuation $valuation, array $files, array $purposes): void
    {
        if (count($files) !== count($purposes)) {
            throw new LogicException('Each trade-in photo must have a purpose.');
        }
        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile || ! str_starts_with((string) $file->getMimeType(), 'image/')) {
                throw new LogicException('Trade-in uploads must be image files.');
            }
            $purpose = strtoupper(trim((string) ($purposes[$index] ?? '')));
            if (! in_array($purpose, self::PURPOSES, true)) {
                throw new LogicException('Choose a supported purpose for every trade-in photo.');
            }
            $fileName = Media::uploadFile($file);
            if (! $fileName) {
                throw new LogicException('A trade-in photo could not be stored within the configured file limit.');
            }
            $media = $device->media()->create([
                'business_id' => $user->business_id, 'file_name' => $fileName, 'uploaded_by' => $user->id,
                'description' => 'Trade-in '.$purpose, 'model_media_type' => 'trade_in_photo',
            ]);
            \DB::table('recommerce_trade_in_photos')->insert([
                'device_id' => $device->id, 'valuation_id' => $valuation->id, 'media_id' => $media->id, 'purpose' => $purpose,
                'captured_by' => $user->id, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
