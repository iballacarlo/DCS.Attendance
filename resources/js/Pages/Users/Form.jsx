import { useId } from 'react';
import FacultyCombobox from '../../components/FacultyCombobox';
import { Link, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import SettingsTabs from '../../components/SettingsTabs';

export default function UsersForm(props) {
    return <AccountForm key={props.user.id || 'new-account'} {...props} />;
}

function AccountForm({ user, employees }) {
    const editing = Boolean(user.id);
    const id = useId();
    const form = useForm({
        name: editing ? user.name || '' : '',
        email: editing ? user.email || '' : '',
        password: '',
        role: editing && ['admin', 'faculty', 'guard'].includes(user.role) ? user.role : '',
        employee_id: editing ? user.employee_id || '' : '',
    });
    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, employee_id: data.role === 'faculty' ? data.employee_id : null }));
        editing ? form.put(`/settings/accounts/${user.id}`) : form.post('/settings/accounts');
    };
    const error = (field) => form.errors[field] && <small className="field-error" role="alert">{form.errors[field]}</small>;
    return <AppLayout title={editing ? 'Edit Account' : 'Add Account'}>
        <section className="account-form-page">
        <SettingsTabs />
        <form className="panel form-grid" name="manage-account" autoComplete="off" onSubmit={submit}>
            <label htmlFor={`${id}-name`}>Name<input id={`${id}-name`} name="managed_account_name" autoComplete="off" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />{error('name')}</label>
            <label htmlFor={`${id}-email`}>Email<input id={`${id}-email`} name="managed_account_email" type="email" autoComplete="off" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required />{error('email')}</label>
            <label htmlFor={`${id}-password`}>{editing ? 'New Password' : 'Password'}<input id={`${id}-password`} name="managed_account_new_password" type="password" autoComplete="new-password" minLength={12} maxLength={72} placeholder={editing ? 'Leave blank to keep current password' : 'Minimum 12 characters'} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required={!editing} />{error('password')}</label>
            <label>Role<select value={form.data.role} required onChange={(e) => form.setData({ ...form.data, role: e.target.value, employee_id: e.target.value === 'faculty' ? form.data.employee_id : '' })}><option value="" disabled>Select role</option><option value="admin">Admin</option><option value="faculty">Faculty</option><option value="guard">Guard</option></select>{error('role')}</label>
            {form.data.role === 'faculty' && <div><FacultyCombobox label="Linked Faculty" employees={employees} selectedId={form.data.employee_id} onSelect={(value) => form.setData('employee_id', value)} />{error('employee_id')}</div>}
            <div className="form-actions"><Link className="ui-control ui-action ui-secondary" href="/settings/accounts">Cancel</Link><button className="ui-control ui-action ui-primary" type="submit" disabled={form.processing}>{form.processing ? 'Saving...' : 'Save Account'}</button></div>
        </form>
        </section>
    </AppLayout>;
}
