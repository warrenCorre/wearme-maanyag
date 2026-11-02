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
        Schema::table('tbl_ai_embeddings', function (Blueprint $table) {
            $table->unsignedInteger('source_id')->nullable()->change();
            $table->string('source_key', 100)->nullable();
            $table->date('reference_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('tbl_ai_embeddings')->exists()) {
            throw new RuntimeException('Cannot remove embedding source metadata while embedding records exist.');
        }

        Schema::table('tbl_ai_embeddings', function (Blueprint $table) {
            $table->dropColumn(['source_key', 'reference_date']);
            $table->unsignedInteger('source_id')->change();
        });
    }
};
