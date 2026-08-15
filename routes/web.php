<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'company'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});
