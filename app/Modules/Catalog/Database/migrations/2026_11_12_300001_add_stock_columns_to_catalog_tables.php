<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // Đơn vị cho phép số lượng lẻ (kg, lít...). Mặc định tắt: chỉ nhận số nguyên.
            $table->boolean('allow_decimal')->default(false)->after('is_default');
        });

        Schema::table('products', function (Blueprint $table) {
            // Ngưỡng cảnh báo sắp hết hàng (Inventory đọc). 0 = không cảnh báo.
            $table->decimal('min_stock', 15, 3)->default(0)->after('track_stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('min_stock'));
        Schema::table('units', fn (Blueprint $table) => $table->dropColumn('allow_decimal'));
    }
};
