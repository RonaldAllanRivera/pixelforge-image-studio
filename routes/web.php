<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('studio', StudioController::class)->name('studio');
    Route::post('studio/quote', QuoteController::class)->name('studio.quote');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('generations/{generation}', [GenerationController::class, 'show'])->whereNumber('generation')->name('generations.show');
    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits/purchases', [CreditController::class, 'store'])->middleware('throttle:10,1')->name('credits.purchases.store');
    Route::get('admin/report', ReportController::class)->can('view-admin-report')->name('admin.report');
});

require __DIR__.'/settings.php';
