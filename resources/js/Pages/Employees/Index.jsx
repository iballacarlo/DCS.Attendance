import { Link, router, usePage } from '@inertiajs/react';
import { Archive, BadgeCheck, MailWarning, Plus, Search, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Pagination from '../../components/Pagination';
import useDebouncedFilters from '../../hooks/useDebouncedFilters';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import { fullName, money } from '../../lib/format';

const weeklyHours = (schedules = []) => {
    const minutes = schedules.reduce((total, schedule) => {
        const toMinutes = (time) => {
            const [hours, mins] = String(time).slice(0, 5).split(':').map(Number);
            return (hours * 60) + mins;
        };

        return total + Math.max(0, toMinutes(schedule.end_time) - toMinutes(schedule.start_time));
    }, 0);

    const hours = minutes / 60;
    return Number.isInteger(hours) ? String(hours) : hours.toFixed(1);
};

function SortHeader({ label, sort, activeSort, onSort }) {
    const isActive = activeSort === sort;
    return <th><button className={`table-sort${isActive ? ' is-active' : ''}`} type="button" onClick={() => onSort(sort)}>{label}</button></th>;
}

export default function EmployeeIndex({ employees, filters, ranks }) {
    const { ask, dialog } = useConfirmDialog();
    const ownEmployeeId = Number(usePage().props.auth.user.employee_id);
    const [selectedIds, setSelectedIds] = useState([]);
    const [archiving, setArchiving] = useState(false);
    const selectableIds = employees.data.filter((employee) => employee.id !== ownEmployeeId).map((employee) => employee.id);
    const selected = selectedIds.filter((id) => selectableIds.includes(id));
    const toggle = (id) => setSelectedIds((ids) => ids.includes(id) ? ids.filter((value) => value !== id) : [...ids, id]);
    const archive = async (ids) => {
        if (!ids.length || archiving) return;
        if (!await ask({ title: 'Archive faculty?', message: `Archive ${ids.length} faculty member(s)? They and their history will be hidden from normal pages, searches, and reports. Their account will be blocked. Schedules and records are preserved and can be restored from Archived Faculty.`, confirmLabel: 'Archive Faculty', tone: 'warning' })) return;
        setArchiving(true);
        router.post('/employees/archive', { employee_ids: ids }, { preserveScroll: true, onSuccess: () => setSelectedIds([]), onFinish: () => setArchiving(false) });
    };
    const [listFilters, setListFilters] = useState({
        search: filters.search || '',
        rank: filters.rank || '',
        status: filters.status || '',
        sort: filters.sort,
        direction: filters.direction,
    });
    useDebouncedFilters('/employees', listFilters);
    const remove = async (employee) => {
        if (await ask({ title: 'Delete faculty record?', message: `Permanently delete ${fullName(employee)} including their schedules, attendance, payroll records, and linked credentials? To retain these and hide them from the system, use Archive instead. This deletion cannot be undone.`, confirmLabel: 'Delete Faculty', tone: 'danger' })) {
            router.delete(`/employees/${employee.id}`);
        }
    };
    const sortBy = (sort) => {
        const direction = filters.sort === sort && filters.direction === 'asc' ? 'desc' : 'asc';
        setListFilters((current) => ({ ...current, sort, direction }));
    };
    return <AppLayout title="Faculty" subtitle="Manage faculty profiles, RFID cards, and fingerprint references.">
        <div className="panel"><div className="panel-heading"><h2>Faculty Records</h2><div className="ui-heading-actions"><Link className="ui-control ui-action ui-secondary" href="/archived-faculty"><Archive size={16} />Archived Faculty</Link><Link className="button ui-control ui-action ui-primary" href="/employees/create"><Plus size={16} />Add Faculty</Link></div></div>
            <div className="list-filters faculty-record-filters"><label className="filter-search">Search Faculty<span className="search-control"><Search size={16} /><input placeholder="Name, employee number, or email" value={listFilters.search} onChange={(event) => setListFilters((current) => ({ ...current, search: event.target.value }))} /></span></label><label>Rank<select value={listFilters.rank} onChange={(event) => setListFilters((current) => ({ ...current, rank: event.target.value }))}><option value="">All ranks</option>{ranks.map((rank) => <option key={rank.id} value={rank.id}>{rank.name}</option>)}</select></label><label>Status<select value={listFilters.status} onChange={(event) => setListFilters((current) => ({ ...current, status: event.target.value }))}><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>{(listFilters.search || listFilters.rank || listFilters.status) && <button className="clear-filters ui-control ui-action ui-secondary" type="button" onClick={() => setListFilters((current) => ({ ...current, search: '', rank: '', status: '' }))}><X size={15} />Clear</button>}</div>
            {selected.length > 0 && <div className="ui-archive-bar"><span><strong>{selected.length}</strong> faculty selected</span><button className="ui-control ui-action ui-archive" type="button" disabled={archiving} onClick={() => archive(selected)}><Archive size={16} />{archiving ? 'Archiving...' : 'Archive Selected'}</button><button className="ui-control ui-action ui-secondary" type="button" onClick={() => setSelectedIds([])}>Clear Selection</button></div>}
            <div className="table-wrap"><table className="faculty-records-table"><thead><tr><th><input type="checkbox" aria-label="Select all faculty on this page" disabled={!selectableIds.length || archiving} checked={selectableIds.length > 0 && selected.length === selectableIds.length} onChange={(event) => setSelectedIds(event.target.checked ? selectableIds : [])} /></th><SortHeader label="Employee No." sort="employee_no" activeSort={filters.sort} onSort={sortBy} /><SortHeader label="Name" sort="name" activeSort={filters.sort} onSort={sortBy} /><th>RFID</th><th>Fingerprint ID</th><SortHeader label="Rank" sort="rank" activeSort={filters.sort} onSort={sortBy} /><SortHeader label="Hourly Rate" sort="rate" activeSort={filters.sort} onSort={sortBy} /><SortHeader label="Hours / Week" sort="weekly_hours" activeSort={filters.sort} onSort={sortBy} /><SortHeader label="Status" sort="status" activeSort={filters.sort} onSort={sortBy} /><th className="table-actions-heading">Actions</th></tr></thead><tbody>
                {employees.data.length ? employees.data.map((employee) => { const rank = employee.faculty_rank; const verified = Boolean(employee.user?.email_verified_at); return <tr key={employee.id}><td><input type="checkbox" aria-label={`Select ${fullName(employee)}`} disabled={employee.id === ownEmployeeId || archiving} checked={selected.includes(employee.id)} onChange={() => toggle(employee.id)} /></td><td>{employee.employee_no}</td><td><span className="faculty-identity"><strong>{fullName(employee)}</strong><small>{employee.email}</small><span className={`email-status ${verified ? 'is-verified' : 'is-pending'}`}>{verified ? <BadgeCheck size={12} /> : <MailWarning size={12} />}{verified ? 'Verified' : 'Pending verification'}</span></span></td><td>{employee.rfid_cards?.[0]?.rfid_uid || '-'}</td><td>{employee.fingerprint_templates?.[0]?.fingerprint_code || '-'}</td><td>{rank?.name || '-'}</td><td>{money(employee.rate_amount)}/hour</td><td>{weeklyHours(employee.schedules)} hrs/week</td><td><span data-status={employee.status} className="badge">{employee.status}</span>{employee.contract_days_remaining != null && <span className="contract-countdown">{employee.contract_days_remaining === 0 ? 'Ends today' : `${employee.contract_days_remaining}d left`}</span>}</td><td className="actions"><div className="table-actions"><Link className="ui-control ui-action ui-secondary" href={`/employees/${employee.id}/edit`}>Profile &amp; IDs</Link><Link className="ui-control ui-action ui-secondary" href={`/employees/${employee.id}/schedule`}>Schedule</Link>{!verified && employee.user && <button className="ui-control ui-action ui-secondary" type="button" onClick={() => router.post(`/employees/${employee.id}/email-verification`, {}, { preserveScroll: true })}>Resend verification</button>}<button className="ui-control ui-action ui-archive" type="button" disabled={archiving || employee.id === ownEmployeeId} onClick={() => archive([employee.id])}><Archive size={14} />Archive</button><button className="link-danger ui-control ui-action ui-danger" type="button" onClick={() => remove(employee)}>Delete</button></div></td></tr>; }) : <tr><td colSpan="10">No faculty records yet.</td></tr>}
            </tbody></table></div>
            <Pagination links={employees.links} />
        </div>
        {dialog}
    </AppLayout>;
}
