<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Routes called by other services, not by browsers. They run outside the "web" middleware
 * group: no session, cookies or CSRF token. Pixelforge signs each webhook instead, and the
 * controller verifies that signature.
 */
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');
