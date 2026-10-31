import { useForm } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';

export default function VerifyEmail({ verificationEmail, verificationAction }) {
    const form = useForm({ password: '' });
    const submit = (event) => {
        event.preventDefault();
        form.post(verificationAction, { preserveScroll: true, onFinish: () => form.reset('password') });
    };

    return <AuthLayout title="Verify email">
        <section className="login-auth" aria-labelledby="verify-email-title">
            <h2 id="verify-email-title">Verify your email</h2>
            <p>Confirm that <strong>{verificationEmail}</strong> belongs to you by entering your current DCS Attendance System password.</p>
            <form onSubmit={submit}>
                <label>Current password
                    <input type="password" name="password" autoComplete="current-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} autoFocus />
                    {form.errors.password && <span className="field-error" role="alert">{form.errors.password}</span>}
                </label>
                <button type="submit" className="full" disabled={form.processing}>{form.processing ? 'Verifying…' : 'Verify email'}</button>
            </form>
        </section>
    </AuthLayout>;
}
