<?php

namespace App\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Enums\WaitingReason;
use App\Models\Generation;
use App\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Pixelforge\Exceptions\IdempotencyKeyReused;
use App\Pixelforge\Exceptions\InvalidApiKey;
use App\Pixelforge\Exceptions\OutcomeUnknown;
use App\Pixelforge\Exceptions\Overloaded;
use App\Pixelforge\Exceptions\RateLimited as PixelforgeRateLimited;
use App\Pixelforge\Exceptions\Rejected;
use App\Pixelforge\Exceptions\RetryLater;
use App\Pixelforge\Exceptions\ValidationFailed;
use App\Pixelforge\NewGeneration;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a pending generation to Pixelforge, waiting and retrying as Pixelforge asks.
 *
 * Every attempt sends the generation's own Idempotency-Key and the same body, so however
 * many times this runs, Pixelforge creates the generation at most once.
 */
final class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Pixelforge runs at most this many generations at once per account, across all keys. */
    private const PIXELFORGE_CONCURRENCY = 3;

    /** After this many "overloaded" answers we stop and fail the generation, at no cost. */
    private const MAX_ATTEMPTS_WHEN_OVERLOADED = 5;

    /** A running generation with no news for this long doesn't count toward the 3 slots. */
    private const SLOT_COUNTS_FOR_MINUTES = 10;

    /**
     * Seconds to wait after an unexpected exception, so a failing job never retries in a
     * tight loop that uses up the shared Pixelforge rate limit.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public Generation $generation,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('pixelforge')];
    }

    /**
     * Waiting for a free slot releases the job many times, so limit it by time, not attempts.
     * Anything still pending after this is picked up by the scheduled reconcile command.
     */
    public function retryUntil(): CarbonInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(PixelforgeClient $client, ApplyGenerationSnapshot $apply): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status->isFinal()) {
            return;
        }

        if ($generation->status !== GenerationStatus::Pending) {
            // An earlier attempt timed out, but Pixelforge did create it and a webhook moved it on.
            // Make sure it's polled, in case the rest of its webhooks go missing.
            PollGeneration::dispatch($generation)->delay(PollGeneration::waitBefore(1));

            return;
        }

        if ($this->runningAtPixelforge() >= self::PIXELFORGE_CONCURRENCY) {
            $this->waitFor($generation, WaitingReason::FreeSlot, seconds: 5);

            return;
        }

        $generation->increment('submit_attempts');

        try {
            $snapshot = $client->createGeneration($this->request($generation), $generation->idempotency_key);
        } catch (RetryLater $e) {
            $this->retryLater($generation, $e, $apply);

            return;
        } catch (IdempotencyKeyReused $e) {
            // Proof that a generation already exists under this key. Failing it for free would
            // let Pixelforge bill us for it while the user pays nothing. Leave it for a person.
            Log::error('Pixelforge says this generation\'s key was used with different parameters; left pending.', [
                'generation' => $generation->id,
                'message' => $e->getMessage(),
            ]);

            return;
        } catch (Rejected $e) {
            $this->reportIfOurMistake($generation, $e);
            $apply->failBeforeAccepted($generation, $e->errorCode ?? 'rejected', $e->getMessage());

            return;
        } catch (OutcomeUnknown $e) {
            // Pixelforge may have created it. Never fail it: retrying with the same key returns it.
            Log::warning('Unknown outcome submitting a generation; retrying with the same key.', [
                'generation' => $generation->id,
                'reason' => $e->getMessage(),
            ]);
            $this->waitFor($generation, WaitingReason::UnknownOutcome, seconds: 10);

            return;
        }

        $generation = $apply->handle($generation, $snapshot);

        // Webhooks should bring the result; polling catches it if they don't.
        if (! $generation->status->isFinal()) {
            PollGeneration::dispatch($generation)->delay(PollGeneration::waitBefore(1));
        }
    }

    /**
     * Retries ran out. The generation stays pending: the scheduled reconcile command will
     * submit it again with the same Idempotency-Key.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Stopped retrying a generation submit; reconcile will pick it up.', [
            'generation' => $this->generation->id,
            'reason' => $exception?->getMessage(),
        ]);
    }

    private function retryLater(Generation $generation, RetryLater $e, ApplyGenerationSnapshot $apply): void
    {
        if ($e instanceof ConcurrencyLimited) {
            $this->waitFor($generation, WaitingReason::FreeSlot, $e->retryAfter);
        } elseif ($e instanceof PixelforgeRateLimited) {
            $this->waitFor($generation, WaitingReason::RateLimit, $e->retryAfter);
        } elseif ($e instanceof Overloaded) {
            $generation->increment('overloaded_attempts');

            if ($generation->overloaded_attempts >= self::MAX_ATTEMPTS_WHEN_OVERLOADED) {
                $apply->failBeforeAccepted($generation, 'overloaded', $e->getMessage());
            } else {
                // Wait 2, 4, 8, then 16 seconds, or longer if Pixelforge asks.
                $this->waitFor($generation, WaitingReason::Overloaded, max($e->retryAfter, 2 ** $generation->overloaded_attempts));
            }
        } else {
            // A limit we don't know yet: wait as long as Pixelforge asks.
            $this->waitFor($generation, WaitingReason::RateLimit, $e->retryAfter);
        }
    }

    /**
     * Touching the row keeps updated_at fresh while the job waits, so the reconcile command
     * can tell this generation still has a live job and won't start a second one.
     */
    private function waitFor(Generation $generation, WaitingReason $reason, int $seconds): void
    {
        $generation->waiting_reason = $reason;
        $generation->touch();

        $this->release($seconds);
    }

    /**
     * These rejections mean a bug or a misconfiguration on our side, not a user problem.
     */
    private function reportIfOurMistake(Generation $generation, Rejected $e): void
    {
        if ($e instanceof ValidationFailed || $e instanceof InvalidApiKey) {
            Log::error('Pixelforge rejected a generation because of our own mistake.', [
                'generation' => $generation->id,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Our generations Pixelforge is working on now. A row with no news for 10 minutes is
     * stale (its webhooks went missing), not running: even a [slow] one finishes in 90 s.
     * Pixelforge's own concurrency limit still protects the account if this count is off.
     */
    private function runningAtPixelforge(): int
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->where('updated_at', '>=', now()->subMinutes(self::SLOT_COUNTS_FOR_MINUTES))
            ->count();
    }

    /**
     * Built only from stored fields, so every attempt sends the same body: Pixelforge refuses
     * a reused Idempotency-Key with different parameters.
     */
    private function request(Generation $generation): NewGeneration
    {
        return new NewGeneration(
            prompt: $generation->prompt,
            size: $generation->size,
            quality: $generation->quality,
            referenceImages: $generation->reference_images,
            metadata: [
                // Lets a webhook find this generation even before the create response arrives.
                // The UUID can never be reused, unlike an id after a database reset.
                'local_id' => $generation->idempotency_key,
                'env' => (string) app()->environment(),
            ],
        );
    }
}
