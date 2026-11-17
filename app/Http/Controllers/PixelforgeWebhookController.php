<?php

namespace App\Http\Controllers;

use App\Actions\ApplyGenerationSnapshot;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\GenerationSnapshot;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives Pixelforge's webhooks.
 *
 * Storing the event and applying it happen in one transaction. A duplicate delivery hits the
 * unique event id and changes nothing. If applying fails, the stored event rolls back too and
 * we answer 500, so Pixelforge's retry is processed normally.
 */
final class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, ApplyGenerationSnapshot $apply): Response
    {
        $body = $request->getContent();

        if (! $signature->isValid((string) $request->header('Pixelforge-Signature'), $body)) {
            Log::warning('Rejected a Pixelforge webhook with a missing or invalid signature.');

            return response('Invalid signature.', 400);
        }

        $event = json_decode($body, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response('Not a Pixelforge event.', 400);
        }

        DB::transaction(fn () => $this->storeAndApply($event, $request, $apply));

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function storeAndApply(array $event, Request $request, ApplyGenerationSnapshot $apply): void
    {
        $stored = WebhookEvent::query()->insertOrIgnore([
            'event_id' => $event['id'],
            'type' => $event['type'],
            'payload' => json_encode($event),
            'delivery_attempt' => ctype_digit((string) $request->header('Pixelforge-Delivery-Attempt'))
                ? (int) $request->header('Pixelforge-Delivery-Attempt')
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($stored === 0) {
            return; // Already received: a duplicate delivery.
        }

        $object = $event['data']['object'] ?? null;
        $generation = null;

        if (is_string($event['type']) && str_starts_with($event['type'], 'generation.') && is_array($object)) {
            $snapshot = GenerationSnapshot::fromArray($object);
            $generation = $this->findGeneration($snapshot);

            if ($generation === null) {
                Log::warning('Received a Pixelforge webhook for a generation we do not have.', [
                    'event' => $event['id'],
                    'generation' => $snapshot->id,
                ]);
            } else {
                $apply->handle($generation, $snapshot);
            }
        }

        WebhookEvent::query()->where('event_id', $event['id'])->update([
            'generation_id' => $generation?->id,
            'processed_at' => now(),
        ]);
    }

    /**
     * By Pixelforge's id, or, when the webhook beat the create response, by the UUID we sent
     * as metadata.local_id (only if it came from this environment). A UUID is never reused,
     * so an old event can't land on a new generation, even after a database reset.
     */
    private function findGeneration(GenerationSnapshot $snapshot): ?Generation
    {
        $generation = Generation::query()->where('provider_id', $snapshot->id)->first();

        if ($generation !== null) {
            return $generation;
        }

        $localId = $snapshot->metadata['local_id'] ?? null;
        $environment = $snapshot->metadata['env'] ?? null;

        if ($localId === null || ! Str::isUuid($localId) || $environment !== app()->environment()) {
            return null;
        }

        return Generation::query()->where('idempotency_key', $localId)->first();
    }
}
