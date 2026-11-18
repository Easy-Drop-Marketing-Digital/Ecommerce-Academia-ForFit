<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author');
            $table->string('email')->nullable();
            $table->unsignedTinyInteger('rating');            // 1 a 5
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('media')->nullable();                // [{type:image|video, url}]
            $table->string('status', 20)->default('pending')->index(); // pending|approved|hidden
            $table->boolean('verified')->default(false);      // compra confirmada
            $table->boolean('featured')->default(false);      // destacada no topo
            $table->text('reply')->nullable();                // resposta da loja (pública)
            $table->timestamp('replied_at')->nullable();
            $table->string('source', 20)->default('customer'); // customer|import|admin
            $table->string('external_id')->nullable()->unique(); // id no app antigo (evita duplicar na reimportação)
            $table->timestamps();
            $table->index(['product_id', 'status', 'featured']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('rating_avg', 3, 2)->default(0)->after('sales_count');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_avg');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['rating_avg', 'rating_count']));
        Schema::dropIfExists('reviews');
    }
};
