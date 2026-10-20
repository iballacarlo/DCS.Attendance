import { Link, router, useForm, usePage } from '@inertiajs/react';
import { BadgeCheck, Calculator, Search, Send, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Pagination from '../../components/Pagination';
import useDebouncedFilters from '../../hooks/useDebouncedFilters';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import useRealtimeReload from '../../hooks/useRealtimeReload';
import { dateToday, fullName, money } from '../../lib/format';

function payrollCutoffOptions() {
    const today = new Date(`${dateToday()}T00:00:00`);
    const options = [];

    for (let offset = 0; offset < 24; offset += 1) {
        const month = new Date(today.getFullYear(), today.getMonth() - offset, 1);
        const year = month.getFullYear();
        const monthIndex = month.getMonth();
        const monthNumber = String(monthIndex + 1).padStart(2, '0');
        const monthName = month.toLocaleDateString('en-PH', { month: 'long' });
        const lastDay = new Date(year, monthIndex + 1, 0).getDate();
        const first = {
            start: `${year}-${monthNumber}-01`,
            end: `${year}-${monthNumber}-15`,
            label: `${monthName} 1-15, ${year}`,
        };
        const second = {
            start: `${year}-${monthNumber}-16`,
            end: `${year}-${monthNumber}-${lastDay}`,
            label: `${monthName} 16-${lastDay}, ${year}`,
        };

        options.push(today.getDate() <= 15 ? first : second);
        options.push(today.getDate() <= 15 ? second : first);
    }

    return options;
}

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
    const cutoffOptions = payrollCutoffOptions();
    const defaultCutoff = cutoffOptions[0];
    const form = useForm({ period_name: defaultCutoff.label, start_date: defaultCutoff.start, end_date: defaultCutoff.end, pay_date: '' });
    const [listFilters, setListFilters] = useState({
        search: filters.search || '',
        period: filters.period || '',
        status: filters.status || '',
    });
    const [selectedRecordIds, setSelectedRecordIds] = useState([]);
    const hasActiveFilters = canReview && Object.values(listFilters).some(Boolean);
    const payDate = expectedPayDate(form.data.start_date, form.data.end_date);
    const selectedRecords = records.data.filter((record) => selectedRecordIds.includes(record.id));
    const selectionPeriodId = selectedRecords[0]?.payroll_period?.id || '';
    const activePeriodId = listFilters.period || selectionPeriodId;
    const selectableRecords = canManage
        ? records.data.filter((record) => ['draft', 'approved'].includes(record.status)
            && (!activePeriodId || String(record.payroll_period?.id) === String(activePeriodId)))
        : [];
    const selectableIds = selectableRecords.map((record) => record.id);
    const selectableKey = selectableIds.join(',');
    const selectAllPeriodId = activePeriodId || selectableRecords[0]?.payroll_period?.id || '';
    const selectAllIds = selectableRecords
        .filter((record) => String(record.payroll_period?.id) === String(selectAllPeriodId))
        .map((record) => record.id);
    const selectedDrafts = selectedRecords.filter((record) => record.status === 'draft').length;
    const selectedApproved = selectedRecords.filter((record) => record.status === 'approved').length;
    const allVisibleSelected = selectAllIds.length > 0 && selectAllIds.every((id) => selectedRecordIds.includes(id));
    useDebouncedFilters('/payroll', listFilters, { enabled: canReview });
    useRealtimeReload(['periods', 'records'], 10000);
    useEffect(() => {
        setSelectedRecordIds((current) => current.filter((id) => selectableIds.includes(id)));
    }, [selectableKey]);

    const submit = (event) => {
        event.preventDefault();
        form.post('/payroll/periods');
    };
    const selectCutoff = (value) => {
        const cutoff = cutoffOptions.find((option) => `${option.start}|${option.end}` === value);
        if (!cutoff) return;
        form.setData({ ...form.data, period_name: cutoff.label, start_date: cutoff.start, end_date: cutoff.end });
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
    const changeListFilter = (key, value) => {
        setSelectedRecordIds([]);
        setListFilters((current) => ({ ...current, [key]: value }));
    };
    const toggleRecord = (recordId) => {
        setSelectedRecordIds((current) => current.includes(recordId)
            ? current.filter((id) => id !== recordId)
            : [...current, recordId]);
    };
    const toggleAllVisible = () => {
        setSelectedRecordIds(allVisibleSelected ? [] : selectAllIds);
    };
    const bulkStatus = async (action) => {
        const eligibleCount = action === 'approve' ? selectedDrafts : selectedApproved;
        if (!eligibleCount) return;
        const verb = action === 'approve' ? 'Approve' : 'Release';
        const confirmed = await ask({
            title: `${verb} selected payslips?`,
            message: `${verb} ${eligibleCount} selected payslip${eligibleCount === 1 ? '' : 's'} for ${periods.find((period) => String(period.id) === String(activePeriodId))?.period_name || 'this payroll period'}? Records with a different status will be skipped.`,
            confirmLabel: `${verb} Selected`,
            tone: action === 'release' ? 'warning' : 'default',
        });
        if (!confirmed) return;
        router.post('/payroll/records/bulk-status', { record_ids: selectedRecordIds, action }, {
            preserveScroll: true,
            onSuccess: () => setSelectedRecordIds([]),
        });
    };

    return (
        <AppLayout title="Payroll" subtitle={canManage ? 'Create payroll periods and compute pay from attendance logs.' : isFaculty ? 'View your released payslips and payroll summary.' : 'Review payroll periods and faculty payroll records.'}>
            {canReview && (
                <section className={`content-grid payroll-overview${canManage ? '' : ' is-read-only'}`}>
                    {canManage && <form className="panel compact-form" onSubmit={submit}>
                        <h2>New Payroll Period</h2>
                        <label>
                            Cutoff Period
                            <select value={`${form.data.start_date}|${form.data.end_date}`} onChange={(e) => selectCutoff(e.target.value)}>
                                {cutoffOptions.map((option) => <option value={`${option.start}|${option.end}`} key={option.start}>{option.label}</option>)}
                            </select>
                        </label>
                        <label>
                            Pay Date
                            <input type="date" value={payDate} readOnly />
                        </label>
                        {(form.errors.period_name || form.errors.start_date || form.errors.end_date) && (
                            <div className="form-note">{form.errors.period_name || form.errors.start_date || form.errors.end_date}</div>
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
                {canReview && <div className="list-filters payroll-record-filters"><label className="filter-search">Search Payroll<span className="search-control"><Search size={16} /><input placeholder="Faculty, employee number, or period" value={listFilters.search} onChange={(event) => changeListFilter('search', event.target.value)} /></span></label><label>Payroll Period<select value={listFilters.period} onChange={(event) => changeListFilter('period', event.target.value)}><option value="">All periods</option>{periods.map((period) => <option key={period.id} value={period.id}>{period.period_name}</option>)}</select></label><label>Status<select value={listFilters.status} onChange={(event) => changeListFilter('status', event.target.value)}><option value="">All statuses</option><option value="draft">Draft</option><option value="approved">Approved</option><option value="released">Released</option></select></label>{Object.values(listFilters).some(Boolean) && <button className="clear-filters" type="button" onClick={() => { setSelectedRecordIds([]); setListFilters({ search: '', period: '', status: '' }); }}><X size={15} />Clear</button>}</div>}
                {canManage && <div className={`payroll-bulk-bar${activePeriodId ? ' is-ready' : ''}`}>
                    <div><strong>{selectedRecordIds.length ? `${selectedRecordIds.length} selected` : 'Select payslips'}</strong><span>{activePeriodId ? 'Selection is limited to one payroll period.' : 'Choose records below, or filter by cutoff for faster selection.'}</span></div>
                    <div className="payroll-bulk-actions"><button type="button" disabled={!selectedDrafts} onClick={() => bulkStatus('approve')}><BadgeCheck size={16} />Approve Selected{selectedDrafts ? ` (${selectedDrafts})` : ''}</button><button className="is-release" type="button" disabled={!selectedApproved} onClick={() => bulkStatus('release')}><Send size={16} />Release Selected{selectedApproved ? ` (${selectedApproved})` : ''}</button></div>
                </div>}
                <div className="table-wrap">
                    <table className="payroll-actions-table">
                        <thead>
                            <tr>
                                {canManage && <th className="payroll-select-cell"><input type="checkbox" aria-label="Select all visible eligible payslips in one payroll period" checked={allVisibleSelected} disabled={!selectAllIds.length} onChange={toggleAllVisible} /></th>}
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
                                    {canManage && <td className="payroll-select-cell"><input type="checkbox" aria-label={`Select payslip for ${fullName(record.employee)}`} checked={selectedRecordIds.includes(record.id)} disabled={!selectableIds.includes(record.id)} onChange={() => toggleRecord(record.id)} /></td>}
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
                                    <td className="empty-row" colSpan={canManage ? 10 : canReview ? 9 : 8}>
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
