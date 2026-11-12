<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('sort_name')->nullable()->index();
            $table->string('gender', 10)->nullable();          // male | female
            $table->string('avatar')->nullable();              // 'preset:nam' hoặc 'uploads/avatars/xxx.jpg'
            $table->string('phone', 10)->nullable()->unique();
            $table->string('address')->nullable();
            $table->string('occupation')->nullable();
            $table->text('note')->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->decimal('total_spent', 15, 2)->default(0); // module Bán hàng cộng dồn qua CustomerService

            // Dành cho cổng khách hàng (module Portal, làm sau)
            $table->string('password')->nullable();
            $table->boolean('can_login')->default(false);
            $table->boolean('must_change_password')->default(true);
            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
