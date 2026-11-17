<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builders', function (Blueprint $table): void {
            $table->boolean('is_diaspora')->default(false)->index();
        });
        Schema::table('articles', function (Blueprint $table): void {
            $table->string('region', 100)->nullable();
            $table->index(['region', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex(['region', 'status', 'published_at']);
            $table->dropColumn('region');
        });
        Schema::table('builders', function (Blueprint $table): void {
            $table->dropIndex(['is_diaspora']);
            $table->dropColumn('is_diaspora');
        });
    }
};
