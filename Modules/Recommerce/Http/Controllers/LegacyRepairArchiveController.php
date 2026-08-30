<?php

namespace Modules\Recommerce\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Recommerce\Services\LegacyRepairArchiveService;

class LegacyRepairArchiveController extends Controller
{
    public function index(LegacyRepairArchiveService $archiveService)
    {
        try {
            $archives = $archiveService->list(auth()->user());
        } catch (AuthorizationException $exception) {
            abort(404);
        }

        return response()->json([
            'status' => 'LEGACY_REPAIR_ARCHIVES',
            'archives' => $archives,
        ], 200)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function show(int $archiveId, LegacyRepairArchiveService $archiveService)
    {
        try {
            $archive = $archiveService->show(auth()->user(), $archiveId);
        } catch (AuthorizationException $exception) {
            abort(404);
        }

        return response()->json([
            'status' => 'LEGACY_REPAIR_ARCHIVE',
            'archive' => $archive,
        ], 200)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, LegacyRepairArchiveService $archiveService)
    {
        try {
            $validated = $request->validate([
                'command_uuid' => ['required', 'uuid'],
            ]);

            $result = $archiveService->archive(
                auth()->user(),
                (string) $validated['command_uuid']
            );
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('Legacy Repair records could not be archived.');
        } catch (AuthorizationException $exception) {
            abort(404);
        }

        return response()->json(['status' => 'LEGACY_REPAIR_ARCHIVE_RUN'] + $result, 201)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    protected function rejected(string $message)
    {
        return response()->json(['message' => $message], 422)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
