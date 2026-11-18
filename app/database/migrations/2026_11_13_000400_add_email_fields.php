<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('pix_reminder_sent_at')->nullable()->after('pix_expires_at');
        });
        Schema::table('email_logs', function (Blueprint $table) {
            $table->index(['template', 'to']);
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', fn (Blueprint $t) => $t->dropIndex(['template', 'to']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('pix_reminder_sent_at'));
    }
};
