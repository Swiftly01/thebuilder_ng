<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $t): void {
            $t->json('body_document')->nullable();
        });
        Schema::table('media', function (Blueprint $t): void {
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
        });
        Schema::create('article_media_uses', function (Blueprint $t): void {
            $t->id();
            $t->foreignUlid('article_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('revision_id')->nullable()->constrained('article_revisions')->cascadeOnDelete();
            $t->foreignUlid('media_id')->constrained('media')->restrictOnDelete();
            $t->index(['article_id', 'revision_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_media_uses');
        Schema::table('articles', fn (Blueprint $t) => $t->dropColumn('body_document'));
        Schema::table('media', fn (Blueprint $t) => $t->dropColumn(['width', 'height']));
    }
};
