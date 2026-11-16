<?php

use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockCountController;
use App\Modules\Inventory\Http\Controllers\StockDocumentController;
use App\Modules\Inventory\Http\Controllers\StockImportController;
use App\Modules\Inventory\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('inventory')->group(function () {
    Route::get('/', [StockController::class, 'index'])->middleware('can:inventory.view')->name('inventory.index');
    Route::put('/settings', [StockController::class, 'updateSettings'])->middleware('can:inventory.settings')->name('inventory.settings');
    Route::get('/documents', [StockDocumentController::class, 'index'])->middleware('can:inventory.view')->name('inventory.documents.index');
    Route::get('/documents/{document}', [StockDocumentController::class, 'show'])->whereNumber('document')->middleware('can:inventory.view')->name('inventory.documents.show');
    Route::get('/receipts/create', [StockDocumentController::class, 'createReceipt'])->middleware('can:inventory.receive')->name('inventory.receipts.create');
    Route::post('/receipts', [StockDocumentController::class, 'storeReceipt'])->middleware('can:inventory.receive')->name('inventory.receipts.store');
    Route::get('/issues/create', [StockDocumentController::class, 'createIssue'])->middleware('can:inventory.issue')->name('inventory.issues.create');
    Route::post('/issues', [StockDocumentController::class, 'storeIssue'])->middleware('can:inventory.issue')->name('inventory.issues.store');
    Route::get('/products/search', [StockDocumentController::class, 'productSearch'])->middleware('can:inventory.view')->name('inventory.products.search');
    Route::get('/counts/create', [StockCountController::class, 'create'])->middleware('can:inventory.count')->name('inventory.counts.create');
    Route::post('/counts', [StockCountController::class, 'store'])->middleware('can:inventory.count')->name('inventory.counts.store');
    Route::get('/counts/{document}', [StockCountController::class, 'edit'])->whereNumber('document')->middleware('can:inventory.count')->name('inventory.counts.edit');
    Route::put('/counts/{document}', [StockCountController::class, 'update'])->whereNumber('document')->middleware('can:inventory.count')->name('inventory.counts.update');
    Route::post('/counts/{document}/confirm', [StockCountController::class, 'confirm'])->whereNumber('document')->middleware('can:inventory.count')->name('inventory.counts.confirm');
    Route::delete('/counts/{document}', [StockCountController::class, 'destroy'])->whereNumber('document')->middleware('can:inventory.count')->name('inventory.counts.destroy');
    Route::get('/import', [StockImportController::class, 'index'])->middleware('can:inventory.receive')->name('inventory.import.index');
    Route::get('/import/template', [StockImportController::class, 'template'])->middleware('can:inventory.receive')->name('inventory.import.template');
    Route::post('/import', [StockImportController::class, 'store'])->middleware('can:inventory.receive')->name('inventory.import.store');
    Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('can:inventory.receive')->name('inventory.suppliers.index');
    Route::get('/suppliers/check', [SupplierController::class, 'check'])->middleware('can:inventory.receive')->name('inventory.suppliers.check');
    Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('can:inventory.receive')->name('inventory.suppliers.store');
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->middleware('can:inventory.receive')->name('inventory.suppliers.update');
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier')->middleware('can:inventory.receive')->name('inventory.suppliers.destroy');
    // Route có {productId} đặt sau các route cố định
    Route::get('/{productId}/card', [StockController::class, 'card'])->whereNumber('productId')->middleware('can:inventory.view')->name('inventory.card');
});
