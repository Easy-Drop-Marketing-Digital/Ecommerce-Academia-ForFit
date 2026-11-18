<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conteúdo editável no admin: configurações, menus, banners, seções da home e páginas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false); // tokens (ex.: CAPI) gravados criptografados
            $table->timestamps();
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('handle')->unique(); // main | footer | footer-institucional...
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->string('title');
            $table->string('link_type', 20)->default('url'); // category|product|page|url|home
            $table->unsignedBigInteger('link_id')->nullable();
            $table->string('url')->nullable();
            $table->string('image')->nullable();             // mega-menu opcional
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('placement', 50)->index(); // home_hero_1, home_hero_2, top_bar, category_top...
            $table->string('title')->nullable();
            $table->string('image_desktop');
            $table->string('image_mobile')->nullable();
            $table->string('link')->nullable();
            $table->string('alt')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();
        });

        // Home montada por seções ordenáveis, como no tema Vision da Shopify.
        Schema::create('home_sections', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);   // slideshow|collection_list|product_list|announcement|image_text|countdown
            $table->string('title')->nullable();
            $table->json('settings')->nullable(); // {placement:"home_hero_1"} / {category_id:3, limit:8}
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
        Schema::dropIfExists('home_sections');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('settings');
    }
};
