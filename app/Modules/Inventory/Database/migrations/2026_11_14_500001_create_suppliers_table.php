<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nhà cung cấp: chỉ để ghi phiếu nhập kho lấy từ đâu (chưa có công nợ).
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('phone', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('stock_documents', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('note')->constrained('suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('supplier_id'));
        Schema::dropIfExists('suppliers');
    }
};
