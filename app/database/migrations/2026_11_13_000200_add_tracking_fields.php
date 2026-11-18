<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identificadores de 1ª parte usados para casar eventos do navegador e do servidor
 * (Meta CAPI, TikTok Events API, GA4 Measurement Protocol) com alta qualidade de correspondência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('visitor_id')->nullable()->index()->after('fbc'); // external_id
            $table->string('ttclid')->nullable()->after('visitor_id');
            $table->string('ttp')->nullable()->after('ttclid');            // cookie _ttp do TikTok
            $table->string('ga_client_id', 64)->nullable()->after('ttp');  // cookie _ga
            $table->string('page_url', 500)->nullable()->after('ga_client_id');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->uuid('visitor_id')->nullable()->index()->after('customer_id');
        });

        Schema::table('tracking_dispatches', function (Blueprint $table) {
            $table->timestamp('send_after')->nullable()->index()->after('attempts');
            $table->text('last_error')->nullable()->after('send_after');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_dispatches', fn (Blueprint $t) => $t->dropColumn(['send_after', 'last_error']));
        Schema::table('carts', fn (Blueprint $t) => $t->dropColumn('visitor_id'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['visitor_id', 'ttclid', 'ttp', 'ga_client_id', 'page_url']));
    }
};
