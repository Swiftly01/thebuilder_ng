<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Administration\Audit;
use App\Models\User;
use Illuminate\Console\Command;

final class GrantAdministrator extends Command
{
    protected $signature = 'builder:grant-admin {email : Existing verified account email}';

    protected $description = 'Provision an administrator through an explicit operator confirmation; MFA remains mandatory.';

    public function handle(Audit $audit): int
    {
        $user = User::where('email', strtolower(trim((string) $this->argument('email'))))->where('status', 'active')->whereNotNull('email_verified_at')->first();
        if (! $user) {
            $this->error('An active, verified account is required.');

            return self::FAILURE;
        }
        if (! $this->confirm('Grant administrator access to this verified account?')) {
            return self::FAILURE;
        }
        $user->assignRole('admin');
        $audit->record('user.administrator.provisioned', null, $user);
        $this->info('Administrator role granted. Enroll or challenge MFA before opening the console.');

        return self::SUCCESS;
    }
}
