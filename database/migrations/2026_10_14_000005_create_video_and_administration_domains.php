<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_channels', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('provider_id')->unique();
            $t->string('title')->nullable();
            $t->string('uploads_playlist')->nullable();
            $t->boolean('active')->default(true);
            $t->boolean('auto_publish')->default(false);
            $t->string('edition', 24)->default('both');
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('videos', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('youtube_channel_id')->constrained()->cascadeOnDelete();
            $t->string('youtube_id', 11)->unique();
            $t->string('provider_title');
            $t->text('provider_description')->nullable();
            $t->string('title')->nullable();
            $t->text('description')->nullable();
            $t->string('thumbnail_url')->nullable();
            $t->unsignedInteger('duration_seconds')->default(0);
            $t->timestamp('provider_published_at')->nullable();
            $t->boolean('available')->default(false);
            $t->boolean('embeddable')->default(false);
            $t->string('status', 24)->default('imported');
            $t->string('edition', 24)->default('both');
            $t->boolean('featured')->default(false);
            $t->timestamps();
            $t->index(['status', 'available', 'provider_published_at']);
        });
        Schema::create('youtube_sync_runs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('youtube_channel_id')->constrained()->cascadeOnDelete();
            $t->string('status', 24);
            $t->unsignedInteger('fetched')->default(0);
            $t->unsignedInteger('requests')->default(0);
            $t->string('error_code')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
        });
        Schema::create('site_settings', function (Blueprint $t): void {
            $t->string('key')->primary();
            $t->json('value');
            $t->boolean('public')->default(false);
            $t->timestamps();
        });
        Schema::create('campaigns', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('advertiser');
            $t->string('placement', 48);
            $t->foreignUlid('media_id')->nullable()->constrained('media')->nullOnDelete();
            $t->string('target_url');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('status', 24)->default('draft');
            $t->unsignedBigInteger('impressions')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->timestamps();
            $t->index(['placement', 'status', 'starts_at', 'ends_at']);
        });
        Schema::create('analytics_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('article_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 24);
            $t->string('session_hash', 64);
            $t->unsignedInteger('read_seconds')->default(0);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['article_id', 'type', 'created_at']);
        });
        Schema::create('legacy_import_map', function (Blueprint $t): void {
            $t->id();
            $t->string('source', 80);
            $t->string('source_id', 80);
            $t->ulid('target_id');
            $t->string('checksum', 64);
            $t->timestamps();
            $t->unique(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        foreach (['legacy_import_map', 'analytics_events', 'campaigns', 'site_settings', 'youtube_sync_runs', 'videos', 'youtube_channels'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
