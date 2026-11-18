<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Juros do parcelamento repassados à cliente (total = subtotal - descontos + frete + juros)
            $table->unsignedInteger('interest_total')->default(0)->after('shipping_total');
            $table->foreignId('shipping_method_id')->nullable()->after('shipping_method')->constrained()->nullOnDelete();
            $table->string('shipping_estimate')->nullable()->after('shipping_method_id');
            $table->timestamp('stock_released_at')->nullable()->after('canceled_at');
            $table->timestamp('last_gateway_check_at')->nullable()->after('stock_released_at');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->json('properties')->nullable()->after('total'); // opções escolhidas (Cor, Tamanho...)
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('properties'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_method_id');
            $table->dropColumn(['interest_total', 'shipping_estimate', 'stock_released_at', 'last_gateway_check_at']);
        });
    }
};
