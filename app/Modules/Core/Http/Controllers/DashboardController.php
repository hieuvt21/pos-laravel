<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Trang chung cho mọi tài khoản sau đăng nhập. Khu báo cáo bổ sung khi các module có dữ liệu. */
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard');
    }
}
