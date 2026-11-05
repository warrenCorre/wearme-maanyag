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
        Schema::table('tbl_sales_transactions', function (Blueprint $table) {
            $table->dropForeign(['cashier_id']);
        });

        Schema::table('tbl_sales_transactions', function (Blueprint $table) {
            $table->renameColumn('cashier_id', 'recorded_by');
        });

        Schema::table('tbl_sales_transactions', function (Blueprint $table) {
            $table->foreign('recorded_by')->references('id')->on('tbl_users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_sales_transactions', function (Blueprint $table) {
            $table->dropForeign(['recorded_by']);
        });

        Schema::table('tbl_sales_transactions', function (Blueprint $table) {
            $table->renameColumn('recorded_by', 'cashier_id');
        });

        Schema::table('tbl_sales_transactions', function (Blueprint $table) {
            $table->foreign('cashier_id')->references('id')->on('tbl_users');
        });
    }
};
