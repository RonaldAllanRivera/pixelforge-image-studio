<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;

/**
 * The backstop, run every hour: catches generations whose webhooks, polls or submit
 * retries all went missing. Hourly rather than every minute, because each scheduled run
 * wakes the app and the database on Laravel Cloud.
 */
final class ReconcileGenerations extends Command
{
    /**
     * @var string
     */
    protected $signature = 'generations:reconcile';

    /**
     * @var string
     */
    protected $description = 'Catch up on generations whose webhooks or jobs went missing';

    /** A generation with no news for this long is checked. */
    private const QUIET_MINUTES = 2;

    public function handle(): int
    {
        $quietSince = now()->subMinutes(self::QUIET_MINUTES);
        $polled = 0;
        $resubmitted = 0;

        // Accepted by Pixelforge, but we haven't heard back: ask Pixelforge.
        Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->where('updated_at', '<', $quietSince)
            ->each(function (Generation $generation) use (&$polled): void {
                PollGeneration::dispatch($generation);
                $polled++;
            });

        // Never accepted: submit again. The same Idempotency-Key returns the generation if
        // an earlier attempt did create it, so this can never create a second one.
        Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('updated_at', '<', $quietSince)
            ->each(function (Generation $generation) use (&$resubmitted): void {
                SubmitGeneration::dispatch($generation);
                $resubmitted++;
            });

        $this->info("Polling {$polled} and resubmitting {$resubmitted} generations.");

        return self::SUCCESS;
    }
}
