<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcas (fabricantes) dos produtos: página própria /marcas/{slug}, filtro nas coleções,
 * selo no card e "brand" correto no feed do Meta/Google.
 * Ficha técnica (tabela nutricional, modo de uso) vai em products.specs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->string('banner_image')->nullable();
            $table->string('color', 9)->nullable();        // cor de destaque na página da marca
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('vendor')->constrained()->nullOnDelete();
            $table->json('specs')->nullable()->after('description');   // tabela nutricional, ingredientes, modo de uso
            $table->string('source_url')->nullable()->after('specs')->index(); // página do fabricante (referência interna)
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->string('source_url', 500)->nullable()->after('path'); // foto original (reimportar sem duplicar)
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn(['specs', 'source_url']);
        });
        Schema::table('product_images', fn (Blueprint $table) => $table->dropColumn('source_url'));
        Schema::dropIfExists('brands');
    }
};
