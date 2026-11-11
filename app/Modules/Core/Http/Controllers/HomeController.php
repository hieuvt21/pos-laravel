<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /** Vào app: chưa đăng nhập -> trang đăng nhập, đã đăng nhập -> trang chủ của tài khoản. */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        return $user
            ? redirect()->route($user->homeRoute())
            : redirect()->route('login');
    }
}
