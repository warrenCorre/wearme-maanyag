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
        Schema::create('tbl_password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 150)->primary(); // primary key, references users.email
            $table->string('token', 255);
            $table->dateTime('created_at')->nullable(); // token generation time
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_password_reset_tokens');
    }
};