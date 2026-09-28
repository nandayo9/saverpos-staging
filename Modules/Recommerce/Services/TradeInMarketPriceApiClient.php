<?php

namespace Modules\Recommerce\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "Current market price" lookup for the Walk-In pricing form.
 *
 * provider=apify: runs marketplace scraper Actors on Apify (Lazada, and
 * Shopee) in parallel, takes the median price of each site's brand-new
 * listings that match the device, and returns the average of those site
 * prices as the current retail price.
 *
 * Never throws: an unavailable price source is an expected state, and the
 * Walk-In form falls back to the staff-entered price when this returns null.
 */
final class TradeInMarketPriceApiClient
{
    private const APIFY_BASE = 'https://api.apify.com/v2/acts/';

    // The Walk-In form looks the price up as soon as brand and model are
    // entered; caching lets the submit reuse that result instead of paying
    // for (and waiting on) a second Apify run.
    private const CACHE_MINUTES = 30;

    /** Why the last lookup() returned null, for showing to staff. */
    private ?string $lastFailureReason = null;

    /** @return array{amount:float,currency:string,source:string,fetched_at:Carbon}|null */
    public function lookup(string $categoryCode, string $brand, string $model, array $specs): ?array
    {
        $this->lastFailureReason = null;
        $config = (array) config('recommerce.tradein_market_price_api');

        return ($config['provider'] ?? null) === 'apify'
            ? $this->lookupApify($config, $brand, $model, $specs)
            : $this->lookupGeneric($config, $categoryCode, $brand, $model, $specs);
    }

    protected function lookupApify(array $config, string $brand, string $model, array $specs): ?array
    {
        $token = (string) ($config['apify_token'] ?? '');
        $actors = (array) ($config['apify_actors'] ?? []);
        if ($token === '' || $actors === []) {
            $this->lastFailureReason = 'the market price API is not configured';

            return null;
        }

        $keyword = $this->keyword($brand, $model);
        if ($keyword === '') {
            $this->lastFailureReason = 'no brand or model was entered';

            return null;
        }
        $cached = Cache::get($this->cacheKey($actors, $keyword));
        if (is_array($cached)) {
            return $cached;
        }

        $timeout = max(10, (int) ($config['apify_timeout'] ?? 120));
        $minSamples = max(1, (int) ($config['apify_min_samples'] ?? 3));
        @set_time_limit($timeout + 30);

        // All Actors run at once, so the wait is the slowest one, not the sum.
        $responses = Http::pool(fn ($pool) => array_map(
            fn (string $actor) => $pool->as($actor)->withToken($token)->acceptJson()->timeout($timeout)
                ->post(self::APIFY_BASE.rawurlencode($actor).'/run-sync-get-dataset-items', $this->actorInput($actor, $keyword, $config)),
            $actors
        ));

        $pricesByActor = [];
        foreach ($responses as $actor => $response) {
            $pricesByActor[$actor] = $this->pricesFrom($actor, $response, $keyword);
        }
        // Outliers are judged against all sites together, so one site's stray
        // listing cannot pass just because that site returned few prices.
        $prices = $this->withoutOutliers(array_merge(...array_values($pricesByActor)));

        // Each marketplace gets its own median, and the market price is the
        // average of those: Lazada usually returns more listings than Shopee,
        // and a pooled median would let it outvote Shopee entirely.
        $priceBySite = [];
        $listingsBySite = [];
        foreach ($pricesByActor as $actor => $actorPrices) {
            $site = str_contains($actor, 'shopee') ? 'Shopee' : 'Lazada';
            $kept = array_values(array_intersect($actorPrices, $prices));
            $listingsBySite[$site] = count($kept);
            if ($kept !== []) {
                $priceBySite[$site] = $this->median($kept);
            }
        }

        if (count($prices) >= $minSamples && $priceBySite !== []) {
            $result = [
                'amount' => round(array_sum($priceBySite) / count($priceBySite), 2),
                'currency' => 'MYR',
                'source' => implode(' + ', array_map(fn ($site) => $site.' RM '.number_format($priceBySite[$site], 2).' ('.$listingsBySite[$site].')', array_keys($priceBySite))),
                'fetched_at' => Carbon::now(),
                'sample_count' => count($prices),
                'listings_by_site' => $listingsBySite,
                'price_by_site' => $priceBySite,
            ];
            Cache::put($this->cacheKey($actors, $keyword), $result, now()->addMinutes(self::CACHE_MINUTES));

            return $result;
        }

        $count = count($prices);
        $this->lastFailureReason = sprintf(
            'only %d matching new listing%s found for "%s" (at least %d needed)',
            $count, $count === 1 ? '' : 's', $keyword, $minSamples
        );

        return null;
    }

