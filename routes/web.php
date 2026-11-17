<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Pixelforge signs its webhooks instead of sending a CSRF token.
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
