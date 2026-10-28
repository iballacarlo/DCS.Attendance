import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Eye, EyeOff, KeyRound } from 'lucide-react';
import { useState } from 'react';
import AuthLayout from '../../Layouts/AuthLayout';

export default function ResetPassword({ email, token }) {
    const { errors } = usePage().props;
    const [showPassword, setShowPassword] = useState(false);
    const form = useForm({ email, token, password: '', password_confirmation: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/reset-password');
    };

    return <AuthLayout title="Reset password">
        <section className="login-auth" aria-labelledby="reset-password-title">
            <span className="login-form-index">ACCOUNT RECOVERY</span>
            <h2 id="reset-password-title">Create new password</h2>
            <p>Use 12?72 characters and keep it private.</p>
            {(errors?.email || errors?.password) && <div className="alert error">{errors.email || errors.password}</div>}
            <form onSubmit={submit}>
                <label>Email address<input type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} required autoComplete="email" /></label>
                <label>New password
                    <span className="password-wrap">
                        <input type={showPassword ? 'text' : 'password'} value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} required minLength={12} maxLength={72} autoComplete="new-password" />
                        <button className="password-toggle" type="button" aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword} onClick={() => setShowPassword(!showPassword)}>{showPassword ? <EyeOff size={19} /> : <Eye size={19} />}</button>
                    </span>
                </label>
                <label>Confirm new password<input type={showPassword ? 'text' : 'password'} value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} required minLength={12} maxLength={72} autoComplete="new-password" /></label>
                <button className="full" type="submit" disabled={form.processing}><KeyRound size={17} />{form.processing ? 'Resetting...' : 'Reset password'}</button>
                <Link className="auth-back-link" href="/login"><ArrowLeft size={16} />Back to sign in</Link>
            </form>
        </section>
    </AuthLayout>;
}
