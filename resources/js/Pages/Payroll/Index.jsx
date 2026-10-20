import { Link, router, useForm, usePage } from '@inertiajs/react';
import { BadgeCheck, Calculator, Search, Send, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Pagination from '../../components/Pagination';
import useDebouncedFilters from '../../hooks/useDebouncedFilters';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import useRealtimeReload from '../../hooks/useRealtimeReload';
import { fullName, money } from '../../lib/format';

function expectedPayDate(startDate, endDate) {
    if (!startDate || !endDate) return '';

    const start = new Date(`${startDate}T00:00:00`);
    const end = new Date(`${endDate}T00:00:00`);

    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return '';
    if (start.getFullYear() !== end.getFullYear() || start.getMonth() !== end.getMonth()) return '';

    const lastDay = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();

    if (start.getDate() === 1 && end.getDate() === 15) {
        return `${startDate.slice(0, 8)}25`;
    }

    if (start.getDate() === 16 && end.getDate() === lastDay) {
        const payDate = new Date(start.getFullYear(), start.getMonth() + 1, 10);
        const year = payDate.getFullYear();
        const month = String(payDate.getMonth() + 1).padStart(2, '0');

        return `${year}-${month}-10`;
    }

    return '';
}

function PeriodRow({ period, ask, canManage }) {
    const generate = async () => {
        if (await ask({
            title: 'Generate payroll?',
            message: `Generate or recalculate draft payroll records for ${period.period_name}?`,
            confirmLabel: 'Generate Payroll',
            tone: 'warning',
        })) {
            router.post(`/payroll/periods/${period.id}/generate`);
        }
    };

    return (
        <tr>
            <td>{period.period_name}</td>
            <td>{period.start_date} to {period.end_date}</td>
            <td>{period.pay_date || '-'}</td>
            <td>{period.records_count}</td>
            <td><span className="badge">{period.status}</span></td>
            {canManage && <td className="actions"><div className="table-actions"><button className="primary-table-action generate-payroll-button" type="button" onClick={generate}><Calculator size={16} />Generate Payroll</button></div></td>}
        </tr>
    );
}

export default function PayrollIndex({ periods, records, filters = {} }) {
    const { auth } = usePage().props;
    const isFaculty = auth.user.role === 'faculty';
    const canReview = !isFaculty;
    const canManage = ['admin', 'payroll_staff'].includes(auth.user.role);
    const { ask, dialog } = useConfirmDialog();
    const form = useForm({ period_name: '', start_date: '', end_date: '', pay_date: '' });
    const [listFilters, setListFilters] = useState({
        search: filters.search || '',
        period: filters.period || '',
        status: filters.status || '',
    });
    const hasActiveFilters = canReview && Object.values(listFilters).some(Boolean);
    const payDate = expectedPayDate(form.data.start_date, form.data.end_date);
    useDebouncedFilters('/payroll', listFilters, { enabled: canReview });
    useRealtimeReload(['periods', 'records'], 10000);

    const submit = (event) => {
        event.preventDefault();
        form.post('/payroll/periods');
    };
    const approveRecord = async (record) => {
        if (await ask({ title: 'Approve payslip?', message: `Approve the payslip for ${fullName(record.employee)}? It cannot be edited after approval.`, confirmLabel: 'Approve Payslip' })) {
            router.post(`/payroll/records/${record.id}/approve`, {}, { preserveScroll: true });
        }
    };
    const releaseRecord = async (record) => {
        if (await ask({ title: 'Release payslip?', message: `Release this payslip to ${fullName(record.employee)}? The faculty member will be able to view and download it.`, confirmLabel: 'Release Payslip', tone: 'warning' })) {
            router.post(`/payroll/records/${record.id}/release`, {}, { preserveScroll: true });
        }
    };

    return (
        <AppLayout title="Payroll" subtitle={canManage ? 'Create payroll periods and compute pay from attendance logs.' : isFaculty ? 'View your released payslips and payroll summary.' : 'Review payroll periods and faculty payroll records.'}>
            {canReview && (
                <section className={`content-grid payroll-overview${canManage ? '' : ' is-read-only'}`}>
                    {canManage && <form className="panel compact-form" onSubmit={submit}>
                        <h2>New Payroll Period</h2>
                        <label>
                            Period Name
                            <input
                                placeholder="September 1-15, 2026"
                                value={form.data.period_name}
                                onChange={(e) => form.setData('period_name', e.target.value)}
                                required
                            />
                        </label>
                        <label>
                            Start Date
                            <input
                                type="date"
                                value={form.data.start_date}
                                onChange={(e) => form.setData('start_date', e.target.value)}
                                required
                            />
                        </label>
                        <label>
                            End Date
                            <input
                                type="date"
                                value={form.data.end_date}
                                onChange={(e) => form.setData('end_date', e.target.value)}
                                required
                            />
                        </label>
                        <label>
                            Pay Date
                            <input type="date" value={payDate} readOnly />
                        </label>
                        {(form.errors.start_date || form.errors.end_date) && (
                            <div className="form-note">{form.errors.start_date || form.errors.end_date}</div>
                        )}
                        <button type="submit" disabled={form.processing}>Create Period</button>
                    </form>}

                    <div className="panel">
                        <h2>Payroll Periods</h2>
                        <div className="table-wrap">
                            <table className="payroll-actions-table">
                                <thead>
                                    <tr>
                                        <th>Period</th>
                                        <th>Coverage</th>
                                        <th>Pay Date</th>
                                        <th>Records</th>
                                        <th>Status</th>
                                        {canManage && <th className="table-actions-heading">Actions</th>}
                                    </tr>
                                </thead>
                                <tbody>
                                    {periods.length ? periods.map((period) => (
                                        <PeriodRow key={period.id} period={period} ask={ask} canManage={canManage} />
                                    )) : (
                                        <tr><td colSpan={canManage ? 6 : 5}>No payroll periods yet.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            )}

            <div className="panel">
                <div className="panel-heading"><h2>{isFaculty ? 'My Payroll Records' : 'Payroll Records'}</h2></div>
                {canReview && <div className="list-filters payroll-record-filters"><label className="filter-search">Search Payroll<span className="search-control"><Search size={16} /><input placeholder="Faculty, employee number, or period" value={listFilters.search} onChange={(event) => setListFilters((current) => ({ ...current, search: event.target.value }))} /></span></label><label>Payroll Period<select value={listFilters.period} onChange={(event) => setListFilters((current) => ({ ...current, period: event.target.value }))}><option value="">All periods</option>{periods.map((period) => <option key={period.id} value={period.id}>{period.period_name}</option>)}</select></label><label>Status<select value={listFilters.status} onChange={(event) => setListFilters((current) => ({ ...current, status: event.target.value }))}><option value="">All statuses</option><option value="draft">Draft</option><option value="approved">Approved</option><option value="released">Released</option></select></label>{Object.values(listFilters).some(Boolean) && <button className="clear-filters" type="button" onClick={() => setListFilters({ search: '', period: '', status: '' })}><X size={15} />Clear</button>}</div>}
                <div className="table-wrap">
                    <table className="payroll-actions-table">
                        <thead>
                            <tr>
                                <th>Faculty</th>
                                <th>Period</th>
                                <th>Days</th>
                                <th>Hours</th>
                                <th>Gross</th>
                                <th>Deductions</th>
                                <th>Net Pay</th>
                                {canReview && <th>Status</th>}
                                <th className="table-actions-heading">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {records.data.length ? records.data.map((record) => (
                                <tr key={record.id}>
                                    <td>{fullName(record.employee)}</td>
                                    <td>{record.payroll_period?.period_name}</td>
                                    <td>{record.total_days_worked}</td>
                                    <td>{record.total_hours_worked}</td>
                                    <td>{money(record.gross_pay)}</td>
                                    <td>{money(record.total_deductions)}</td>
                                    <td><strong>{money(record.net_pay)}</strong></td>
                                    {canReview && <td><span className={`badge payroll-status is-${record.status}`}>{record.status}</span></td>}
                                    <td className="actions"><div className="table-actions"><Link href={`/payroll/records/${record.id}`}>View</Link>{canManage && record.status === 'draft' && <button className="table-action-primary" type="button" onClick={() => approveRecord(record)}><BadgeCheck size={14} />Approve</button>}{canManage && record.status === 'approved' && <button className="table-action-primary is-release" type="button" onClick={() => releaseRecord(record)}><Send size={14} />Release</button>}</div></td>
                                </tr>
                            )) : (
                                <tr>
                                    <td className="empty-row" colSpan={canReview ? 9 : 8}>
                                        {canReview
                                            ? (hasActiveFilters ? 'No payroll records match the selected filters.' : 'No payroll records have been generated yet.')
                                            : 'No payslips have been released to your account yet.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <Pagination links={records.links} />
            </div>
            {dialog}
        </AppLayout>
    );
}
