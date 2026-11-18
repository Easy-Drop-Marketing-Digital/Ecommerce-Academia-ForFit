<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Verificação em duas etapas do admin (app autenticador). Valores criptografados pela APP_KEY. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
