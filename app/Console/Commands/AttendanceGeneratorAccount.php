<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountSecurityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AttendanceGeneratorAccount extends Command
{
    protected $signature = 'attendance:generator-account {email?} {--reset : Replace an existing generator password and revoke its sessions}';

    protected $description = 'Provision a restricted attendance generator account';

    public function handle(AccountSecurityService $security): int
    {
        $email = strtolower(trim((string) ($this->argument('email') ?: $this->ask('Account email'))));
        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $password = Str::password(24);
        $error = DB::transaction(function () use ($email, $password, $security) {
            $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            if ($user && ($user->role !== 'generator' || ! $this->option('reset'))) {
                return $user->role === 'generator' ? 'Account exists. Use --reset to replace its password.' : 'That email belongs to another account. No changes were made.';
            }
            $user ??= new User;
            $user->forceFill(['name' => 'Attendance Generator', 'email' => $email, 'password' => $password,
                'role' => 'generator', 'employee_id' => null, 'must_change_password' => true, 'email_verified_at' => now()])->save();
            $security->revokeSessions($user);
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return null;
        });
        if ($error) {
            $this->error($error);

            return self::FAILURE;
        }
        $this->info('Generator account is ready. Change the temporary password after signing in.');
        $this->line('Email: '.$email);
        $this->line('Temporary password: '.$password);
        $this->line(config('attendance_generator.enabled') ? 'Generator access is enabled.' : 'Generator access is disabled until ATTENDANCE_GENERATOR_ENABLED is enabled.');

        return self::SUCCESS;
    }
}
