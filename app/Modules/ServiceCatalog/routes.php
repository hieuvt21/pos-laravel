<?php

use App\Modules\ServiceCatalog\Http\Controllers\ServiceCategoryController;
use App\Modules\ServiceCatalog\Http\Controllers\ServiceController;
use App\Modules\ServiceCatalog\Http\Controllers\ServicePackageController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('services')->group(function () {
    // Danh mục dịch vụ: route cố định đặt trước route có {service}
    Route::get('/categories', [ServiceCategoryController::class, 'index'])->middleware('can:service_categories.manage')->name('service_categories.index');
    Route::get('/categories/check', [ServiceCategoryController::class, 'check'])->middleware('can:service_categories.manage')->name('service_categories.check');
    Route::post('/categories', [ServiceCategoryController::class, 'store'])->middleware('can:service_categories.manage')->name('service_categories.store');
    Route::put('/categories/{category}', [ServiceCategoryController::class, 'update'])->whereNumber('category')->middleware('can:service_categories.manage')->name('service_categories.update');
    Route::delete('/categories/{category}', [ServiceCategoryController::class, 'destroy'])->whereNumber('category')->middleware('can:service_categories.manage')->name('service_categories.destroy');

    // Combo / Liệu trình: một controller, loại gói lấy từ giá trị mặc định của route. Route cố định đặt trước route có {id}
    Route::get('/combos', [ServicePackageController::class, 'index'])->defaults('type', 'combo')->middleware('can:service_packages.view')->name('service_combos.index');
    Route::get('/courses', [ServicePackageController::class, 'index'])->defaults('type', 'course')->middleware('can:service_packages.view')->name('service_courses.index');
    Route::get('/packages/check', [ServicePackageController::class, 'check'])->middleware('can:service_packages.view')->name('service_packages.check');
    Route::post('/packages', [ServicePackageController::class, 'store'])->middleware('can:service_packages.create')->name('service_packages.store');
    Route::put('/packages/{package}', [ServicePackageController::class, 'update'])->whereNumber('package')->middleware('can:service_packages.update')->name('service_packages.update');
    Route::delete('/packages/{package}', [ServicePackageController::class, 'destroy'])->whereNumber('package')->middleware('can:service_packages.delete')->name('service_packages.destroy');

    Route::get('/', [ServiceController::class, 'index'])->middleware('can:services.view')->name('services.index');
    Route::get('/check', [ServiceController::class, 'check'])->middleware('can:services.view')->name('services.check');
    Route::post('/', [ServiceController::class, 'store'])->middleware('can:services.create')->name('services.store');
    Route::put('/{service}', [ServiceController::class, 'update'])->whereNumber('service')->middleware('can:services.update')->name('services.update');
    Route::delete('/{service}', [ServiceController::class, 'destroy'])->whereNumber('service')->middleware('can:services.delete')->name('services.destroy');
});
