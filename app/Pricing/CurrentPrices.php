<?php

namespace App\Pricing;

use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The price sheet behind every up-front estimate: Pixelforge's live GET /v1/pricing,
 * kept for 10 minutes. If it can't be fetched, the published prices from config are
 * used for a minute before trying again, so an estimate never waits on a dead connection.
 */
final readonly class CurrentPrices
{
    private const CACHE_KEY = 'pixelforge.pricing';

    /**
     * @param  array<string, mixed>  $publishedPricing
     */
    public function __construct(
        private PixelforgeClient $client,
        private Cache $cache,
        #[Config('pixelforge.published_pricing')]
        private array $publishedPricing,
    ) {}

    public function sheet(): PriceSheet
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return PriceSheet::fromPricingResponse($cached);
        }

        try {
            $response = $this->client->pricingResponse();
            $sheet = PriceSheet::fromPricingResponse($response);
            $this->cache->put(self::CACHE_KEY, $response, now()->addMinutes(10));

            return $sheet;
        } catch (PixelforgeException|InvalidArgumentException $e) {
            Log::warning('Pixelforge pricing is unavailable, so estimates use the published prices.', ['reason' => $e->getMessage()]);
            $this->cache->put(self::CACHE_KEY, $this->publishedPricing, now()->addMinute());

            return PriceSheet::fromPricingResponse($this->publishedPricing);
        }
    }
}