    public function lastFailureReason(): ?string
    {
        return $this->lastFailureReason;
    }

    /**
     * The result of an earlier successful lookup for this device, without
     * calling any API. Used to tell an auto-filled price from a typed one.
     *
     * @return array{amount:float,currency:string,source:string,fetched_at:Carbon}|null
     */
    public function cachedLookup(string $brand, string $model): ?array
    {
        $actors = (array) config('recommerce.tradein_market_price_api.apify_actors', []);
        $cached = Cache::get($this->cacheKey($actors, $this->keyword($brand, $model)));

        return is_array($cached) ? $cached : null;
    }

    protected function cacheKey(array $actors, string $keyword): string
    {
        return 'recommerce:tradein-market-price:'.sha1(implode(',', $actors).'|'.strtolower($keyword));
    }

    /** Each Actor has its own input shape. */
    protected function actorInput(string $actor, string $keyword, array $config): array
    {
        $country = strtolower((string) ($config['apify_country'] ?? 'my'));
        $maxItems = max(20, (int) ($config['apify_max_items'] ?? 20));
        // Without a floor, searches for a phone model return pages of RM 5 cases.
        $minPrice = max(0, (int) ($config['apify_min_listing_price'] ?? 200));

        return match (true) {
            // Its condition=new filter is loose; used listings are also dropped by title.
            str_contains($actor, 'shopee') => ['searchTerms' => [$keyword], 'region' => strtoupper($country), 'maxItems' => $maxItems, 'condition' => 'new', 'sort' => 'relevance', 'priceMin' => $minPrice],
            // startUrls must be emptied: the Actor's default is a sample URL.
            default => ['startUrls' => [], 'queries' => [$keyword], 'country' => $country, 'sort' => 'best', 'limit' => $maxItems, 'minPrice' => $minPrice],
        };
    }

