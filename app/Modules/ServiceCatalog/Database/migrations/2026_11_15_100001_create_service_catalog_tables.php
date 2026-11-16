<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Danh mục dịch vụ: riêng, không dùng chung danh mục sản phẩm
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Dịch vụ: giá cố định, không đơn vị, không tồn kho. Hoa hồng nhân viên khai báo bằng một ô "định mức":
        // commission_type = amount (định mức là số tiền) hoặc percent (định mức là % giá dịch vụ, tối đa 100).
        // Số tiền hoa hồng không lưu: luôn tính từ giá và định mức để đổi giá thì hoa hồng theo.
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->restrictOnDelete();
            $table->decimal('price', 15, 2)->default(0);
            $table->string('commission_type', 10)->default('amount');
            $table->decimal('commission_value', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};
