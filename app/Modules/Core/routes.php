<?php

use App\Modules\Core\Http\Controllers\AppearanceController;
use App\Modules\Core\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Modules\Core\Http\Controllers\Auth\PasswordController;
use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\HomeController;
use App\Modules\Core\Http\Controllers\MemberTierController;
use App\Modules\Core\Http\Controllers\ProfileController;
use App\Modules\Core\Http\Controllers\RoleController;
use App\Modules\Core\Http\Controllers\StoreSettingsController;
use App\Modules\Core\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Không dùng closure trong file route để `php artisan route:cache` chạy được trên cPanel.

Route::get('/', HomeController::class)->name('home');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Đổi mật khẩu (bắt buộc ở lần đăng nhập đầu, hoặc tự đổi ở trang Hồ sơ)
    Route::get('password/change', [PasswordController::class, 'edit'])->name('password.force');
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Giao diện: ai cũng xem được; lưu thương hiệu cửa hàng cần settings.manage
    Route::get('/appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');
    Route::post('/appearance/branding', [AppearanceController::class, 'updateBranding'])
        ->middleware('can:settings.manage')->name('appearance.branding');

    // Cửa hàng: thông tin, tiền tệ & thuế, đánh số chứng từ
    Route::middleware('can:settings.manage')->prefix('store')->name('store.')->group(function () {
        Route::get('/', [StoreSettingsController::class, 'edit'])->name('edit');
        Route::put('/info', [StoreSettingsController::class, 'updateInfo'])->name('info');
        Route::put('/finance', [StoreSettingsController::class, 'updateFinance'])->name('finance');
        Route::put('/numbering', [StoreSettingsController::class, 'updateNumbering'])->name('numbering');
    });

    // Hạng thành viên
    Route::middleware('can:settings.manage')->prefix('member-tiers')->name('member-tiers.')->group(function () {
        Route::get('/', [MemberTierController::class, 'edit'])->name('edit');
        Route::put('/', [MemberTierController::class, 'update'])->name('update');
    });

    // Người dùng (route cố định /users/check đứng trước route có {user})
    Route::get('/users/check', [UserController::class, 'check'])->middleware('throttle:90,1')->name('users.check');
    Route::get('/users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->middleware('can:users.create')->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('can:users.update')->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('can:users.delete')->name('users.destroy');

    // Vai trò & quyền
    Route::middleware('can:roles.manage')->prefix('roles')->name('roles.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->name('index');
        Route::post('/', [RoleController::class, 'store'])->name('store');
        Route::put('/{role}', [RoleController::class, 'update'])->name('update');
        Route::delete('/{role}', [RoleController::class, 'destroy'])->name('destroy');
    });
});
