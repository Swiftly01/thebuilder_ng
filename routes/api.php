<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AdministrationController;
use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\EditorialController;
use App\Http\Controllers\Api\V1\IdentityController;
use App\Http\Controllers\Api\V1\IntakeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\OpportunityController;
use App\Http\Controllers\Api\V1\PublicController;
use App\Http\Controllers\Api\V1\ReaderController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\YoutubeController;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\SignedPublicRead;
use App\Http\Middleware\VerifyBot;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware([SignedPublicRead::class, 'throttle:public'])->group(function (): void {
        Route::get('home', [PublicController::class, 'home']);
        Route::get('articles', [PublicController::class, 'articles']);
        Route::get('articles/{slug}', [PublicController::class, 'article']);
        foreach (['builders' => 'builder', 'startups' => 'startup', 'opportunities' => 'opportunity', 'videos' => 'video'] as $list => $detail) {
            Route::get($list, [PublicController::class, $list]);
            Route::get($list.'/{'.($detail === 'video' ? 'id' : 'slug').'}', [PublicController::class, $detail]);
        }
        Route::get('events', [PublicController::class, 'events']);
        Route::get('categories', [PublicController::class, 'categories']);
        Route::get('categories/{slug}', [PublicController::class, 'category']);
        Route::get('tags', [PublicController::class, 'tags']);
        Route::get('search', [PublicController::class, 'search']);
        Route::get('search/suggestions', [PublicController::class, 'suggest']);
        Route::get('settings/public', [PublicController::class, 'settings']);
        Route::get('sitemap.xml', [PublicController::class, 'sitemap']);
        Route::get('ads/{placement}', [MetricsController::class, 'ad']);
    });
    Route::prefix('auth')->middleware('throttle:auth')->group(function (): void {
        Route::post('register', [IdentityController::class, 'register'])->middleware(VerifyBot::class);
        Route::post('login', [IdentityController::class, 'login']);
        Route::post('forgot-password', [IdentityController::class, 'forgotPassword']);
        Route::post('reset-password', [IdentityController::class, 'resetPassword']);
        Route::post('email/resend', [IdentityController::class, 'resend']);
        Route::get('email/verify/{id}/{hash}', [IdentityController::class, 'verify'])->middleware('signed')->name('verification.verify');
    });
    Route::post('auth/logout', [IdentityController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('nominations', [IntakeController::class, 'nominate'])->middleware(['throttle:intake', Idempotency::class, VerifyBot::class]);
    Route::post('newsletter/subscriptions', [IntakeController::class, 'subscribe'])->middleware(['throttle:intake', Idempotency::class, VerifyBot::class]);
    Route::post('newsletter/confirm', [IntakeController::class, 'confirm'])->middleware('throttle:auth');
    Route::post('newsletter/unsubscribe', [IntakeController::class, 'unsubscribe'])->middleware('throttle:auth');
    Route::post('track/events', [MetricsController::class, 'track'])->middleware('throttle:public');
    Route::post('ads/{campaign}/events', [MetricsController::class, 'adEvent'])->middleware('throttle:public');
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::get('me', [IdentityController::class, 'me']);
        Route::middleware(['verified', 'throttle:mfa'])->prefix('auth/mfa')->group(function (): void {
            Route::post('setup', [MfaController::class, 'setup']);
            Route::post('confirm', [MfaController::class, 'confirm']);
            Route::post('challenge', [MfaController::class, 'challenge']);
        });
        Route::middleware('verified')->group(function (): void {
            Route::get('me/profile', [IdentityController::class, 'profile']);
            Route::patch('me/profile', [IdentityController::class, 'updateProfile']);
            Route::put('me/onboarding', [IdentityController::class, 'onboarding']);
            Route::put('me/interests', [IdentityController::class, 'interests']);
            Route::put('me/password', [IdentityController::class, 'changePassword']);
            Route::delete('me', [IdentityController::class, 'deleteAccount']);
            Route::get('me/sessions', [IdentityController::class, 'sessions']);
            Route::delete('me/sessions/{id}', [IdentityController::class, 'revokeSession']);
            Route::get('me/feed', [ReaderController::class, 'feed']);
            Route::get('me/bookmarks', [ReaderController::class, 'bookmarks']);
            Route::get('me/bookmarks/{article}', [ReaderController::class, 'bookmark']);
            Route::put('me/bookmarks/{article}', [ReaderController::class, 'save']);
            Route::delete('me/bookmarks/{article}', [ReaderController::class, 'remove']);
            Route::get('me/recent', [ReaderController::class, 'recent']);
            Route::put('me/recent/{article}', [ReaderController::class, 'view']);
            Route::post('me/submissions', [IntakeController::class, 'submit'])->middleware(['throttle:intake', Idempotency::class]);
            Route::prefix('console')->middleware('mfa')->group(function (): void {
                Route::get('dashboard', [AdministrationController::class, 'dashboard'])->middleware('permission:content.read');
                Route::get('content', [EditorialController::class, 'index'])->middleware('permission:content.read');
                Route::post('content-preview', [EditorialController::class, 'preview'])->middleware('permission:content.create');
                Route::post('content-import', [EditorialController::class, 'import'])->middleware('permission:content.create');
                Route::post('content', [EditorialController::class, 'store'])->middleware('permission:content.create');
                Route::get('content/{article}', [EditorialController::class, 'show'])->middleware('permission:content.read');
                Route::patch('content/{article}', [EditorialController::class, 'update'])->middleware('permission:content.edit');
                Route::post('content/{article}/transition', [EditorialController::class, 'transition'])->middleware('permission:content.edit');
                Route::get('content/{article}/versions', [EditorialController::class, 'versions'])->middleware('permission:content.read');
                Route::post('content/{article}/versions/{revision}/restore', [EditorialController::class, 'restore'])->middleware('permission:content.edit');
                Route::delete('content/{article}', [EditorialController::class, 'destroy'])->middleware('permission:content.publish');
                Route::get('{kind}', [DirectoryController::class, 'index'])->whereIn('kind', ['builders', 'startups'])->middleware('permission:profiles.manage');
                Route::post('{kind}', [DirectoryController::class, 'store'])->whereIn('kind', ['builders', 'startups'])->middleware('permission:profiles.manage');
                Route::get('{kind}/{id}', [DirectoryController::class, 'show'])->whereIn('kind', ['builders', 'startups'])->middleware('permission:profiles.manage');
                Route::put('{kind}/{id}', [DirectoryController::class, 'update'])->whereIn('kind', ['builders', 'startups'])->middleware('permission:profiles.manage');
                Route::delete('{kind}/{id}', [DirectoryController::class, 'destroy'])->whereIn('kind', ['builders', 'startups'])->middleware('permission:profiles.manage');
                Route::get('media', [MediaController::class, 'index'])->middleware('permission:media.manage');
                Route::get('media/{media}', [MediaController::class, 'show'])->middleware('permission:media.manage');
                Route::post('media', [MediaController::class, 'store'])->middleware('permission:media.manage');
                Route::patch('media/{media}', [MediaController::class, 'update'])->middleware('permission:media.manage');
                Route::delete('media/{media}', [MediaController::class, 'destroy'])->middleware('permission:media.manage');
                Route::get('nominations', [ReviewController::class, 'nominations'])->middleware('permission:nominations.manage');
                Route::post('nominations/{nomination}/draft', [ReviewController::class, 'draftNomination'])->middleware(['permission:nominations.manage', 'permission:content.create']);
                Route::patch('nominations/{nomination}', [ReviewController::class, 'moveNomination'])->middleware('permission:nominations.manage');
                Route::get('submissions', [ReviewController::class, 'submissions'])->middleware('permission:submissions.manage');
                Route::patch('submissions/{submission}', [ReviewController::class, 'moveSubmission'])->middleware('permission:submissions.manage');
                Route::get('homepage', [AdministrationController::class, 'homepage'])->middleware('permission:homepage.manage');
                Route::get('opportunities', [OpportunityController::class, 'index'])->middleware('permission:profiles.manage');
                Route::post('opportunities', [OpportunityController::class, 'save'])->middleware('permission:profiles.manage');
                Route::put('opportunities/{opportunity}', [OpportunityController::class, 'save'])->middleware('permission:profiles.manage');
                Route::delete('opportunities/{opportunity}', [OpportunityController::class, 'destroy'])->middleware('permission:profiles.manage');
                Route::put('homepage', [AdministrationController::class, 'saveHomepage'])->middleware('permission:homepage.manage');
                Route::get('taxonomy', [AdministrationController::class, 'taxonomy'])->middleware('permission:taxonomy.manage');
                Route::put('taxonomy/{kind}', [AdministrationController::class, 'saveTaxonomy'])->whereIn('kind', ['categories', 'tags'])->middleware('permission:taxonomy.manage');
                Route::get('users', [AdministrationController::class, 'users'])->middleware('permission:users.manage');
                Route::patch('users/{user}', [AdministrationController::class, 'updateUser'])->middleware('permission:users.manage');
                Route::get('roles', [AdministrationController::class, 'roles'])->middleware('permission:users.manage');
                Route::get('settings', [AdministrationController::class, 'settings'])->middleware('permission:settings.manage');
                Route::put('settings', [AdministrationController::class, 'saveSettings'])->middleware('permission:settings.manage');
                Route::get('audit', [AdministrationController::class, 'audit'])->middleware('permission:audit.read');
                Route::get('analytics', [MetricsController::class, 'analytics'])->middleware('permission:analytics.read');
                Route::get('ads', [MetricsController::class, 'campaigns'])->middleware('permission:ads.manage');
                Route::put('ads', [MetricsController::class, 'saveCampaign'])->middleware('permission:ads.manage');
                Route::get('youtube/channels', [YoutubeController::class, 'channels'])->middleware('permission:youtube.manage');
                Route::put('youtube/channels', [YoutubeController::class, 'saveChannel'])->middleware('permission:youtube.manage');
                Route::post('youtube/channels/{channel}/sync', [YoutubeController::class, 'sync'])->middleware(['permission:youtube.manage', 'throttle:auth']);
                Route::get('youtube/history', [YoutubeController::class, 'history'])->middleware('permission:youtube.manage');
                Route::get('videos', [YoutubeController::class, 'videos'])->middleware('permission:youtube.manage');
                Route::patch('videos/{video}', [YoutubeController::class, 'updateVideo'])->middleware('permission:youtube.manage');
            });
        });
    });
    Route::fallback(fn () => abort(404));
});
