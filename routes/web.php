<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/', fn () => redirect('/dashboard'));

Route::middleware(['auth', 'company'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/admin/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('/admin/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::put('/admin/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::delete('/admin/branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');

    Route::get('/admin/audit', [AuditLogController::class, 'index'])->name('audit.index');
});
