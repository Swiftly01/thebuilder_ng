<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['bookmarks', 'reading_history'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
                $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
                $t->foreignUlid('article_id')->constrained()->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['user_id', 'article_id']);
            });
        }
        Schema::create('reference_counters', function (Blueprint $t): void {
            $t->string('key')->primary();
            $t->unsignedBigInteger('value')->default(0);
        });
        Schema::create('nominations', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('reference')->unique();
            $t->string('type', 24);
            $t->string('name');
            foreach (['industry', 'country', 'city', 'website', 'nominee_email', 'nominee_phone', 'submitter_name', 'submitter_email', 'relationship'] as $k) {
                $t->string($k)->nullable();
            }$t->text('reason');
            $t->text('links')->nullable();
            $t->timestamp('consent_at');
            $t->string('status', 32)->default('received')->index();
            $t->text('review_note')->nullable();
            $t->foreignUlid('article_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('story_submissions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('reference')->unique();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('title', 180);
            $t->text('pitch');
            $t->text('why_now')->nullable();
            $t->text('links')->nullable();
            $t->string('status', 24)->default('received');
            $t->text('review_note')->nullable();
            $t->foreignUlid('article_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('newsletter_subscriptions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('email')->unique();
            $t->string('edition', 24)->default('Nigeria');
            $t->string('status', 24)->default('pending');
            $t->string('token_hash', 64)->nullable()->unique();
            $t->string('unsubscribe_hash', 64)->nullable()->unique();
            $t->timestamp('token_expires_at')->nullable();
            $t->timestamp('consent_at');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['newsletter_subscriptions', 'story_submissions', 'nominations', 'reference_counters', 'reading_history', 'bookmarks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
