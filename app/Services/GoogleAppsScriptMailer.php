<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

class GoogleAppsScriptMailer
{
    public function sendVerification(User $user, ?string $temporaryPassword = null): bool
    {
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(48),
            ['user' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );
        $textMessage = $this->textMessage($user, $verificationUrl, $temporaryPassword);
        $htmlMessage = $this->htmlMessage($user, $verificationUrl, $temporaryPassword);

        return $this->deliver(
            $user,
            $temporaryPassword
                ? 'Your CvSU - Imus Payroll account is ready'
                : 'Verify your DCS Attendance & DTR email address',
            $textMessage,
            $htmlMessage
        );
    }

    public function sendPasswordReset(User $user, string $token): bool
    {
        $resetUrl = route('password.reset', [
            'token' => $token,
            'email' => $user->email,
        ]);
        $safeName = e($user->name);
        $safeResetUrl = e($resetUrl);
        $safeLogoUrl = e(asset('images/cvsu-logo.png'));
        $textMessage = "Hello {$user->name},\n\nWe received a request to reset your DCS Attendance & DTR password. Use this link within 60 minutes:\n{$resetUrl}\n\nIf you did not request a password reset, you can ignore this email. Your password will remain unchanged.";
        $htmlMessage = <<<HTML
            <!doctype html>
            <html lang="en">
            <head><meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="utf-8"><title>Reset your DCS Attendance & DTR password</title></head>
            <body style="margin:0;padding:0;background:#eef3f1;font-family:Arial,Helvetica,sans-serif;color:#20312b;">
                <div style="display:none;max-height:0;overflow:hidden;opacity:0;">Reset your DCS Attendance & DTR password.</div>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#eef3f1;">
                    <tr><td align="center" style="padding:28px 12px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;border-collapse:collapse;background:#ffffff;border:1px solid #d7e1dc;">
                            <tr><td style="padding:22px 32px;background:#174d38;border-top:5px solid #d6a400;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>
                                    <td width="64" style="vertical-align:middle;"><img src="{$safeLogoUrl}" width="52" height="52" alt="CvSU" style="display:block;border:0;width:52px;height:52px;"></td>
                                    <td style="vertical-align:middle;color:#ffffff;"><div style="font-size:12px;line-height:1.4;text-transform:uppercase;color:#c9ddd4;">Cavite State University</div><div style="font-size:20px;line-height:1.3;font-weight:700;">Imus Campus</div><div style="font-size:12px;line-height:1.4;color:#f2c94c;">DCS Attendance &amp; DTR</div></td>
                                </tr></table>
                            </td></tr>
                            <tr><td style="padding:30px 32px 20px;"><h1 style="margin:0 0 14px;font-size:24px;line-height:1.3;color:#174d38;">Reset your password</h1><p style="margin:0;font-size:15px;line-height:1.7;color:#455852;">Hello <strong>{$safeName}</strong>, we received a request to create a new password for your attendance account.</p></td></tr>
                            <tr><td align="center" style="padding:4px 32px 26px;"><a href="{$safeResetUrl}" style="display:inline-block;padding:13px 24px;background:#1f7a4d;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;border-radius:4px;">Reset password</a><p style="margin:13px 0 0;font-size:13px;color:#65736e;">This secure link expires in 60 minutes.</p></td></tr>
                            <tr><td style="padding:0 32px 28px;"><div style="padding:16px 18px;background:#fff8df;border:1px solid #ead38a;font-size:13px;line-height:1.6;color:#604b0b;"><strong>Did not request this?</strong> Ignore this email. Your current password will remain unchanged.</div></td></tr>
                            <tr><td style="padding:20px 32px;background:#f6f8f7;border-top:1px solid #dbe3df;font-size:12px;line-height:1.6;color:#65736e;">For your security, never share this link with anyone.</td></tr>
                        </table>
                    </td></tr>
                </table>
            </body>
            </html>
        HTML;

        return $this->deliver($user, 'Reset your DCS Attendance & DTR password', $textMessage, $htmlMessage);
    }

