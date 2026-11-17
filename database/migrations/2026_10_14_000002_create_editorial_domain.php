<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->foreignUlid('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->text('description')->nullable();
            $t->timestamps();
        });
        Schema::create('tags', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->timestamps();
        });
        Schema::create('media', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('path')->unique();
            $t->string('mime', 80);
            $t->unsignedBigInteger('size');
            $t->string('alt');
            $t->string('credit')->nullable();
            $t->timestamps();
        });
        Schema::create('articles', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('title', 180);
            $t->string('slug')->unique();
            $t->string('type', 40)->default('article');
            $t->text('summary')->nullable();
            $t->longText('body')->nullable();
            $t->foreignUlid('author_id')->constrained('users')->restrictOnDelete();
            $t->foreignUlid('category_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('media_id')->nullable()->constrained('media')->nullOnDelete();
            $t->string('status', 24)->default('draft');
            $t->string('edition', 24)->default('both');
            $t->string('industry')->nullable();
            $t->string('country')->nullable();
            $t->boolean('is_sponsored')->default(false);
            $t->string('sponsor_name')->nullable();
            $t->json('seo')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('view_count')->default(0);
            $t->timestamp('scheduled_for')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['status', 'published_at']);
            $t->index(['edition', 'status', 'published_at']);
            $t->index(['category_id', 'status']);
        });
        Schema::create('article_tag', function (Blueprint $t): void {
            $t->foreignUlid('article_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('tag_id')->constrained()->cascadeOnDelete();
            $t->primary(['article_id', 'tag_id']);
        });
        Schema::create('article_revisions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('article_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->text('note')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['article_id', 'version']);
        });
        Schema::create('homepage_sections', function (Blueprint $t): void {
            $t->id();
            $t->string('key')->unique();
            $t->unsignedSmallInteger('position');
            $t->boolean('visible')->default(true);
            $t->json('pinned_ids')->nullable();
            $t->timestamps();
        });
        Schema::create('redirects', function (Blueprint $t): void {
            $t->id();
            $t->string('from_path')->unique();
            $t->string('to_path');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['redirects', 'homepage_sections', 'article_revisions', 'article_tag', 'articles', 'media', 'tags', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
