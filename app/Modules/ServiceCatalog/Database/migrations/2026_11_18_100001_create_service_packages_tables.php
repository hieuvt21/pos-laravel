<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mẫu gói bán trước: combo (1 dịch vụ, nhiều lần) và liệu trình (nhiều dịch vụ). Dùng chung danh mục dịch vụ.
        // validity_days: hạn sử dụng tính từ ngày mua (null = không hạn). warranty_days: số ngày bảo hành sau khi dùng hết gói (null = không bảo hành).
        // Hoa hồng không lưu số tiền, luôn tính từ giá và định mức (giống dịch vụ):
        //  - sale_commission_*: hoa hồng cho người BÁN gói, tính trên giá gói.
        //  - usage_commission_mode = per_item (mỗi dịch vụ một định mức, ở service_package_items) hoặc common (một định mức chung, ở usage_commission_*).
        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('type', 10); // combo | course
            $table->string('name', 150);
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->restrictOnDelete();
            $table->decimal('price', 15, 2)->default(0);
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->unsignedSmallInteger('warranty_days')->nullable();
            $table->string('sale_commission_type', 10)->default('amount');
            $table->decimal('sale_commission_value', 15, 2)->default(0);
            $table->string('usage_commission_mode', 10)->default('per_item');
            $table->string('usage_commission_type', 10)->default('amount');
            $table->decimal('usage_commission_value', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('name');
            $table->index('type');
        });

        // Dịch vụ trong gói. quantity null = không giới hạn số lần (chỉ hợp lệ khi gói có hạn sử dụng).
        Schema::create('service_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_package_id')->constrained('service_packages')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('commission_type', 10)->default('amount');
            $table->decimal('commission_value', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['service_package_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_items');
        Schema::dropIfExists('service_packages');
    }
};
