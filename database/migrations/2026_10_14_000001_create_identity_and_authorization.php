<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
            $t->unique(['name', 'guard_name']);
        });
        Schema::create('roles', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
            $t->unique(['name', 'guard_name']);
        });
        foreach (['model_has_permissions' => 'permission_id', 'model_has_roles' => 'role_id'] as $table => $key) {
            Schema::create($table, function (Blueprint $t) use ($key): void {
                $t->unsignedBigInteger($key);
                $t->string('model_type');
                $t->ulid('model_id');
                $t->index(['model_id', 'model_type']);
                $t->foreign($key)->references('id')->on($key === 'role_id' ? 'roles' : 'permissions')->cascadeOnDelete();
                $t->primary([$key, 'model_id', 'model_type']);
            });
        }
        Schema::create('role_has_permissions', function (Blueprint $t): void {
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['permission_id', 'role_id']);
        });
        Schema::create('profiles', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            foreach (['country', 'city', 'profession', 'organisation', 'industry', 'website'] as $key) {
                $t->string($key)->nullable();
            }$t->text('biography')->nullable();
            $t->json('social_links')->nullable();
            $t->json('privacy')->nullable();
            $t->json('interests')->nullable();
            $t->timestamps();
        });
        Schema::create('user_mfa_methods', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('secret');
            $t->json('recovery_code_hashes')->nullable();
            $t->unsignedBigInteger('last_used_timestep')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event', 120)->index();
            $t->string('subject_type', 80)->nullable();
            $t->string('subject_id', 80)->nullable();
            $t->json('context')->nullable();
            $t->string('request_id', 80)->nullable();
            $t->string('ip_hash', 64)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'user_mfa_methods', 'profiles', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'roles', 'permissions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
