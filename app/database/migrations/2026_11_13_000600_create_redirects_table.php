<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Redirecionamentos 301 (links antigos, produtos renomeados/removidos) — editáveis no admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path')->unique();   // /products/nome-antigo
            $table->string('to_url', 500);           // /products/nome-novo ou URL completa
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
