<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationDetailResource;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class GenerationController extends Controller
{
    /**
     * Save it as pending and queue it for Pixelforge. The Studio shows the new card at once.
     */
    public function store(StoreGenerationRequest $request, CreateGeneration $create, #[CurrentUser] User $user): RedirectResponse
    {
        $create->handle($user, $request->prompt(), $request->imageSize(), $request->imageQuality(), $request->referenceImages());

        return to_route('studio');
    }

    public function show(Generation $generation): Response
    {
        Gate::authorize('view', $generation);

        return Inertia::render('generations/Show', [
            'generation' => fn () => (new GenerationDetailResource($generation))->resolve(),
        ]);
    }
}
