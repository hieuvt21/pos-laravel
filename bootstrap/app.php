<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Core\Http\Middleware\EnsurePasswordIsChanged;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Route của từng module nằm trong app/Modules/<Ten>/routes.php, do CoreServiceProvider nạp.
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Thiếu HandleInertiaRequests thì mất toàn bộ dữ liệu dùng chung (auth, menu, flash...)
        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnsurePasswordIsChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
