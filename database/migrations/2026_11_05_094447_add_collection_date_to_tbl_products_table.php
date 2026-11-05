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
        Schema::table('tbl_products', function (Blueprint $table) {
            $table->date('collection_date')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('tbl_products')->whereNotNull('collection_date')->exists()) {
            throw new RuntimeException('Cannot remove collection dates while products have recorded dates.');
        }

        Schema::table('tbl_products', function (Blueprint $table) {
            $table->dropColumn('collection_date');
        });
    }
};
