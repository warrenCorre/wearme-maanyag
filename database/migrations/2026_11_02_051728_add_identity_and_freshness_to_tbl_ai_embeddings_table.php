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
        if (DB::table('tbl_ai_embeddings')->exists()) {
            throw new RuntimeException('Cannot add required embedding identity and freshness metadata while embedding records exist.');
        }

        Schema::table('tbl_ai_embeddings', function (Blueprint $table) {
            $table->string('source_key', 100)->nullable(false)->change();
            $table->char('content_hash', 64);
            $table->string('embedding_model', 100);
            $table->unsignedSmallInteger('embedding_dimensions');
            $table->unique(['source_type', 'source_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('tbl_ai_embeddings')->exists()) {
            throw new RuntimeException('Cannot remove embedding identity and freshness metadata while embedding records exist.');
        }

        Schema::table('tbl_ai_embeddings', function (Blueprint $table) {
            $table->dropUnique(['source_type', 'source_key']);
            $table->dropColumn(['content_hash', 'embedding_model', 'embedding_dimensions']);
            $table->string('source_key', 100)->nullable()->change();
        });
    }
};
