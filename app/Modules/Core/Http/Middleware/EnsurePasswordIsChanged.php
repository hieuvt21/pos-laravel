<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Chặn mọi trang (trừ đổi mật khẩu và đăng xuất) cho tới khi người dùng đổi mật khẩu lần đầu. */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs('password.force', 'password.update', 'logout')) {
            return redirect()->route('password.force');
        }

        return $next($request);
    }
}
