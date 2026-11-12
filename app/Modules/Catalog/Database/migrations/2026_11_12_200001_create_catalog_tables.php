<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('barcode', 50)->nullable()->unique();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('qty_pricing')->default(false);   // tự chuyển giá theo số lượng
            $table->decimal('cost_price', 15, 2)->default(0); // giá vốn; module Inventory sẽ cập nhật bình quân
            $table->boolean('track_stock')->default(false);   // có theo dõi tồn kho không (số tồn nằm ở Inventory)
            $table->string('image')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 15, 2)->default(0);
            // Số lượng tối thiểu để áp dụng bảng giá này (bảng giá bán lẻ để trống)
            $table->decimal('min_qty', 15, 3)->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'price_list_id']);
        });

        $now = now();
        DB::table('units')->insert(['name' => 'Cái', 'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('price_lists')->insert(['name' => 'Bán lẻ', 'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
    }
};
