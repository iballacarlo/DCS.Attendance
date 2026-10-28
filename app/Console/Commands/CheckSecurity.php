<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckSecurity extends Command
{
    protected $signature = 'security:check';

    protected $description = 'Check production security settings without printing credentials';

    public function handle(): int
    {
        $checks = [
            'Production environment' => app()->environment('production'),
            'Debug disabled' => ! config('app.debug'),
            'HTTPS application URL' => str_starts_with((string) config('app.url'), 'https://'),
            'Secure session cookie' => (bool) config('session.secure'),
            'HTTP-only session cookie' => (bool) config('session.http_only'),
            'SameSite session cookie' => in_array(config('session.same_site'), ['lax', 'strict'], true),
            'Encrypted session storage' => (bool) config('session.encrypt'),
            'Persistent session storage' => in_array(config('session.driver'), ['database', 'redis', 'file'], true),
            'Session idle limit at most 120 minutes' => config('session.lifetime') > 0 && config('session.lifetime') <= 120,
            'Hardware API key at least 32 characters' => strlen((string) config('services.hardware.api_key')) >= 32,
        ];

        foreach ($checks as $label => $passed) {
            $this->line(($passed ? 'PASS' : 'FAIL').' '.$label);
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
