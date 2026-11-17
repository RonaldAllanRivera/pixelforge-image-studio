<?php

namespace App\Http\Controllers;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\User;
use App\Pricing\CurrentPrices;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Studio: the form to generate an image, and the user's history below it.
 */
final class StudioController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, CurrentPrices $prices): Response
    {
        // Closures, so the page's 2-second status polling (which asks only for
        // "generations") doesn't rebuild the form's choices every time.
        return Inertia::render('Studio', [
            'generations' => fn () => GenerationResource::collection($user->generations()->latest('id')->paginate(12)),
            'sizes' => fn () => array_map(fn (ImageSize $size) => ['value' => $size->value, 'label' => $size->label()], ImageSize::cases()),
            'qualities' => fn () => array_map(fn (ImageQuality $quality) => ['value' => $quality->value, 'label' => $quality->label()], ImageQuality::cases()),
            'limits' => fn () => [
                'prompt' => StoreGenerationRequest::MAX_PROMPT_CHARACTERS,
                'referenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
                'tokensPerReferenceImage' => $prices->sheet()->tokensPerReferenceImage,
            ],
        ]);
    }
}
