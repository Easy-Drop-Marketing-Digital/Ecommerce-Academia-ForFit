<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics próprio (funil completo) e log de envios para plataformas (Meta CAPI, TikTok, e-mails).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_key')->unique();
            $table->uuid('visitor_id')->index();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('landing_page', 500)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('source', 60)->nullable()->index(); // facebook|instagram|google|tiktok|direct|email...
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable()->index();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('device', 20)->nullable();          // mobile|desktop|tablet
            $table->string('country', 2)->nullable();
            $table->string('region', 60)->nullable();
            $table->unsignedInteger('pageviews')->default(0);
            $table->timestamp('started_at')->index();
            $table->timestamp('last_seen_at');
        });

        // Funil: page_view, view_item, add_to_cart, begin_checkout, add_shipping_info, add_payment_info, purchase
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 40)->index();
            $table->string('path', 500)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('value')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->index();
        });

        // Agregado diário (cron) para dashboards rápidos mesmo com muito tráfego na Black Friday
        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->date('date');
            $table->string('dimension', 30)->default('total'); // total|source|campaign|product|device
            $table->string('dimension_value')->default('');
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('product_views')->default(0);
            $table->unsignedInteger('add_to_carts')->default(0);
            $table->unsignedInteger('checkouts')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->unsignedInteger('paid_orders')->default(0);
            $table->unsignedBigInteger('revenue')->default(0);
            $table->unsignedBigInteger('paid_revenue')->default(0);
            $table->primary(['date', 'dimension', 'dimension_value']);
        });

        // Toda chamada server-side para plataformas de anúncio fica registrada (auditoria + reenvio)
        Schema::create('tracking_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20)->index(); // meta|tiktok|google|utmify
            $table->string('event_name', 40);
            $table->string('event_id', 64)->index();
            $table->nullableMorphs('subject');      // Order | Cart
            $table->json('payload');
            $table->json('response')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('status', 20)->default('pending'); // pending|sent|failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('template', 50)->index();
            $table->string('to');
            $table->string('subject');
            $table->nullableMorphs('subject_model');
            $table->string('status', 20)->default('queued'); // queued|sent|failed
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('tracking_dispatches');
        Schema::dropIfExists('analytics_daily');
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('visitor_sessions');
    }
};
