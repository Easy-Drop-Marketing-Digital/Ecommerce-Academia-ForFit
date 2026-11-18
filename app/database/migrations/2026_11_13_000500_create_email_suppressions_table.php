<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Quem pediu para não receber e-mails de marketing (vale também para quem nunca comprou). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason', 30)->default('unsubscribe'); // unsubscribe|admin
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
