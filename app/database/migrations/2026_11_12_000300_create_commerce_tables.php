<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes, carrinho, frete, descontos, pedidos e pagamentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 20)->nullable();
            $table->string('document', 14)->nullable()->index(); // CPF só dígitos
            $table->string('password')->nullable();               // conta opcional (checkout como convidado)
            $table->boolean('accepts_marketing')->default(true);
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedBigInteger('total_spent')->default(0);
            $table->timestamp('last_order_at')->nullable();
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('zip', 9);
            $table->string('street');
            $table->string('number', 20);
            $table->string('complement')->nullable();
            $table->string('neighborhood');
            $table->string('city');
            $table->char('state', 2);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');                    // "Frete Grátis", "Entrega Expressa"
            $table->string('description')->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->unsignedInteger('free_above')->nullable(); // grátis acima de X (centavos)
            $table->unsignedSmallInteger('min_days')->nullable();
            $table->unsignedSmallInteger('max_days')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('code')->nullable()->unique();   // null = desconto automático
            /*
             * percentage      → % sobre itens elegíveis
             * fixed_amount    → R$ fixo no pedido
             * free_shipping   → frete grátis
             * buy_x_get_y     → compre X leve Y (rules: {buy_qty, get_qty, get_percent})
             * bundle          → combo de itens específicos (rules: {items:[{product_id,qty}], price|percent})
             * tiered          → progressivo por qtd (rules: {tiers:[{min_qty:2,percent:10},{min_qty:3,percent:15}]})
             * payment_method  → desconto por forma de pagamento (rules: {method:"pix"})
             */
            $table->string('type', 30);
            $table->unsignedInteger('value')->default(0);   // % (ex. 10) ou centavos, conforme o tipo
            $table->string('applies_to', 20)->default('all'); // all|categories|products
            $table->json('rules')->nullable();
            $table->unsignedInteger('min_subtotal')->nullable();
            $table->unsignedInteger('min_quantity')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('combinable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('discount_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->morphs('target'); // Product | Category
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('discount_code')->nullable();
            $table->foreignId('shipping_method_id')->nullable()->constrained()->nullOnDelete();
            $table->json('checkout_data')->nullable();      // endereço/etapas preenchidas
            $table->json('attribution')->nullable();        // utm_*, fbclid, fbp, fbc, ttclid, gclid, referrer, landing
            $table->string('status', 20)->default('active')->index(); // active|abandoned|converted
            $table->unsignedTinyInteger('recovery_emails_sent')->default(0);
            $table->timestamp('last_recovery_email_at')->nullable();
            $table->timestamp('checkout_started_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['cart_id', 'variant_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('number')->unique();     // nº público (#10001)
            $table->uuid('public_token')->unique();             // link de acompanhamento sem login
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('pending')->index();
            // pending|paid|processing|shipped|delivered|canceled|refunded
            $table->string('payment_status', 20)->default('pending')->index();
            // pending|processing|paid|refused|canceled|refunded|chargeback|med|failed
            $table->string('fulfillment_status', 20)->default('unfulfilled');

            $table->string('customer_name');
            $table->string('customer_email')->index();
            $table->string('customer_phone', 20);
            $table->string('customer_document', 14);
            $table->json('shipping_address');

            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('discount_total')->default(0);
            $table->unsignedInteger('shipping_total')->default(0);
            $table->unsignedInteger('total');
            $table->string('discount_codes')->nullable();
            $table->string('shipping_method')->nullable();

            $table->string('payment_method', 20);   // pix|credit_card
            $table->unsignedTinyInteger('installments')->default(1);
            $table->string('gateway', 20)->default('nixfy');
            $table->string('gateway_uuid')->nullable()->index();
            $table->text('pix_code')->nullable();
            $table->mediumText('pix_qr_base64')->nullable();
            $table->timestamp('pix_expires_at')->nullable();
            $table->string('card_brand', 20)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->unsignedInteger('gateway_fee')->nullable();

            $table->string('tracking_code')->nullable();
            $table->string('tracking_url')->nullable();
            $table->string('carrier')->nullable();

            // Atribuição / rastreamento (casamento de alta qualidade no Meta CAPI)
            $table->json('attribution')->nullable();
            $table->string('utm_source')->nullable()->index();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable()->index();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('fbp')->nullable();
            $table->string('fbc')->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('event_id', 64)->nullable(); // dedupe Pixel x CAPI (Purchase)
            $table->timestamp('purchase_event_sent_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('title');
            $table->string('variant_title')->nullable();
            $table->string('sku')->nullable();
            $table->string('image')->nullable();
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('compare_at_price')->nullable();
            $table->unsignedInteger('unit_cost')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedInteger('total');
            $table->timestamps();
        });

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('code')->nullable();
            $table->unsignedInteger('amount');
        });

        // Linha do tempo do pedido (admin) — cada mudança de status, e-mail enviado, nota interna.
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('message');
            $table->json('data')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 20);
            $table->string('gateway_uuid')->nullable()->index();
            $table->string('method', 20);
            $table->string('status', 30);
            $table->unsignedInteger('amount');
            $table->json('request')->nullable();   // nunca contém número de cartão/CVV (sanitizado)
            $table->json('response')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps();
        });

        // Idempotência dos webhooks (X-Nixfy-Event-Id)
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id')->nullable();
            $table->string('event_type', 50)->nullable();
            $table->json('payload');
            $table->string('status', 20)->default('received'); // received|processed|failed|ignored
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        foreach (['webhook_events', 'payment_transactions', 'order_events', 'order_discounts', 'order_items', 'orders',
                  'cart_items', 'carts', 'discount_targets', 'discounts', 'shipping_methods', 'customer_addresses', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
