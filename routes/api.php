<?php

use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileParentController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->name('api.mobile.')->group(function () {
    Route::post('/login', [MobileAuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [MobileAuthController::class, 'me'])->name('me');
        Route::post('/logout', [MobileAuthController::class, 'logout'])->name('logout');
        Route::get('/tasks', [MobileAuthController::class, 'tasks'])->name('tasks');
        Route::get('/location/session', [MobileAuthController::class, 'locationSession'])->name('location.session');

        Route::prefix('parent')->name('parent.')->group(function () {
            Route::get('/dashboard', [MobileParentController::class, 'dashboard'])->name('dashboard');
            Route::get('/children', [MobileParentController::class, 'children'])->name('children');
            Route::post('/church-invite', [MobileParentController::class, 'applyChurchInvite'])->name('church-invite.apply');
            Route::get('/children/linkable', [MobileParentController::class, 'linkableChildren'])->middleware('throttle:20,1')->name('children.linkable');
            Route::post('/children/link', [MobileParentController::class, 'linkChild'])->middleware('throttle:10,1')->name('children.link');
            Route::post('/children', [MobileParentController::class, 'storeChild'])->name('children.store');
            Route::put('/children/{member}', [MobileParentController::class, 'updateChild'])->name('children.update');
            Route::get('/payments', [MobileParentController::class, 'payments'])->name('payments');
            Route::post('/payments/transfers', [MobileParentController::class, 'submitTransfer'])->name('payments.transfers.store');
            Route::get('/payment-submissions/{submission}/proof', [MobileParentController::class, 'paymentProof'])->name('payment-proofs.show');
            Route::get('/receipts/{receipt}', [MobileParentController::class, 'receipt'])->name('receipts.show');
            Route::get('/workplan', [MobileParentController::class, 'workplan'])->name('workplan');
        });
    });
});
