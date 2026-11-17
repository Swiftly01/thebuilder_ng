<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_channels', function (Blueprint $table): void {
            $table->ulid('reconcile_after_id')->nullable();
        });
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->timestamp('bucket_at')->nullable();
            $table->unique(['article_id', 'session_hash', 'type', 'bucket_at'], 'analytics_deduplication');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->dropUnique('analytics_deduplication');
            $table->dropColumn('bucket_at');
        });
        Schema::table('youtube_channels', function (Blueprint $table): void {
            $table->dropColumn('reconcile_after_id');
        });
    }
};
