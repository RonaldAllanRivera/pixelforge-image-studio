<?php

namespace App\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use App\Models\Generation;
use App\Pixelforge\Exceptions\OutcomeUnknown;
use App\Pixelforge\Exceptions\Rejected;
use App\Pixelforge\Exceptions\RetryLater;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

/**
 * Asks Pixelforge how a generation is doing, in case its webhooks are late or lost.
 * Webhooks stay the fast path; this is the safety net, and it only runs while something
 * is in flight. The result goes through ApplyGenerationSnapshot, like a webhook.
 */
final class PollGeneration implements ShouldQueue
{
    use Queueable;

    /** Seconds before poll 1, 2 and 3. Every later poll waits a minute. */
    private const WAITS = [20, 30, 45];

    /** 16 polls cover about 15 minutes. After that, the hourly reconcile command takes over. */
    private const MAX_POLLS = 16;

    /**
     * Seconds to wait after an unexpected exception, so a failing poll never retries in a
     * tight loop that uses up the shared Pixelforge rate limit.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public Generation $generation,
        public int $poll = 1,
    ) {}

    /**
     * Seconds to wait before poll number $poll.
     */
    public static function waitBefore(int $poll): int
    {
        return self::WAITS[$poll - 1] ?? 60;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('pixelforge')];
    }

    public function retryUntil(): CarbonInterface
    {
        return now()->addMinutes(15);
    }

    public function handle(PixelforgeClient $client, ApplyGenerationSnapshot $apply): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status->isFinal() || $generation->provider_id === null) {
            return;
        }

        try {
            $snapshot = $client->getGeneration($generation->provider_id);
        } catch (RetryLater $e) {
            $this->release($e->retryAfter);

            return;
        } catch (OutcomeUnknown) {
            $this->pollAgain($generation);

            return;
        } catch (Rejected $e) {
            Log::error('Pixelforge refused to report on a generation we sent it.', [
                'generation' => $generation->id,
                'provider_id' => $generation->provider_id,
                'code' => $e->errorCode,
            ]);

            return;
        }

        $generation = $apply->handle($generation, $snapshot);

        if (! $generation->status->isFinal()) {
            $this->pollAgain($generation);
        }
    }

    private function pollAgain(Generation $generation): void
    {
        // Counted from the first poll, not from creation: a generation can wait a long time
        // for a free slot before Pixelforge even accepts it.
        if ($this->poll >= self::MAX_POLLS) {
            return; // the hourly reconcile command catches anything still running
        }

        self::dispatch($generation, $this->poll + 1)->delay(self::waitBefore($this->poll + 1));
    }
}
