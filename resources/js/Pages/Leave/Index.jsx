import FacultyCombobox from '../../components/FacultyCombobox';
import { formatDate } from '../../lib/format';
import DateInput from '../../components/DateInput';
import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CalendarRange, Check, FileCheck2, RotateCcw, Search, Send, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Pagination from '../../components/Pagination';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import useDebouncedFilters from '../../hooks/useDebouncedFilters';
import { fullName } from '../../lib/format';

const dateText = formatDate;
const emptyFilters = { employee_id: '', search: '', status: '', leave_type_id: '', date_from: '', date_to: '' };

function RejectionDialog({ leaveRequest, form, onClose }) {
    const fieldRef = useRef(null);
    useEffect(() => {
        if (!leaveRequest) return undefined;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        fieldRef.current?.focus();
        const closeOnEscape = (event) => { if (event.key === 'Escape' && !form.processing) onClose(); };
        window.addEventListener('keydown', closeOnEscape);
        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', closeOnEscape);
        };
    }, [form.processing, leaveRequest, onClose]);

    if (!leaveRequest) return null;
    const submit = (event) => {
        event.preventDefault();
        form.put(`/leave/${leaveRequest.id}/review`, { preserveScroll: true, onSuccess: onClose });
    };

    return <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !form.processing) onClose(); }}>
        <form className="confirm-dialog is-danger leave-rejection-dialog" role="dialog" aria-modal="true" aria-labelledby="leave-rejection-title" onSubmit={submit}>
            <button className="dialog-close" type="button" onClick={onClose} disabled={form.processing} aria-label="Close dialog"><X size={18} /></button>
            <span className="dialog-icon"><AlertTriangle size={23} /></span>
            <div className="dialog-copy"><h2 id="leave-rejection-title">Reject leave request?</h2><p>{fullName(leaveRequest.employee)} | {leaveRequest.leave_type.name} | {dateText(leaveRequest.start_date)} to {dateText(leaveRequest.end_date)}</p></div>
            <label className="leave-rejection-field"><span>Reason for rejection</span><textarea ref={fieldRef} rows="4" maxLength="1000" value={form.data.reviewer_remarks} onChange={(event) => form.setData('reviewer_remarks', event.target.value)} placeholder="Explain what needs to be corrected or submitted." required />{form.errors.reviewer_remarks && <small>{form.errors.reviewer_remarks}</small>}</label>
            <div className="dialog-actions"><button className="dialog-cancel" type="button" onClick={onClose} disabled={form.processing}>Cancel</button><button className="dialog-confirm" type="submit" disabled={form.processing || !form.data.reviewer_remarks.trim()}>Reject Request</button></div>
        </form>
    </div>;
}

