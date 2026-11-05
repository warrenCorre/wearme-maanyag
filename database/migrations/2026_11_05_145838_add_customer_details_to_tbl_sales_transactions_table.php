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
        Schema::table('tbl_sales_transactions', function (Blueprint $table) {
            $table->string('customer_name', 150)->nullable()->after('online_platform');
            $table->string('customer_contact', 20)->nullable()->after('customer_name');
            $table->string('delivery_address', 255)->nullable()->after('customer_contact');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('tbl_sales_transactions')
            ->whereNotNull('customer_name')
            ->orWhereNotNull('customer_contact')
            ->orWhereNotNull('delivery_address')
            ->exists()) {
            throw new RuntimeException('Cannot remove customer details while sales have recorded customer information.');
        }

        Schema::table('tbl_sales_transactions', function (Blueprint $table) {
            $table->dropColumn(['customer_name', 'customer_contact', 'delivery_address']);
        });
    }
};
