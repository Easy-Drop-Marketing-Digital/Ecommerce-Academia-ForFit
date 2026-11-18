<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retirada na loja/academia: nunca é a forma de entrega pré-selecionada e não mostra "receba entre".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->boolean('is_pickup')->default(false)->after('max_days');
        });
        DB::table('shipping_methods')->where('name', 'like', '%retir%')->update(['is_pickup' => true]);
    }

    public function down(): void
    {
        Schema::table('shipping_methods', fn (Blueprint $table) => $table->dropColumn('is_pickup'));
    }
};
