<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código da peça no fornecedor (dropshipping): uso interno, só na planilha do fornecedor.
 * O SKU público (página, feed, gateway) é neutro, da própria loja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', fn (Blueprint $t) => $t->string('supplier_sku')->nullable()->after('sku'));
        Schema::table('order_items', fn (Blueprint $t) => $t->string('supplier_sku')->nullable()->after('sku'));
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropColumn('supplier_sku'));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('supplier_sku'));
    }
};
