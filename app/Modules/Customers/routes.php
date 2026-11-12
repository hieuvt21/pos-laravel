<?php

use App\Modules\Customers\Http\Controllers\CustomerController;
use App\Modules\Customers\Http\Controllers\CustomerExchangeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Route cố định phải đứng trước route có {customer}
    Route::get('/customers/check', [CustomerController::class, 'check'])
        ->middleware(['can:customers.view', 'throttle:90,1'])->name('customers.check');
    Route::get('/customers/export', [CustomerExchangeController::class, 'export'])
        ->middleware('can:customers.import')->name('customers.export');
    Route::get('/customers/template', [CustomerExchangeController::class, 'template'])
        ->middleware('can:customers.import')->name('customers.template');
    Route::post('/customers/import', [CustomerExchangeController::class, 'import'])
        ->middleware(['can:customers.import', 'throttle:10,1'])->name('customers.import');
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('can:customers.view')->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('can:customers.view')->name('customers.show');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('can:customers.create')->name('customers.store');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.update')->name('customers.update');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('can:customers.delete')->name('customers.destroy');
});
