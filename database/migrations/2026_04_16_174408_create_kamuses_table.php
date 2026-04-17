<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up() {
        Schema::create('kamus', function (Blueprint $table) {
            $table->id();
            $table->string('indonesia')->index();
            $table->string('dialek_a');
            $table->string('dialek_o');
            $table->string('audio_a')->nullable(); // Nama file audio Dialek A
            $table->string('audio_o')->nullable(); // Nama file audio Dialek O
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kamuses');
    }
};
