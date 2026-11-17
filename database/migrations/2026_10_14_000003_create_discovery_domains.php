<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builders', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('classification', 64);
            $t->string('headline')->nullable();
            $t->text('biography')->nullable();

            foreach (['industry', 'profession', 'organisation', 'country', 'city', 'region', 'website'] as $k) {
                $length = in_array($k, ['country', 'region'], true) ? 100 : 191;
                $t->string($k, $length)->nullable();
            }

            $t->foreignUlid('media_id')->nullable()->constrained('media')->nullOnDelete();
            $t->json('achievements')->nullable();
            $t->json('social_links')->nullable();
            $t->string('status', 24)->default('draft')->index();
            $t->timestamp('consent_at')->nullable();
            $t->string('consent_method')->nullable();
            $t->string('feature', 24)->nullable();
            $t->timestamp('feature_from')->nullable();
            $t->timestamp('feature_until')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['country', 'region', 'status']);
        });

        Schema::create('startups', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('one_liner')->nullable();
            $t->text('description')->nullable();

            foreach (['industry', 'stage', 'funding_stage', 'country', 'city', 'region', 'website'] as $k) {
                $length = in_array($k, ['country', 'industry'], true) ? 100 : 191;
                $t->string($k, $length)->nullable();
            }

            $t->unsignedSmallInteger('founded_year')->nullable();
            $t->unsignedInteger('team_size')->nullable();
            $t->foreignUlid('media_id')->nullable()->constrained('media')->nullOnDelete();
            $t->string('status', 24)->default('draft')->index();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['country', 'industry', 'status']);
        });

        Schema::create('startup_founders', function (Blueprint $t): void {
            $t->foreignUlid('startup_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('builder_id')->constrained()->cascadeOnDelete();
            $t->string('role')->nullable();
            $t->primary(['startup_id', 'builder_id']);
        });

        foreach (['article_builder' => 'builder_id', 'article_startup' => 'startup_id'] as $table => $key) {
            Schema::create($table, function (Blueprint $t) use ($key): void {
                $t->foreignUlid('article_id')->constrained()->cascadeOnDelete();
                $t->foreignUlid($key)->constrained(
                    $key === 'builder_id' ? 'builders' : 'startups'
                )->cascadeOnDelete();
                $t->primary(['article_id', $key]);
            });
        }

        Schema::create('opportunities', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('title');
            $t->string('slug')->unique();
            $t->string('organisation');
            $t->string('type', 32);
            $t->string('location');
            $t->string('region')->nullable();
            $t->boolean('remote')->default(false);
            $t->text('description');
            $t->text('eligibility')->nullable();
            $t->string('apply_url');
            $t->date('deadline')->nullable();
            $t->string('edition', 24)->default('both');
            $t->string('status', 24)->default('draft')->index();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['status', 'deadline']);
        });

        Schema::create('editorial_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('title');
            $t->text('description')->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->string('timezone')->default('Africa/Lagos');
            $t->string('location');
            $t->string('type', 40);
            $t->string('organiser')->nullable();
            $t->string('registration_url')->nullable();
            $t->string('status', 24)->default('draft');
            $t->timestamps();
            $t->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        foreach (
            [
                'editorial_events',
                'opportunities',
                'article_startup',
                'article_builder',
                'startup_founders',
                'startups',
                'builders'
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }
};
