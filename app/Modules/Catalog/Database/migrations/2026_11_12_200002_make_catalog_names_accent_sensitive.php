<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chỉ mục duy nhất theo tên phải phân biệt dấu ("Cá" khác "Ca"): đổi sang utf8mb4_bin.
 * Việc chặn trùng không phân biệt hoa thường do UniqueName xử lý ở tầng ứng dụng.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE categories MODIFY name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        DB::statement('ALTER TABLE units MODIFY name VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        DB::statement('ALTER TABLE price_lists MODIFY name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        // Giữ nguyên: đổi lại collation không cần thiết
    }
};
