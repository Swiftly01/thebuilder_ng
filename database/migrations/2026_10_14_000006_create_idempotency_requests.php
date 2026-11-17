<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_requests', function (Blueprint $t): void {
            $t->id();
            $t->string('scope', 64);
            $t->string('key', 120);
            $t->string('fingerprint', 64);
            $t->unsignedSmallInteger('status')->nullable();
            $t->json('response')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_requests');
    }
};
