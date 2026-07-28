<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EntryController;
Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('ledger', [EntryController::class, 'index'])->name('ledger');
    Route::post('ledger', [EntryController::class, 'store'])->name('ledger.store');
    Route::delete('ledger/{entry}', [EntryController::class, 'destroy'])->name('ledger.destroy');
});

require __DIR__.'/settings.php';
