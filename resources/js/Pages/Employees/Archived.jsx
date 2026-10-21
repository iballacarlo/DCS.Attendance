import { Link, router } from '@inertiajs/react';
import { Archive, ArrowLeft, RotateCcw, Search, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Pagination from '../../components/Pagination';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import useDebouncedFilters from '../../hooks/useDebouncedFilters';
import { formatDate, fullName } from '../../lib/format';

export default function ArchivedFaculty({ employees, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [restoring, setRestoring] = useState(null);
    const { ask, dialog } = useConfirmDialog();
    useDebouncedFilters('/archived-faculty', { search });
    const restore = async (employee) => {
        if (!await ask({ title: 'Restore faculty?', message: `Restore ${fullName(employee)} with their saved schedules and history? Their previous status (${employee.status}) will be retained, and their account will be available again.`, confirmLabel: 'Restore Faculty' })) return;
        setRestoring(employee.id);
        router.post(`/archived-faculty/${employee.id}/restore`, {}, { preserveScroll: true, onFinish: () => setRestoring(null) });
    };
    return <AppLayout title="Archived Faculty" subtitle="Restore faculty with their saved schedules and records.">
        <section className="panel">
            <div className="panel-heading"><div><span className="bento-eyebrow">Faculty archive</span><h2>Archived Faculty <span className="ui-record-count">{employees.total}</span></h2></div><Link className="ui-control ui-action ui-secondary" href="/employees"><ArrowLeft size={16} />Back to Faculty</Link></div>
            <p className="form-note"><Archive size={16} /> Archived faculty and their records are hidden from the normal system pages. Their schedules, attendance, payroll, and credentials are retained. Restore a faculty member to make them available again.</p>
            <div className="list-filters archive-filters"><label className="filter-search">Search archived faculty<span className="search-control"><Search size={16} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, employee number, or email" /></span></label>{search && <button className="ui-control ui-action ui-secondary" type="button" onClick={() => setSearch('')}><X size={15} />Clear</button>}</div>
            <div className="table-wrap"><table><thead><tr><th>Employee No.</th><th>Faculty</th><th>Archived on</th><th>Saved schedule blocks</th><th>Previous status</th><th>Action</th></tr></thead><tbody>{employees.data.length ? employees.data.map((employee) => <tr key={employee.id}><td>{employee.employee_no}</td><td><strong>{fullName(employee)}</strong><small>{employee.email}</small></td><td>{formatDate(new Date(employee.archived_at))}</td><td>{employee.schedules_count + employee.schedule_breaks_count}</td><td><span className="badge" data-status={employee.status}>{employee.status}</span></td><td><button className="ui-control ui-action ui-primary" type="button" disabled={restoring !== null} onClick={() => restore(employee)}><RotateCcw size={16} />{restoring === employee.id ? 'Restoring…' : 'Restore'}</button></td></tr>) : <tr><td colSpan={6} className="empty-row">{search ? 'No archived faculty match your search.' : 'No archived faculty. Archive a faculty member from the Faculty page.'}</td></tr>}</tbody></table></div>
            <Pagination links={employees.links} />
        </section>
        {dialog}
    </AppLayout>;
}
