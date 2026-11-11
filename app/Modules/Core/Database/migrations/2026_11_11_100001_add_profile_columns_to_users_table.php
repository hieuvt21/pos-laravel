<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Tên đăng nhập: chữ thường không dấu, số, . _ -
            $table->string('username', 50)->nullable()->unique()->after('name');

            // Email không còn bắt buộc (cột unique vẫn cho phép nhiều giá trị rỗng)
            $table->string('email')->nullable()->change();

            // Số điện thoại: đúng 10 chữ số, lưu không dấu chấm
            $table->string('phone', 10)->nullable()->unique()->after('email');

            // 'preset:a01' (ảnh có sẵn) hoặc 'uploads/avatars/xxx.jpg' (ảnh tải lên)
            $table->string('avatar')->nullable()->after('phone');

            // Bật khi tài khoản mới tạo hoặc được quản trị viên đặt lại mật khẩu
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['phone']);
            $table->dropColumn(['username', 'phone', 'avatar', 'must_change_password']);
        });
    }
};
