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
        Schema::create('tbl_ai_embeddings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('source_type', 50);
            $table->unsignedInteger('source_id');
            $table->text('content_text');
            $table->json('embedding_vector');
            $table->dateTime('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_ai_embeddings');
    }
};