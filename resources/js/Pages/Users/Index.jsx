import { Link, router, usePage } from '@inertiajs/react';
import { Plus, Search, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Pagination from '../../components/Pagination';
import SettingsTabs from '../../components/SettingsTabs';
import useDebouncedFilters from '../../hooks/useDebouncedFilters';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import { fullName, roleLabel } from '../../lib/format';

export default function UsersIndex({ users, filters }) {
    const { auth } = usePage().props;
    const { ask, dialog } = useConfirmDialog();
    const [listFilters, setListFilters] = useState({ search: filters.search || '', role: filters.role || '' });
    useDebouncedFilters('/settings/accounts', listFilters);

    const remove = async (user) => {
        const name = user.employee ? fullName(user.employee) : user.name;
        if (await ask({ title: 'Delete account?', message: `Permanently delete the account of ${name}? This action cannot be undone.`, confirmLabel: 'Delete Account', tone: 'danger' })) {
            router.delete(`/settings/accounts/${user.id}`);
        }
    };

    return <AppLayout title="Settings" subtitle="Manage system accounts, backups, and data recovery.">
        <SettingsTabs />
        <div className="panel">
            <div className="panel-heading"><h2>Accounts</h2><Link className="button ui-control ui-action ui-primary" href="/settings/accounts/create"><Plus size={16} />Add Account</Link></div>
            <div className="list-filters account-filters">
                <label className="filter-search">Search Accounts<span className="search-control"><Search size={16} /><input placeholder="Name, email, or employee number" value={listFilters.search} onChange={(event) => setListFilters((current) => ({ ...current, search: event.target.value }))} /></span></label>
                <label>Role<select value={listFilters.role} onChange={(event) => setListFilters((current) => ({ ...current, role: event.target.value }))}><option value="">All roles</option><option value="admin">Administrator</option><option value="payroll_staff">HR</option><option value="faculty">Faculty</option></select></label>
                {(listFilters.search || listFilters.role) && <button className="clear-filters ui-control ui-action ui-secondary" type="button" onClick={() => setListFilters({ search: '', role: '' })}><X size={15} />Clear</button>}
            </div>
            <div className="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Linked Faculty</th><th className="table-actions-heading">Actions</th></tr></thead><tbody>
                {users.data.length ? users.data.map((user) => <tr key={user.id}><td>{user.employee ? fullName(user.employee) : user.name}</td><td>{user.email}</td><td><span className="badge">{roleLabel(user.role)}</span></td><td>{fullName(user.employee)}</td><td className="actions"><div className="table-actions"><Link className="ui-control ui-action ui-secondary" href={`/settings/accounts/${user.id}/edit`}>Edit</Link>{auth.user.id !== user.id && <button className="link-danger ui-control ui-action ui-danger" type="button" onClick={() => remove(user)}>Delete</button>}</div></td></tr>) : <tr><td colSpan="5">No accounts match the selected filters.</td></tr>}
            </tbody></table></div>
            <Pagination links={users.links} />
        </div>
        {dialog}
    </AppLayout>;
}
