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
        Schema::create('tbl_unpaid_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('product_id');
            $table->integer('quantity');
            $table->string('customer_name', 150);
            $table->string('customer_contact', 20);
            $table->string('item_description', 255);
            $table->decimal('amount_paid', 10, 2);
            $table->decimal('balance', 10, 2);
            $table->dateTime('payment_date')->nullable();
            $table->enum('status', ['pending', 'settled']);
            $table->unsignedInteger('updated_by');

            $table->foreign('transaction_id')
                ->references('id')
                ->on('tbl_sales_transactions');

            $table->foreign('product_id')
                ->references('id')
                ->on('tbl_products');

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
        Schema::dropIfExists('tbl_unpaid_payments');
    }
};