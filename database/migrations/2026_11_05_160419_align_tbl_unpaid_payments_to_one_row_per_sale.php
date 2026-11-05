<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('tbl_unpaid_payments')->exists()) {
            throw new RuntimeException('Cannot replace unpaid item rows while unpaid payment records exist. Back up and reconcile them first.');
        }

        Schema::table('tbl_unpaid_payments', function (Blueprint $table) {
            $table->dropForeign(['transaction_id']);
            $table->dropForeign(['product_id']);
        });

        Schema::table('tbl_unpaid_payments', function (Blueprint $table) {
            $table->dropColumn(['product_id', 'quantity', 'customer_name', 'customer_contact', 'item_description']);
            $table->unsignedInteger('transaction_id')->nullable(false)->change();
        });

        Schema::table('tbl_unpaid_payments', function (Blueprint $table) {
            $table->unique('transaction_id');
            $table->foreign('transaction_id')->references('id')->on('tbl_sales_transactions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('tbl_unpaid_payments')->exists()) {
            throw new RuntimeException('Cannot restore item-level unpaid columns while sale balances exist.');
        }

        Schema::table('tbl_unpaid_payments', function (Blueprint $table) {
            $table->dropForeign(['transaction_id']);
            $table->dropUnique(['transaction_id']);
        });

        Schema::table('tbl_unpaid_payments', function (Blueprint $table) {
            $table->unsignedInteger('transaction_id')->nullable()->change();
            $table->unsignedInteger('product_id');
            $table->integer('quantity');
            $table->string('customer_name', 150);
            $table->string('customer_contact', 20);
            $table->string('item_description', 255);
        });

        Schema::table('tbl_unpaid_payments', function (Blueprint $table) {
            $table->foreign('transaction_id')->references('id')->on('tbl_sales_transactions');
            $table->foreign('product_id')->references('id')->on('tbl_products');
        });
    }
};
