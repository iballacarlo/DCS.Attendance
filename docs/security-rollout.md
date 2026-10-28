# Account security and deployment

Accounts created by Admin and passwords reset by Admin are temporary. Admin and Faculty land on Dashboard. Guards go directly to the entrance kiosk; visiting `/` as Guard also redirects to the kiosk. Until the owner changes the password, the server blocks attendance and other features. Guards can change their own password at `/account/password`; they cannot access account administration or faculty profiles.

New passwords require at least 12 characters and at most 72 UTF-8 bytes (the bcrypt limit). Existing passwords remain usable for login. The current password is required for an authenticated password change. Password recovery continues to require its single-use email token.

Password changes, password recovery, and Admin changes to email, role or linked faculty revoke existing sessions and remembered logins. A self-service password change preserves only the current session and regenerates its ID. A full backup restore revokes all sessions and reset tokens, including when restoring a legacy backup without the new security-version column. Legacy Payroll Staff remains disabled.

## Deploy the application

Run database migrations before directing traffic to this release. The additive `security_version` migration defaults existing accounts to zero and does not delete historical data. The migration was applied locally during implementation; hosted environments still need their own migration.

Set these on the production host, then rebuild the Laravel configuration cache:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-attendance-host
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
SESSION_LIFETIME=120
```

```sh
php artisan migrate --force
php artisan config:cache
php artisan security:check
```

`security:check` prints setting names and pass/fail only, never credential values. It is a configuration check, not a penetration test or confirmation that the reverse proxy, certificates and network are correctly configured. It intentionally fails in the local HTTP development environment. Production defaults now enable session encryption and secure cookies unless explicitly overridden. Enabling encryption may require existing users to sign in again.

## Hardware rollout

The three maintained/retained attendance sketches use `setCACert(HARDWARE_ROOT_CA)` for attendance and enrollment HTTPS requests, without a `setInsecure()` fallback. They target `dcsattendance.up.railway.app`; the included `railway_root_ca.h` provides its public ISRG Root X2 trust anchor, verified on October 29, 2026. A `HARDWARE_ROOT_CA` override in the local `device_config.h` takes precedence and must match the actual host. Re-verify the trust anchor if the domain or issuer changes. Keep NTP working so certificate validity dates can be checked. Test a valid certificate, a wrong CA, and queued attendance retries on a device before deployment.

Real `device_config.h` files are ignored for every sketch. The previously tracked legacy configuration was replaced with placeholders. **Removing secrets from the current file does not remove them from Git history.** Rotate the previously committed Wi-Fi credentials and hardware API key in a coordinated maintenance window, update the server/device configuration, and flash/test the devices. No live keys were rotated and no devices were flashed by this change. Existing ignored device configurations were not edited. The maintained TFT firmware has been compiled with the installed Arduino CLI and ESP32 3.3.12 core; physical device validation is still required.

## Remaining work

Admin MFA, security audit events, password reauthentication for backup/export and other sensitive actions, encrypted scheduled backups with restore drills, and dependency vulnerability scanning remain separate improvements. This release does not claim those controls are implemented.
