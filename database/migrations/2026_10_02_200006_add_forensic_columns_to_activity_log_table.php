<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra forensic columns for the audit trail (IP, request URL, method, user agent).
 * spatie/laravel-activitylog stores causer, subject, event, description and
 * properties (old/new values) out of the box; these columns capture the
 * request context required by the institutional audit policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('batch_uuid');
            $table->string('url', 2048)->nullable()->after('ip_address');
            $table->string('method', 10)->nullable()->after('url');
            $table->text('user_agent')->nullable()->after('method');

            $table->index(['created_at']);
            $table->index(['event']);
            $table->index(['ip_address']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'url', 'method', 'user_agent']);
        });
    }
};