    private function deliver(User $user, string $subject, string $textMessage, string $htmlMessage): bool
    {
        $endpoint = config('services.google_mail.webhook_url');
        $secret = config('services.google_mail.webhook_secret');

        if (! $endpoint || ! $secret) {
            Log::warning('Google Apps Script email delivery is not configured.', [
                'user_id' => $user->id,
                'webhook_url_configured' => (bool) $endpoint,
                'webhook_secret_configured' => (bool) $secret,
            ]);

            return false;
        }

        try {
            $response = Http::asJson()
                ->withOptions(['allow_redirects' => true])
                ->timeout(15)
                ->post($endpoint, [
                    'secret' => $secret,
                    'to' => $user->email,
                    'recipient' => $user->email,
                    'subject' => $subject,
                    'message' => $textMessage,
                    'text' => $textMessage,
                    'html' => $htmlMessage,
                ]);

            $delivered = $response->successful() && $response->json('ok') === true;

            if (! $delivered) {
                Log::warning('Google Apps Script rejected the email delivery request.', [
                    'user_id' => $user->id,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            }

            return $delivered;
        } catch (Throwable $exception) {
            Log::warning('Google Apps Script email delivery failed.', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function textMessage(User $user, string $verificationUrl, ?string $temporaryPassword): string
    {
        $credentials = $temporaryPassword
            ? "\n\nTEMPORARY ACCOUNT CREDENTIALS\nEmail: {$user->email}\nTemporary password: {$temporaryPassword}\n\nYou will be required to create a new password after signing in."
            : '';

        return "Hello {$user->name},\n\nYour DCS Attendance & DTR account is ready.{$credentials}\n\nVerify your email address using this link (valid for 48 hours):\n{$verificationUrl}\n\nFor your security, do not share your password or verification link. If you did not expect this account, contact the system administrator.";
    }

    private function htmlMessage(User $user, string $verificationUrl, ?string $temporaryPassword): string
    {
        $safeName = e($user->name);
        $safeEmail = e($user->email);
        $safeVerificationUrl = e($verificationUrl);
        $safeLoginUrl = e(route('login'));
        $safeLogoUrl = e(asset('images/cvsu-logo.png'));
        $credentials = '';

        if ($temporaryPassword) {
            $safePassword = e($temporaryPassword);
            $credentials = <<<HTML
                <tr>
                    <td style="padding:0 32px 24px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f3f7f5;border-left:4px solid #d6a400;">
                            <tr><td style="padding:18px 20px 10px;font-size:13px;font-weight:700;color:#174d38;text-transform:uppercase;">Temporary account credentials</td></tr>
                            <tr><td style="padding:0 20px 6px;font-size:14px;color:#455852;">Email address</td></tr>
                            <tr><td style="padding:0 20px 14px;font-size:16px;font-weight:700;color:#172b24;word-break:break-all;">{$safeEmail}</td></tr>
                            <tr><td style="padding:0 20px 6px;font-size:14px;color:#455852;">Temporary password</td></tr>
                            <tr><td style="padding:0 20px 18px;"><span style="display:inline-block;padding:9px 12px;background:#ffffff;border:1px solid #cdd9d4;font-family:Consolas,Monaco,monospace;font-size:17px;font-weight:700;color:#172b24;letter-spacing:1px;">{$safePassword}</span></td></tr>
                        </table>
                        <p style="margin:12px 0 0;font-size:13px;line-height:1.6;color:#65736e;">You will be asked to replace this temporary password after signing in. Keep these credentials private.</p>
                    </td>
                </tr>
            HTML;
        }

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head><meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="utf-8"><title>DCS Attendance & DTR</title></head>
            <body style="margin:0;padding:0;background:#eef3f1;font-family:Arial,Helvetica,sans-serif;color:#20312b;">
                <div style="display:none;max-height:0;overflow:hidden;opacity:0;">Verify your DCS Attendance & DTR account.</div>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#eef3f1;">
                    <tr><td align="center" style="padding:28px 12px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;border-collapse:collapse;background:#ffffff;border:1px solid #d7e1dc;">
                            <tr>
                                <td style="padding:22px 32px;background:#174d38;border-top:5px solid #d6a400;">
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>
                                        <td width="64" style="vertical-align:middle;"><img src="{$safeLogoUrl}" width="52" height="52" alt="CvSU" style="display:block;border:0;width:52px;height:52px;"></td>
                                        <td style="vertical-align:middle;color:#ffffff;"><div style="font-size:12px;line-height:1.4;text-transform:uppercase;color:#c9ddd4;">Cavite State University</div><div style="font-size:20px;line-height:1.3;font-weight:700;">Imus Campus</div><div style="font-size:12px;line-height:1.4;color:#f2c94c;">DCS Attendance &amp; DTR</div></td>
                                    </tr></table>
                                </td>
                            </tr>
                            <tr><td style="padding:30px 32px 18px;"><h1 style="margin:0 0 14px;font-size:24px;line-height:1.3;color:#174d38;">Welcome to DCS Attendance & DTR</h1><p style="margin:0;font-size:15px;line-height:1.7;color:#455852;">Hello <strong>{$safeName}</strong>, your faculty attendance account is ready. Verify your institutional email address to activate and protect your account.</p></td></tr>
                            {$credentials}
                            <tr><td align="center" style="padding:4px 32px 24px;"><a href="{$safeVerificationUrl}" style="display:inline-block;padding:13px 24px;background:#1f7a4d;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;border-radius:4px;">Verify email address</a><p style="margin:13px 0 0;font-size:13px;color:#65736e;">This secure verification link expires in 48 hours.</p></td></tr>
                            <tr><td style="padding:0 32px 26px;"><div style="padding:16px 18px;background:#fff8df;border:1px solid #ead38a;font-size:13px;line-height:1.6;color:#604b0b;"><strong>Security reminder:</strong> CvSU system administrators will never ask for your password or verification link.</div></td></tr>
                            <tr><td style="padding:20px 32px;background:#f6f8f7;border-top:1px solid #dbe3df;font-size:12px;line-height:1.6;color:#65736e;">After verification, sign in at <a href="{$safeLoginUrl}" style="color:#1f7a4d;">DCS Attendance & DTR</a>.<br>If you did not expect this account, contact the system administrator.</td></tr>
                        </table>
                    </td></tr>
                </table>
            </body>
            </html>
        HTML;
    }
}
