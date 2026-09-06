<?php

namespace Modules\Recommerce\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use LogicException;
use Modules\Recommerce\Entities\TradeInOutboxMessage;

final class TradeInOutboxDispatcher
{
    /** @var callable(string,array<string,string>,string):array{status:int,body:string}|null */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /** @return array{delivered:int,pending:int,failed:int} */
    public function dispatchPending(int $limit = 50, ?string $eventId = null): array
    {
        $query = TradeInOutboxMessage::query()->where(function ($query) use ($eventId): void {
            $query->where('status', 'PENDING');
            if ($eventId !== null) $query->orWhere('status', 'FAILED');
        });
        // An explicit event ID is an operator-authorized manual retry and may
        // bypass the scheduled backoff. Scheduled batches remain bounded by
        // available_at so repeated website outages do not create a hot loop.
        if ($eventId === null) {
            $query->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', CarbonImmutable::now('UTC'));
            });
        }
        $query->orderBy('id')->limit(max(1, min(200, $limit)));
        if ($eventId !== null) $query->where('event_uuid', $eventId);
        $result = ['delivered' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($query->get() as $message) {
            $status = $this->dispatchOne($message);
            $result[strtolower($status)]++;
        }
        return $result;
    }

    public function dispatchOne(TradeInOutboxMessage $message): string
    {
        $message = TradeInOutboxMessage::query()->whereKey($message->id)->firstOrFail();
        if ($message->status === 'DELIVERED') return 'DELIVERED';
        try {
            [$url, $secret] = $this->configuration();
            $body = json_encode($message->payload_json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) time();
            $signature = 'sha256='.hash_hmac('sha256', $timestamp."\n".$message->event_uuid."\n".$body, $secret);
            $headers = [
                'Content-Type' => 'application/json', 'Accept' => 'application/json',
                'X-SaverBro-Event-Id' => (string) $message->event_uuid,
                'X-SaverBro-Timestamp' => $timestamp,
                'X-SaverBro-Signature' => $signature,
            ];
            $basicAuthorization = (string) config('recommerce.tradein_outbox.website_basic_authorization', '');
            if ($basicAuthorization !== '') {
                $headers['Authorization'] = 'Basic '.base64_encode($basicAuthorization);
            }
            if ($this->transport) {
                $response = ($this->transport)($url, $headers, $body);
                $status = (int) ($response['status'] ?? 0);
                $responseBody = (string) ($response['body'] ?? '');
            } else {
                $response = Http::withHeaders($headers)->timeout((int) config('recommerce.tradein_outbox.timeout_seconds', 10))
                    ->withOptions(['allow_redirects' => false])->withBody($body, 'application/json')->post($url);
                $status = $response->status();
                $responseBody = $response->body();
            }
            $ack = json_decode($responseBody, true);
            if ($status >= 200 && $status < 300 && is_array($ack) && hash_equals((string) $message->event_uuid, (string) ($ack['event_id'] ?? ''))) {
                $message->update([
                    'status' => 'DELIVERED', 'attempt_count' => $message->attempt_count + 1,
                    'last_attempt_at' => CarbonImmutable::now('UTC'), 'delivered_at' => CarbonImmutable::now('UTC'), 'last_error_code' => null, 'last_error' => null,
                ]);
                return 'DELIVERED';
            }
            $retryable = $status === 0 || $status === 408 || $status === 429 || $status >= 500;
            $code = $retryable ? 'UPSTREAM' : (in_array($status, [401, 403], true) ? 'AUTHENTICATION' : 'CONTRACT');
            return $this->failedAttempt($message, $retryable, $code, 'Website projection endpoint returned HTTP '.$status.'.');
        } catch (LogicException $error) {
            return $this->failedAttempt($message, false, 'CONFIGURATION', $error->getMessage());
        } catch (\Throwable $error) {
            return $this->failedAttempt($message, true, 'NETWORK', 'Website projection delivery failed.');
        }
    }

    private function failedAttempt(TradeInOutboxMessage $message, bool $retryable, string $code, string $safeMessage): string
    {
        $attempt = (int) $message->attempt_count + 1;
        $status = $retryable ? 'PENDING' : 'FAILED';
        $delay = min(3600, 60 * (2 ** min(6, max(0, $attempt - 1))));
        $clock = CarbonImmutable::now('UTC');
        $message->update([
            'status' => $status, 'attempt_count' => $attempt, 'last_attempt_at' => $clock,
            'available_at' => $retryable ? $clock->addSeconds($delay) : null,
            'last_error_code' => $code, 'last_error' => mb_substr($safeMessage, 0, 255),
        ]);
        return $status;
    }

    /** @return array{0:string,1:string} */
    private function configuration(): array
    {
        if (config('recommerce.tradein_outbox.enabled') !== true) throw new LogicException('Trade-In outbox delivery is disabled.');
        $url = (string) config('recommerce.tradein_outbox.website_url');
        $secret = (string) config('recommerce.tradein_outbox.hmac_secret');
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $allowed = (array) config('recommerce.tradein_outbox.allowed_hosts', []);
        $local = config('recommerce.tradein_outbox.allow_insecure_local') === true
            && in_array($host, ['localhost', '127.0.0.1', 'host.docker.internal'], true);
        if ($host === '' || ! in_array($host, $allowed, true) || ($scheme !== 'https' && ! $local)) throw new LogicException('Trade-In outbox destination is not allowlisted for secure delivery.');
        if (strlen($secret) < 32) throw new LogicException('Trade-In outbox HMAC secret is unavailable.');
        return [$url, $secret];
    }
}
