<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo: categorias (coleções), produtos, opções, variantes e imagens.
 * Todos os valores monetários são inteiros em centavos (mesmo padrão da Nixfy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('banner_image')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('sort_order', 30)->default('manual'); // manual|best_selling|price_asc|price_desc|newest
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->unsignedBigInteger('shopify_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('description')->nullable();
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->json('tags')->nullable();
            $table->string('status', 20)->default('active')->index(); // active|draft|archived
            $table->boolean('track_stock')->default(true);
            $table->boolean('allow_backorder')->default(false);
            $table->unsignedInteger('min_price')->default(0)->index();    // cache p/ listagens
            $table->unsignedInteger('max_compare_price')->nullable();
            $table->unsignedInteger('sales_count')->default(0)->index();  // ordenação "mais vendidos"
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('google_category')->nullable();               // feed Meta/Google
            $table->unsignedBigInteger('shopify_id')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->primary(['category_id', 'product_id']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');          // caminho local (storage) ou URL absoluta
            $table->string('alt')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedBigInteger('shopify_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');                      // "Cor", "Tamanho"...
            $table->string('display_type', 20)->default('button'); // button|swatch|select
            $table->unsignedTinyInteger('position')->default(1);
            $table->json('values');                      // ["P","M","G"]
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('image_id')->nullable()->constrained('product_images')->nullOnDelete();
            $table->string('sku')->nullable()->index();
            $table->string('title');
            $table->string('option1')->nullable();
            $table->string('option2')->nullable();
            $table->string('option3')->nullable();
            $table->unsignedInteger('price');
            $table->unsignedInteger('compare_at_price')->nullable();
            $table->unsignedInteger('cost')->nullable();             // custo do fornecedor (margem no analytics)
            $table->integer('stock')->default(0);
            $table->unsignedInteger('weight_grams')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('shopify_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->integer('quantity');                 // +entrada / -saída
            $table->integer('stock_after');
            $table->string('reason', 40);                // order|cancel|manual|import|refund
            $table->nullableMorphs('reference');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
