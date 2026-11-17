<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hoa hồng nhân viên khi bán sản phẩm, khai báo bằng một ô "định mức":
        // commission_type = amount (số tiền cho mỗi đơn vị bán) hoặc percent (% giá bán, tối đa 100).
        // Số tiền hoa hồng không lưu: tính khi bán từ giá bán thực tế (xem Core\Support\Commission).
        Schema::table('products', function (Blueprint $table) {
            $table->string('commission_type', 10)->default('amount')->after('cost_price');
            $table->decimal('commission_value', 15, 2)->default(0)->after('commission_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['commission_type', 'commission_value']));
    }
};
