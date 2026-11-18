<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antifraude de cartão SEM guardar número completo nem CVV.
 *
 * card_fingerprint = HMAC-SHA256(número do cartão, chave derivada da APP_KEY).
 * O mesmo cartão gera sempre a mesma impressão digital, então dá para saber se ele
 * aparece em várias contas/CPFs — mas não é possível recuperar o número a partir dela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('card_fingerprint', 64)->nullable()->index()->after('card_last4');
            $table->string('card_bin', 8)->nullable()->after('card_fingerprint');
            $table->string('card_holder')->nullable()->after('card_bin');
            $table->string('card_exp', 7)->nullable()->after('card_holder'); // MM/AAAA
            $table->unsignedTinyInteger('risk_score')->default(0)->after('card_exp');
            $table->json('risk_flags')->nullable()->after('risk_score');
        });

        // Toda tentativa de pagamento com cartão (inclusive recusadas) — base do antifraude e do anti "teste de cartão"
        Schema::create('card_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fingerprint', 64)->index();
            $table->string('bin', 8)->nullable();
            $table->string('last4', 4)->nullable();
            $table->string('brand', 20)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('document', 14)->nullable()->index();
            $table->string('ip', 45)->nullable()->index();
            $table->string('status', 20); // blocked|paid|refused|processing|error
            $table->unsignedInteger('amount')->default(0);
            $table->json('flags')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_attempts');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['card_fingerprint', 'card_bin', 'card_holder', 'card_exp', 'risk_score', 'risk_flags']);
        });
    }
};
