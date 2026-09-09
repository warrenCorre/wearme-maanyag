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
        Schema::create('tbl_sales_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('transaction_no', 20)->unique();
            $table->enum('sale_type', ['walk_in', 'online']);
            $table->string('online_platform', 50);
            $table->decimal('total_amount', 10, 2);
            $table->enum('payment_status', ['paid', 'unpaid', 'partially_paid']);
            $table->unsignedInteger('cashier_id');
            $table->dateTime('transaction_date');

            $table->foreign('cashier_id')
                ->references('id')
                ->on('tbl_users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_sales_transactions');
    }
};