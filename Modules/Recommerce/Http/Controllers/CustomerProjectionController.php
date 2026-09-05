<?php

namespace Modules\Recommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Recommerce\Services\CustomerDeviceListingProjection;
use Modules\Recommerce\Services\CustomerProjectionAccess;

final class CustomerProjectionController
{
    public function __construct(
        private CustomerDeviceListingProjection $projection,
        private CustomerProjectionAccess $access
    ) {
    }

    public function models(): JsonResponse
    {
        return $this->response($this->projection->models());
    }

    public function listings(Request $request): JsonResponse
    {
        $result = $this->projection->listings($request->only([
            'page', 'per_page', 'sort', 'category', 'brand', 'model_slug',
            'cpu', 'ram', 'storage', 'branch', 'min_price', 'max_price',
        ]));

        return $this->response($result['records'], ['pagination' => $result['pagination']]);
    }

    public function model(string $slug): JsonResponse
    {
        return $this->notFoundOrResponse($this->projection->model($slug));
    }

    public function specifications(string $modelSlug): JsonResponse
    {
        if ($this->projection->model($modelSlug) === null) {
            return $this->notFound();
        }

        return $this->response($this->projection->specifications($modelSlug));
    }

    public function specification(string $publicId): JsonResponse
    {
        return $this->notFoundOrResponse($this->projection->specification($publicId));
    }

    public function devices(string $specificationId): JsonResponse
    {
        if ($this->projection->specification($specificationId) === null) {
            return $this->notFound();
        }

        return $this->response($this->projection->devices($specificationId));
    }

    public function device(string $publicDeviceId): JsonResponse
    {
        return $this->notFoundOrResponse($this->projection->device($publicDeviceId));
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $data */
    private function response(array $data, array $extraMeta = []): JsonResponse
    {
        $refreshedAt = now()->toAtomString();
        $sourceVersion = 0;
        foreach (array_is_list($data) ? $data : [$data] as $record) {
            if (is_array($record)) {
                $sourceVersion = max($sourceVersion, (int) ($record['source_version'] ?? 0));
                $refreshedAt = (string) ($record['refreshed_at'] ?? $refreshedAt);
            }
        }

        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'contract_version' => $this->access->contractVersion(),
                'authoritative_source' => 'SAVERPOS',
                'projection_kind' => 'staging_device_listing_projection',
                'source_version' => $sourceVersion,
                'refreshed_at' => $refreshedAt,
            ], $extraMeta),
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /** @param array<string, mixed>|null $data */
    private function notFoundOrResponse(?array $data): JsonResponse
    {
        return $data === null ? $this->notFound() : $this->response($data);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Resource not found.'], 404)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
