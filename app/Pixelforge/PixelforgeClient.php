<?php

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Pixelforge\Exceptions\ContentPolicyViolation;
use App\Pixelforge\Exceptions\DailyLimitReached;
use App\Pixelforge\Exceptions\IdempotencyKeyReused;
use App\Pixelforge\Exceptions\InvalidApiKey;
use App\Pixelforge\Exceptions\NotFound;
use App\Pixelforge\Exceptions\OutcomeUnknown;
use App\Pixelforge\Exceptions\Overloaded;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\Exceptions\RateLimited;
use App\Pixelforge\Exceptions\Rejected;
use App\Pixelforge\Exceptions\ValidationFailed;
use App\Pricing\PriceSheet;
use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * The only place that talks HTTP to Pixelforge. Every request is sent once; retrying is
 * the caller's decision. Every failure becomes a typed exception, chosen by Pixelforge's
 * `error.code` rather than by the HTTP status alone.
 */
final readonly class PixelforgeClient
{
    public function __construct(
        #[Config('pixelforge.base_url')]
        private string $baseUrl,
        #[Config('pixelforge.api_key')]
        private ?string $apiKey,
        #[Config('pixelforge.timeout')]
        private int $timeout,
    ) {}

    /**
     * Ask Pixelforge to create a generation. Reusing the same Idempotency-Key for a retry
     * returns the existing generation instead of creating a second one.
     *
     * @throws PixelforgeException
     */
    public function createGeneration(NewGeneration $generation, string $idempotencyKey): GenerationSnapshot
    {
        $response = $this->send(fn () => $this->authenticated()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $generation->toRequestBody()));

        return $this->snapshot($response);
    }

    /**
     * @throws PixelforgeException
     */
    public function getGeneration(string $id): GenerationSnapshot
    {
        return $this->snapshot($this->send(fn () => $this->authenticated()->get('generations/'.rawurlencode($id))));
    }

    /**
     * The published prices and token rules. This endpoint needs no API key.
     *
     * @throws PixelforgeException
     */
    public function pricing(): PriceSheet
    {
        return PriceSheet::fromPricingResponse($this->pricingResponse());
    }

    /**
     * The raw GET /v1/pricing body, for callers that cache it.
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     */
    public function pricingResponse(): array
    {
        $body = $this->send(fn () => $this->request()->get('pricing'))->json();

        if (! is_array($body)) {
            throw new OutcomeUnknown('Pixelforge answered GET /pricing with something that is not JSON.');
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }

    private function authenticated(): PendingRequest
    {
        return $this->request()->withToken((string) $this->apiKey);
    }

    /**
     * @param  Closure(): Response  $request
     *
     * @throws PixelforgeException
     */
    private function send(Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new OutcomeUnknown('Pixelforge did not answer: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        return $response;
    }

    /**
     * @throws OutcomeUnknown
     */
    private function snapshot(Response $response): GenerationSnapshot
    {
        $body = $response->json();

        try {
            return GenerationSnapshot::fromArray(is_array($body) ? $body : []);
        } catch (InvalidArgumentException $e) {
            throw new OutcomeUnknown("Pixelforge answered HTTP {$response->status()} without a generation.", previous: $e);
        }
    }

    private function failure(Response $response): PixelforgeException
    {
        $code = $response->json('error.code');
        $code = is_string($code) ? $code : null;
        $message = $response->json('error.message');
        $message = is_string($message) ? $message : "Pixelforge answered HTTP {$response->status()}.";

        return match ($code) {
            'rate_limit_exceeded' => new RateLimited($message, $code, $this->retryAfter($response) ?? 60),
            'concurrency_limit_exceeded' => new ConcurrencyLimited($message, $code, $this->retryAfter($response) ?? 5),
            'overloaded' => new Overloaded($message, $code, $this->retryAfter($response) ?? 2),
            'content_policy_violation' => new ContentPolicyViolation($message, $code),
            'daily_limit_exceeded' => new DailyLimitReached($message, $code),
            'invalid_api_key' => new InvalidApiKey($message, $code),
            'not_found' => new NotFound($message, $code),
            'idempotency_key_reused' => new IdempotencyKeyReused($message, $code),
            'validation_failed' => new ValidationFailed($message, $this->validationErrors($response)),
            // A 4xx means Pixelforge refused the request. Anything else (5xx, redirects) leaves
            // us not knowing whether the generation was created. The body is never copied into
            // the message: it could echo anything back.
            default => $response->clientError()
                ? new Rejected($message, $code)
                : new OutcomeUnknown("Pixelforge answered HTTP {$response->status()}.", $code),
        };
    }

    /**
     * Seconds from the Retry-After header, or null when it's missing or unreadable.
     */
    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return ctype_digit($header) && (int) $header > 0 ? (int) $header : null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function validationErrors(Response $response): array
    {
        $errors = [];

        foreach ((array) $response->json('error.errors', []) as $param => $messages) {
            if (is_string($param) && is_array($messages)) {
                $errors[$param] = array_values(array_filter($messages, is_string(...)));
            }
        }

        return $errors;
    }
}
