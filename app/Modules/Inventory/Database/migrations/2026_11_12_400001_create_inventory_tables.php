<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Số tồn hiện tại (cache của sổ kho). Đây là dòng bị khóa khi ghi sổ để hai máy không làm lệch nhau.
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('on_hand', 15, 3)->default(0);
            $table->timestamps();
        });

        // Phiếu kho thủ công: nhập, xuất, kiểm kho (nháp rồi xác nhận). Phiếu đã xác nhận không sửa, không xóa.
        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('type', 20);                          // receipt | issue | count
            $table->date('doc_date');                            // chỉ để hiển thị, không tính lại giá vốn/số dư
            $table->string('reason', 50)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('confirmed');  // draft (chỉ kiểm kho) | confirmed
            $table->nullableMorphs('reference');                 // phiếu do module khác sinh (Purchasing...)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'doc_date']);
        });

        Schema::create('stock_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 15, 3)->nullable();           // nhập/xuất: số lượng; kiểm kho: số đếm (null = chưa đếm)
            $table->decimal('unit_cost', 15, 2)->nullable();     // đơn giá nhập
            $table->decimal('system_qty', 15, 3)->nullable();    // kiểm kho: tồn trong máy lúc lưu/xác nhận
            $table->decimal('diff_qty', 15, 3)->nullable();      // kiểm kho: chênh lệch đã ghi sổ
            $table->string('note')->nullable();
            $table->timestamps();
        });

        // Sổ cái, chỉ thêm. Bán hàng/trả hàng/nhập từ NCC ghi thẳng vào đây, tham chiếu bằng reference_*.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_document_id')->nullable()->constrained()->restrictOnDelete();
            $table->nullableMorphs('reference');
            $table->string('type', 20);                          // receipt | issue | return | count
            $table->string('reason', 50)->nullable();
            $table->decimal('qty', 15, 3);                       // + nhập, - xuất
            $table->decimal('balance_after', 15, 3);
            $table->decimal('unit_cost', 15, 2)->default(0);     // giá vốn tại thời điểm ghi
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_document_lines');
        Schema::dropIfExists('stock_documents');
        Schema::dropIfExists('stock_levels');
    }
};
