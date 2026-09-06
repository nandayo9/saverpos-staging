<?php

namespace Modules\Recommerce\Services;

use Illuminate\Support\Facades\Http;
use LogicException;
use Modules\Recommerce\Entities\TradeInIntake;

final class TradeInWebsiteEvidenceClient
{
    /** @return array{bytes:string,mime_type:string} */
    public function fetch(TradeInIntake $intake, string $evidenceId): array
    {
        $reference = collect((array) $intake->evidence_references_json)->first(fn ($item) => is_array($item) && ($item['evidence_id'] ?? null) === $evidenceId);
        if (! is_array($reference)) throw new LogicException('Evidence is not linked to this POS intake.');
        $base = rtrim((string) config('recommerce.tradein_website_evidence.base_url'), '/');
        $token = (string) config('recommerce.tradein_website_evidence.bearer_token');
        $parts = parse_url($base);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', (array) config('recommerce.tradein_website_evidence.allowed_hosts', []));
        if ($base === '' || strlen($token) < 32 || ! in_array($host, $allowed, true)) {
            throw new LogicException('Website evidence connector is unavailable.');
        }
        if (($parts['scheme'] ?? '') !== 'https' && ! in_array($host, ['localhost', '127.0.0.1', 'host.docker.internal'], true)) {
            throw new LogicException('Website evidence transport requires HTTPS.');
        }
        $url = $base.'/wp-json/saverbro-tradein/v1/integration/evidence/'.rawurlencode($intake->external_case_reference).'/'.rawurlencode($evidenceId);
        $response = Http::withToken($token)->acceptJson()->timeout(5)->withOptions(['allow_redirects' => false])->get($url);
        if (! $response->successful()) throw new LogicException('Private evidence could not be retrieved.');
        $payload = $response->json('data');
        if (! is_array($payload) || ! in_array($payload['mime_type'] ?? '', ['image/jpeg','image/png','image/webp'], true) || ! is_string($payload['content_base64'] ?? null)) {
            throw new LogicException('Private evidence response is invalid.');
        }
        $bytes = base64_decode($payload['content_base64'], true);
        if (! is_string($bytes) || strlen($bytes) < 1 || strlen($bytes) > 10 * 1024 * 1024) throw new LogicException('Private evidence response is invalid.');
        return ['bytes' => $bytes, 'mime_type' => $payload['mime_type']];
    }
}
