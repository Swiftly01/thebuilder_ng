<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Article;
use App\Policies\ArticlePolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {  
         Schema::defaultStringLength(191);
        RateLimiter::for('public', fn ($r) => $r->attributes->get('builder_ssr') === true
            ? Limit::perMinute((int) config('builder.ssr_requests_per_minute'))->by('signed-public-ssr')
            : Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('auth', fn ($r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('intake', fn ($r) => Limit::perHour(5)->by($r->ip()));
        RateLimiter::for('mfa', fn ($r) => Limit::perMinute(6)->by($r->user()->id ?? $r->ip()));
        VerifyEmail::createUrlUsing(fn ($user) => URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]));
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('builder.frontend_url').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));
        Gate::policy(Article::class, ArticlePolicy::class);
    }
}
