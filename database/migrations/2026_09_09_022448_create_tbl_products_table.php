<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tbl_products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('product_code', 50)->unique();
            $table->string('product_name', 150);
            $table->unsignedInteger('category_id');
            $table->text('description');
            $table->decimal('price', 10, 2);
            $table->integer('quantity');
            $table->integer('low_stock_threshold');
            $table->string('image', 255);
            $table->enum('status', ['active', 'archived']);
            $table->unsignedInteger('created_by');
            $table->unsignedInteger('updated_by');
            $table->dateTime('deleted_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->foreign('category_id')
                ->references('id')
                ->on('tbl_categories');

            $table->foreign('created_by')
                ->references('id')
                ->on('tbl_users');

            $table->foreign('updated_by')
                ->references('id')
                ->on('tbl_users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_products');
    }
};