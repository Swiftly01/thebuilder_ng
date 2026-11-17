<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Administration\Audit;
use App\Models\Article;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PurgeDeletedAccounts extends Command
{
    protected $signature = 'builder:purge-accounts';

    protected $description = 'Purge account data after the 30-day deletion grace period; retain anonymous editorial attribution.';

    public function handle(Audit $audit): int
    {
        $count = 0;
        User::where('status', 'deactivated')->where('deletion_requested_at', '<=', now()->subDays(30))->chunkById(50, function ($users) use ($audit, &$count): void {
            foreach ($users as $candidate) {
                DB::transaction(function () use ($candidate, $audit, &$count): void {
                    $user = User::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    if ($user->status !== 'deactivated' || ! $user->deletion_requested_at || $user->deletion_requested_at->gt(now()->subDays(30)) || $user->hasRole('admin')) {
                        return;
                    }
                    $email = $user->email;
                    $audit->record('account.purged', null, $user);
                    foreach (['profiles', 'bookmarks', 'reading_history', 'story_submissions', 'sessions'] as $table) {
                        DB::table($table)->where('user_id', $user->id)->delete();
                    }
                    DB::table('password_reset_tokens')->where('email', $email)->delete();
                    $user->mfaMethod()->delete();
                    $user->syncRoles([]);
                    if (Article::withTrashed()->where('author_id', $user->id)->exists()) {
                        $user->forceFill(['name' => 'Former contributor', 'email' => Str::ulid().'@deleted.invalid', 'password' => Str::random(64), 'remember_token' => null, 'email_verified_at' => null, 'onboarding_completed_at' => null, 'deletion_requested_at' => null])->save();
                    } else {
                        $user->delete();
                    }
                    $count++;
                });
            }
        });
        $this->info('Purged '.$count.' accounts.');

        return self::SUCCESS;
    }
}
