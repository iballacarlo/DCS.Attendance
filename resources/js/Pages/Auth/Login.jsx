import { Link, useForm, usePage } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import AuthLayout from '../../Layouts/AuthLayout';

const remainingCooldown = (until) => Math.max(0, Number(until || 0) - Math.floor(Date.now() / 1000));

const formatCooldown = (seconds) => {
    const minutes = Math.floor(seconds / 60);
    return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
};

export default function Login() {
    const { errors, flash } = usePage().props;
    const [showPassword, setShowPassword] = useState(false);
    const [cooldownSeconds, setCooldownSeconds] = useState(() => remainingCooldown(flash?.loginCooldownUntil));
    const form = useForm({ email: '', password: '' });

    useEffect(() => {
        setCooldownSeconds(remainingCooldown(flash?.loginCooldownUntil));
    }, [flash?.loginCooldownUntil]);

    useEffect(() => {
        if (cooldownSeconds <= 0) return undefined;
        const timer = window.setInterval(() => {
            setCooldownSeconds(remainingCooldown(flash?.loginCooldownUntil));
        }, 1000);
        return () => window.clearInterval(timer);
    }, [cooldownSeconds, flash?.loginCooldownUntil]);

    const submit = (event) => {
        event.preventDefault();
        if (cooldownSeconds > 0) return;
        form.post('/login');
    };

    const coolingDown = cooldownSeconds > 0;

    return <AuthLayout title="Sign in">
        <section className="login-auth" aria-labelledby="login-title">
            <span className="login-form-index">SECURE ACCESS</span>
            <h2 id="login-title">Sign in</h2>
            <p>Use your assigned system account.</p>
            {flash?.success && <div className="alert success">{flash.success}</div>}
            {errors?.email && <div className="alert error" role="alert">{errors.email}{coolingDown && <strong className="login-cooldown">Cooldown remaining: {formatCooldown(cooldownSeconds)}</strong>}</div>}
            <form onSubmit={submit}>
                <label>Email address<input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required autoFocus autoComplete="email" disabled={coolingDown} /></label>
                <label>Password
                    <span className="password-wrap">
                        <input type={showPassword ? 'text' : 'password'} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required autoComplete="current-password" disabled={coolingDown} />
                        <button className="password-toggle" type="button" disabled={coolingDown} aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword} title={showPassword ? 'Hide password' : 'Show password'} onClick={() => setShowPassword(!showPassword)}>{showPassword ? <EyeOff size={19} aria-hidden="true" /> : <Eye size={19} aria-hidden="true" />}</button>
                    </span>
                </label>
                <Link className="forgot-password-link" href="/forgot-password">Forgot password?</Link>
                <button className="full" type="submit" disabled={form.processing || coolingDown}>{form.processing ? 'Signing in...' : coolingDown ? `Try again in ${formatCooldown(cooldownSeconds)}` : 'Sign in to system'}</button>
            </form>
        </section>
    </AuthLayout>;
}