export default function LeaveIndex({ requests, leaveTypes, canReview, filters = {} }) {
    const form = useForm({ leave_type_id: '', start_date: '', end_date: '', reason: '' });
    const rejectionForm = useForm({ status: 'rejected', reviewer_remarks: '' });
    const [rejecting, setRejecting] = useState(null);
    const { facultyChoices } = usePage().props;
    const [filterValues, setFilterValues] = useState({ ...emptyFilters, ...filters });
    const { ask, dialog } = useConfirmDialog();
    useDebouncedFilters('/leave', filterValues);

    const submit = (event) => {
        event.preventDefault();
        form.post('/leave', { preserveScroll: true, onSuccess: () => form.reset() });
    };
    const approve = async (request) => {
        if (!await ask({ title: 'Approve leave request?', message: `Approve the ${request.leave_type.name} request of ${fullName(request.employee)} for ${dateText(request.start_date)} to ${dateText(request.end_date)}?`, confirmLabel: 'Approve Leave', tone: 'warning' })) return;
        router.put(`/leave/${request.id}/review`, { status: 'approved', reviewer_remarks: '' }, { preserveScroll: true });
    };
    const openRejection = (request) => {
        rejectionForm.reset();
        rejectionForm.clearErrors();
        setRejecting(request);
    };
    const closeRejection = () => {
        if (rejectionForm.processing) return;
        setRejecting(null);
        rejectionForm.reset();
        rejectionForm.clearErrors();
    };
    const cancel = async (request) => {
        if (await ask({ title: 'Cancel leave request?', message: 'Withdraw this pending leave request?', confirmLabel: 'Cancel Request', tone: 'danger' })) router.post(`/leave/${request.id}/cancel`, {}, { preserveScroll: true });
    };
    const setFilter = (key, value) => setFilterValues((current) => ({ ...current, [key]: value }));
    const hasFilters = Object.values(filterValues).some(Boolean);

    return <AppLayout title="Leave" subtitle={canReview ? 'Review faculty leave requests and their DTR impact.' : 'File and monitor your leave requests.'}>
        {!canReview && <form className="panel leave-request-form" onSubmit={submit}>
            <div className="panel-heading"><div><span className="bento-eyebrow">New request</span><h2>File Faculty Leave</h2></div><FileCheck2 size={22} /></div>
            <label>Leave type<select value={form.data.leave_type_id} onChange={(e) => form.setData('leave_type_id', e.target.value)} required><option value="">Select leave type</option>{leaveTypes.map((type) => <option value={type.id} key={type.id}>{type.name}</option>)}</select></label>
            <div className="form-pair"><label>Start date<DateInput value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} required /></label><label>End date<DateInput min={form.data.start_date || undefined} value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} required /></label></div>
            <label>Reason<textarea rows="4" maxLength="1000" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required /></label>
            <div className="form-actions"><button className="ui-control ui-action ui-primary" disabled={form.processing}><Send size={17} />Submit Request</button></div>
        </form>}

        <section className="panel leave-request-list">
            <div className="panel-heading"><div><span className="bento-eyebrow">{canReview ? 'Approval queue' : 'Request history'}</span><h2>{canReview ? 'Faculty Leave Requests' : 'My Leave Requests'}</h2></div><CalendarRange size={22} /></div>
            <div className="leave-filter-bar">
                {canReview && <FacultyCombobox employees={facultyChoices} label="Search Faculty" allowClear selectedId={filterValues.employee_id} onSelect={(id) => setFilter('employee_id', id)} />}
                <label>Search reason<span className="leave-filter-search"><Search size={16} /><input type="search" value={filterValues.search} onChange={(event) => setFilter('search', event.target.value)} placeholder="Reason" /></span></label>
                <label>Status<select value={filterValues.status} onChange={(event) => setFilter('status', event.target.value)}><option value="">All statuses</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option></select></label>
                <label>Leave type<select value={filterValues.leave_type_id} onChange={(event) => setFilter('leave_type_id', event.target.value)}><option value="">All types</option>{leaveTypes.map((type) => <option value={type.id} key={type.id}>{type.name}</option>)}</select></label>
                <label>From<DateInput value={filterValues.date_from} onChange={(event) => setFilter('date_from', event.target.value)} /></label>
                <label>To<DateInput min={filterValues.date_from || undefined} value={filterValues.date_to} onChange={(event) => setFilter('date_to', event.target.value)} /></label>
                <button className="leave-filter-clear ui-control ui-action ui-secondary" type="button" onClick={() => setFilterValues(emptyFilters)} disabled={!hasFilters}><RotateCcw size={15} />Clear</button>
            </div>
            <div className="leave-cards">{requests.data.length ? requests.data.map((request) => <article className="leave-card" key={request.id}>
                <div className="leave-card-main"><span className={`leave-status is-${request.status}`}>{request.status}</span><h3>{request.leave_type.name}<small>Approved leave is excluded from expected class sessions</small></h3>{canReview && <strong>{fullName(request.employee)} | {request.employee.employee_no}</strong>}<p>{dateText(request.start_date)} to {dateText(request.end_date)}</p><blockquote>{request.reason}</blockquote>{request.reviewer_remarks && <small><strong>{request.status === 'rejected' ? 'Rejection reason' : 'Reviewer remarks'}:</strong> {request.reviewer_remarks}</small>}{request.reviewer && <small>Reviewed by {request.reviewer.name}{request.reviewed_at ? ` on ${dateText(request.reviewed_at)}` : ''}</small>}</div>
                <div className="leave-card-actions">{canReview && request.status === 'pending' && <><button type="button" className="leave-approve ui-control ui-action ui-primary" onClick={() => approve(request)}><Check size={16} />Approve</button><button type="button" className="leave-reject ui-control ui-action ui-danger" onClick={() => openRejection(request)}><X size={16} />Reject</button></>}{!canReview && request.status === 'pending' && <button type="button" className="leave-reject ui-control ui-action ui-danger" onClick={() => cancel(request)}><X size={16} />Cancel</button>}</div>
            </article>) : <p className="empty-row">No leave requests match the selected filters.</p>}</div>
            <Pagination links={requests.links} />
        </section>
        <RejectionDialog leaveRequest={rejecting} form={rejectionForm} onClose={closeRejection} />
        {dialog}
    </AppLayout>;
}
