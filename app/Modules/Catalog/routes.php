<?php

use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Sản phẩm (route cố định /products/check phải đứng trước route có {product})
    Route::get('/products/check', [ProductController::class, 'check'])
        ->middleware(['can:products.view', 'throttle:90,1'])->name('products.check');
    Route::get('/products', [ProductController::class, 'index'])->middleware('can:products.view')->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->middleware('can:products.create')->name('products.store');
    Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('can:products.update')->name('products.update');
    Route::patch('/products/{product}/active', [ProductController::class, 'toggle'])->middleware('can:products.update')->name('products.toggle');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('can:products.delete')->name('products.destroy');

    // Danh mục, đơn vị tính, bảng giá
    Route::get('/catalog', [CatalogController::class, 'index'])->middleware('can:products.view')->name('catalog.index');
    Route::middleware('can:catalog.manage')->whereIn('type', ['categories', 'units', 'price-lists'])->group(function () {
        Route::get('/catalog/{type}/check', [CatalogController::class, 'check'])->middleware('throttle:90,1')->name('catalog.check');
        Route::post('/catalog/{type}', [CatalogController::class, 'store'])->name('catalog.store');
        Route::put('/catalog/{type}/{id}', [CatalogController::class, 'update'])->whereNumber('id')->name('catalog.update');
        Route::delete('/catalog/{type}/{id}', [CatalogController::class, 'destroy'])->whereNumber('id')->name('catalog.destroy');
    });
});
