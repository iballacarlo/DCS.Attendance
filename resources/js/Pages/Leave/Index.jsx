import { router, useForm } from '@inertiajs/react';
import { CalendarRange, Check, FileCheck2, Send, X } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import Pagination from '../../components/Pagination';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import { fullName } from '../../lib/format';

const dateText = (value) => new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });

export default function LeaveIndex({ requests, leaveTypes, canReview }) {
    const form = useForm({ leave_type_id: '', start_date: '', end_date: '', reason: '' });
    const { ask, dialog } = useConfirmDialog();
    const submit = (event) => {
        event.preventDefault();
        form.post('/leave', { preserveScroll: true, onSuccess: () => form.reset() });
    };
    const review = async (request, status) => {
        const approved = status === 'approved';
        if (!await ask({ title: `${approved ? 'Approve' : 'Reject'} leave request?`, message: `${approved ? 'Approve' : 'Reject'} the ${request.leave_type.name} request of ${fullName(request.employee)} for ${dateText(request.start_date)} to ${dateText(request.end_date)}?`, confirmLabel: approved ? 'Approve Leave' : 'Reject Leave', tone: approved ? 'warning' : 'danger' })) return;
        router.put(`/leave/${request.id}/review`, { status, reviewer_remarks: '' }, { preserveScroll: true });
    };
    const cancel = async (request) => {
        if (await ask({ title: 'Cancel leave request?', message: 'Withdraw this pending leave request?', confirmLabel: 'Cancel Request', tone: 'danger' })) router.post(`/leave/${request.id}/cancel`, {}, { preserveScroll: true });
    };

    return <AppLayout title="Leave" subtitle={canReview ? 'Review faculty leave requests and their DTR impact.' : 'File and monitor your leave requests.'}>
        {!canReview && <form className="panel leave-request-form" onSubmit={submit}>
            <div className="panel-heading"><div><span className="bento-eyebrow">New request</span><h2>File Faculty Leave</h2></div><FileCheck2 size={22} /></div>
            <label>Leave type<select value={form.data.leave_type_id} onChange={(e) => form.setData('leave_type_id', e.target.value)} required><option value="">Select leave type</option>{leaveTypes.map((type) => <option value={type.id} key={type.id}>{type.name} · {type.is_paid ? 'Paid' : 'Without pay'}</option>)}</select></label>
            <div className="form-pair"><label>Start date<input type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} required /></label><label>End date<input type="date" min={form.data.start_date || undefined} value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} required /></label></div>
            <label>Reason<textarea rows="4" maxLength="1000" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required /></label>
            <p className="form-note">Only approved leave affects the DTR. Paid leave credits scheduled hours; leave without pay records the leave but credits no hours.</p>
            <div className="form-actions"><button disabled={form.processing}><Send size={17} />Submit Request</button></div>
        </form>}

        <section className="panel leave-request-list">
            <div className="panel-heading"><div><span className="bento-eyebrow">{canReview ? 'Approval queue' : 'Request history'}</span><h2>{canReview ? 'Faculty Leave Requests' : 'My Leave Requests'}</h2></div><CalendarRange size={22} /></div>
            <div className="leave-cards">{requests.data.length ? requests.data.map((request) => <article className="leave-card" key={request.id}>
                <div className="leave-card-main"><span className={`leave-status is-${request.status}`}>{request.status}</span><h3>{request.leave_type.name}<small>{request.leave_type.is_paid ? 'Paid leave' : 'Leave without pay'}</small></h3>{canReview && <strong>{fullName(request.employee)} · {request.employee.employee_no}</strong>}<p>{dateText(request.start_date)} – {dateText(request.end_date)}</p><blockquote>{request.reason}</blockquote>{request.reviewer_remarks && <small>Reviewer: {request.reviewer_remarks}</small>}</div>
                <div className="leave-card-actions">{canReview && request.status === 'pending' && <><button type="button" className="leave-approve" onClick={() => review(request, 'approved')}><Check size={16} />Approve</button><button type="button" className="leave-reject" onClick={() => review(request, 'rejected')}><X size={16} />Reject</button></>}{!canReview && request.status === 'pending' && <button type="button" className="leave-reject" onClick={() => cancel(request)}><X size={16} />Cancel</button>}</div>
            </article>) : <p className="empty-row">No leave requests yet.</p>}</div>
            <Pagination links={requests.links} />
        </section>
        {dialog}
    </AppLayout>;
}
