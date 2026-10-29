import { Link, useForm, usePage } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';

export default function ChangePassword() {
    const role = usePage().props.auth.user.role;
    const guard = role === 'guard';
    const generator = role === 'generator';
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    const submit = (event) => {
        event.preventDefault();
        form.put('/account/password', { onFinish: () => form.reset('current_password', 'password', 'password_confirmation') });
    };

    return <AuthLayout title="Change password">
        <section className="login-auth" aria-labelledby="change-password-title">
            <h2 id="change-password-title">Change password</h2>
            <p>Use 12–72 characters. You can use a memorable passphrase.</p>
            <form onSubmit={submit}>
                {[
                    ['current_password', 'Current password', 'current-password'],
                    ['password', 'New password', 'new-password'],
                    ['password_confirmation', 'Confirm new password', 'new-password'],
                ].map(([field, label, autoComplete]) => <label key={field}>{label}
                    <input type="password" name={field} autoComplete={autoComplete} required minLength={field === 'current_password' ? undefined : 12} maxLength={field === 'current_password' ? undefined : 72} value={form.data[field]} onChange={(event) => form.setData(field, event.target.value)} />
                    {form.errors[field] && <span className="field-error" role="alert">{form.errors[field]}</span>}
                </label>)}
                <button type="submit" className="full" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save password'}</button>
                <Link href={generator ? '/tools/attendance-generator' : guard ? '/entrance-kiosk' : '/'}>{generator ? 'Back to generator' : guard ? 'Back to kiosk' : 'Back to Dashboard'}</Link>
            </form>
        </section>
    </AuthLayout>;
}