    /** @return float[] */
    protected function pricesFrom(string $actor, $response, string $keyword): array
    {
        if ($response instanceof \Throwable) {
            Log::warning('Apify market price lookup failed.', ['actor' => $actor, 'message' => $response->getMessage()]);

            return [];
        }
        if (! $response->successful() || ! is_array($response->json())) {
            Log::warning('Apify market price lookup returned no data.', ['actor' => $actor, 'status' => $response->status()]);

            return [];
        }

        $prices = [];
        foreach ($response->json() as $item) {
            if (! is_array($item)) {
                continue;
            }
            $currency = strtoupper((string) ($item['currency'] ?? ''));
            if ($currency !== '' && ! in_array($currency, ['MYR', 'RM'], true)) {
                continue;
            }
            // Lazada nests the price under "pricing"; Shopee has it top-level.
            $title = (string) ($item['title'] ?? $item['name'] ?? '');
            if (! $this->listingMatches($title, $keyword)) {
                continue;
            }
            $price = $this->numeric($item['pricing']['price'] ?? $item['price'] ?? null);
            if ($price !== null && $price > 0) {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    /**
     * Marketplace search is loose: "Honor 90" also returns Honor 90 Lite and
     * Magic8 phones, cases, screen protectors and used units. Keep a listing
     * only when its title has the brand and model as one phrase, and no
     * variant, accessory or second-hand word that the keyword itself lacks.
     */
    protected function listingMatches(string $title, string $keyword): bool
    {
        $normalize = fn (string $text) => ' '.trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($text))).' ';
        $title = $normalize($title);
        $keyword = $normalize($keyword);
        if (trim($keyword) === '' || ! str_contains($title, $keyword)) {
            return false;
        }

        $excluded = [
            // Other models sharing the base name.
            'lite', 'pro', 'plus', 'max', 'mini', 'ultra', 'smart', 'fe', 'neo', 'prime', 'play', 'gt',
            // Accessories, parts, and faulty units.
            'case', 'casing', 'cover', 'protector', 'tempered', 'charger', 'cable', 'housing', 'lcd',
            'parts', 'sparepart', 'spareparts', 'dummy', 'rosak', 'spoil', 'faulty', 'broken',
            // Second-hand units: the market price is the brand-new retail price.
            'used', 'second', 'secondhand', '2nd', 'preown', 'preowned', 'refurbished', 'refurb', 'terpakai', 'demo',
        ];
        foreach ($excluded as $word) {
            if (str_contains($title, ' '.$word.' ') && ! str_contains($keyword, ' '.$word.' ')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop prices below half or above double the median: mislabelled
     * listings that slip past the title filter should not move the result.
     *
     * @param float[] $prices
     * @return float[]
     */
    protected function withoutOutliers(array $prices): array
    {
        if (count($prices) < 3) {
            return $prices;
        }
        $median = $this->median($prices);

        return array_values(array_filter($prices, fn (float $price) => $price >= $median * 0.5 && $price <= $median * 2));
    }

    // Brand + model only: sellers list several storage sizes in one title
    // ("256/512GB"), so adding the size filters out most valid listings.
    protected function keyword(string $brand, string $model): string
    {
        $model = trim($model);
        $brand = trim($brand);
        $parts = [];
        if ($brand !== '' && stripos($model, $brand) === false) {
            $parts[] = $brand;
        }
        $parts[] = $model;

        return trim(implode(' ', array_filter($parts)));
    }

    protected function numeric($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && preg_match('/[\d,]+(?:\.\d+)?/', $value, $match)) {
            return (float) str_replace(',', '', $match[0]);
        }

        return null;
    }

    /** @param float[] $values */
    protected function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return round($count % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2, 2);
    }

    protected function lookupGeneric(array $config, string $categoryCode, string $brand, string $model, array $specs): ?array
    {
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');
        $token = (string) ($config['bearer_token'] ?? '');
        if ($base === '') {
            return null;
        }

        $parts = parse_url($base);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', (array) ($config['allowed_hosts'] ?? []));
        if (strlen($token) < 16 || ! in_array($host, $allowed, true)) {
            return null;
        }
        if (($parts['scheme'] ?? '') !== 'https' && ! in_array($host, ['localhost', '127.0.0.1', 'host.docker.internal'], true)) {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) ($config['timeout'] ?? 5))
                ->withOptions(['allow_redirects' => false])
                ->get($base.'/price-lookup', ['category' => $categoryCode, 'brand' => $brand, 'model' => $model, 'specs' => $specs]);
        } catch (\Throwable $e) {
            Log::warning('Trade-in market price lookup failed.', ['message' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            $this->lastFailureReason = 'the market price API returned HTTP '.$response->status();

            return null;
        }

        // A provider may return one amount or a low/high range; a range
        // collapses to its midpoint so callers always get one number.
        $amount = $response->json('amount');
        if (! is_numeric($amount) && is_numeric($response->json('low')) && is_numeric($response->json('high'))) {
            $amount = ((float) $response->json('low') + (float) $response->json('high')) / 2;
        }
        if (! is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }

        return [
            'amount' => round((float) $amount, 2),
            'currency' => (string) $response->json('currency', 'MYR'),
            'source' => (string) $response->json('source', $host),
            'fetched_at' => Carbon::now(),
        ];
    }
}
