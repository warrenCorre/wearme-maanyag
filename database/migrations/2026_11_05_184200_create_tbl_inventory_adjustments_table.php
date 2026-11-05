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
        Schema::create('tbl_inventory_adjustments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('adjusted_by');
            $table->enum('direction', ['increase', 'decrease']);
            $table->unsignedInteger('quantity');
            $table->integer('quantity_before');
            $table->integer('quantity_after');
            $table->string('reason', 255);
            $table->dateTime('created_at');

            $table->index(['product_id', 'created_at', 'id']);
            $table->foreign('product_id')->references('id')->on('tbl_products')->restrictOnDelete();
            $table->foreign('adjusted_by')->references('id')->on('tbl_users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('tbl_inventory_adjustments')->exists()) {
            throw new RuntimeException('Cannot remove inventory adjustment history while records exist.');
        }

        Schema::dropIfExists('tbl_inventory_adjustments');
    }
};
