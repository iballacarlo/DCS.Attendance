<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountSecurityService
{
    public function revokeSessions(User $user, ?Request $keepCurrentRequest = null): void
    {
        // Atomic version changes also invalidate sessions using file, Redis or cookie storage.
        $user->increment('security_version', 1, ['remember_token' => Str::random(60)]);
        $user->refresh();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($keepCurrentRequest, fn ($query) => $query->where('id', '!=', $keepCurrentRequest->session()->getId()))
                ->delete();
        }

        if ($keepCurrentRequest) {
            $keepCurrentRequest->session()->regenerate();
            $keepCurrentRequest->session()->put('account_security_version', $user->security_version);
        }
    }
}
