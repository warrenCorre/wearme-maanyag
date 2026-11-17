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
        Schema::create('tbl_store_activity_events', function (Blueprint $table) {
            $table->increments('id');
            $table->string('event_type', 40);
            $table->string('subject_type', 32);
            $table->unsignedInteger('subject_id');
            $table->unsignedInteger('actor_id');
            $table->dateTime('created_at');

            $table->index(['created_at', 'id']);
            $table->index(['event_type', 'created_at']);
            $table->foreign('actor_id')->references('id')->on('tbl_users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('tbl_store_activity_events')->exists()) {
            throw new RuntimeException('Cannot remove store activity history while records exist.');
        }

        Schema::dropIfExists('tbl_store_activity_events');
    }
};
