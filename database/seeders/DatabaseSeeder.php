<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /** Tạo quyền, vai trò mặc định và tài khoản admin từ các module (cũng chạy được riêng: php artisan pos:sync). */
    public function run(): void
    {
        Artisan::call('pos:sync');
    }
}
