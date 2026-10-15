import { Link, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { BriefcaseBusiness, Check, CircleCheck, CircleHelp, Copy, Fingerprint, KeyRound, Link2Off, LoaderCircle, Plus, Radio, RefreshCw, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

const attainmentOptions = [
    "Bachelor's Degree",
    'Post-Baccalaureate Certificate or Diploma',
    "Master's Degree Units",
    "Master's Degree",
    'Doctorate Degree Units',
    'Doctorate Degree',
    'Postdoctoral Studies',
];

function FieldLabel({ children, note, required = false }) {
    return <span className="field-label"><span>{children}{required && <b aria-hidden="true"> *</b>}</span>{note && <span className="field-help" aria-label={note} data-tooltip={note}><CircleHelp size={13} /></span>}</span>;
}

function suggestedEmail(firstName, lastName) {
    const clean = (value) => value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '');

    const first = clean(firstName);
    const last = clean(lastName);

    return first && last ? `${first}.${last}@cvsu.edu.ph` : '';
}

function localPhoneNumber(value) {
    const digits = String(value || '').replace(/\D/g, '');
    if (digits.startsWith('63')) return digits.slice(2, 12);
    if (digits.startsWith('0')) return digits.slice(1, 11);
    return digits.slice(0, 10);
}

function serviceDuration(startMonth) {
    if (!startMonth) return 'Month / Year';
    const [year, month] = startMonth.split('-').map(Number);
    const now = new Date();
    const totalMonths = Math.max(0, ((now.getFullYear() - year) * 12) + (now.getMonth() + 1 - month));
    const years = Math.floor(totalMonths / 12);
    const months = totalMonths % 12;
    return [years ? `${years} ${years === 1 ? 'year' : 'years'}` : '', months ? `${months} ${months === 1 ? 'month' : 'months'}` : ''].filter(Boolean).join(', ') || 'Less than one month';
}

export default function EmployeeForm({ employee, ranks, nextEmployeeNumber }) {
    const { flash } = usePage().props;
    const isNew = !employee.id;
    const emailEdited = useRef(Boolean(employee.email));
    const rfidInput = useRef(null);
    const fingerprintInput = useRef(null);
    const [enrollment, setEnrollment] = useState(null);
    const [enrollmentError, setEnrollmentError] = useState('');
    const [credentialsCopied, setCredentialsCopied] = useState(false);
    const form = useForm({
        employee_no: employee.employee_no || nextEmployeeNumber || '', first_name: employee.first_name || '', middle_name: employee.middle_name || '', last_name: employee.last_name || '', suffix: employee.suffix || '', email: employee.email || '', contact_no: localPhoneNumber(employee.contact_no), highest_educational_attainment: employee.highest_educational_attainment || '', service_start_date: employee.service_start_date?.slice(0, 7) || '', faculty_rank_id: employee.faculty_rank_id || '', rate_amount: employee.rate_amount ?? '', status: employee.status || 'active', contract_start: employee.contract_start || '', contract_end: employee.contract_end || '', employment_history: (employee.employment_histories || []).map((history) => ({ ...history, started_on: history.started_on?.slice(0, 7) || '', ended_on: history.ended_on?.slice(0, 7) || '' })), rfid_uid: employee.rfid_cards?.[0]?.rfid_uid || '', fingerprint_code: employee.fingerprint_templates?.[0]?.fingerprint_code || '', finger_label: employee.fingerprint_templates?.[0]?.finger_label || '', rfid_reregister: false, fingerprint_reregister: false,
    });
    const updateName = (field, value) => {
        const updated = { ...form.data, [field]: value };
        form.setData(emailEdited.current ? updated : { ...updated, email: suggestedEmail(updated.first_name, updated.last_name) });
    };
    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            email: data.email.trim().toLowerCase(),
            contact_no: data.contact_no ? `+63${data.contact_no}` : null,
            service_start_date: data.service_start_date ? `${data.service_start_date}-01` : null,
            employment_history: data.employment_history.map((history) => ({
                ...history,
                started_on: `${history.started_on}-01`,
                ended_on: history.ended_on ? `${history.ended_on}-01` : null,
            })),
        }));
        employee.id ? form.put(`/employees/${employee.id}`) : form.post('/employees');
    };
    useEffect(() => {
        if (!enrollment?.id || !['pending', 'processing'].includes(enrollment.status)) return undefined;

        const checkStatus = async () => {
            try {
                const { data } = await axios.get(`/employees/${employee.id}/credentials/enrollments/${enrollment.id}`);
                setEnrollment(data);
                if (data.status === 'completed') {
                    if (data.method === 'rfid') {
                        form.setData('rfid_uid', data.identifier);
                    } else {
                        form.setData('fingerprint_code', data.identifier);
                    }
                }
            } catch (error) {
                setEnrollmentError(error.response?.data?.message || 'Could not check the device registration status.');
            }
        };

        const timer = window.setInterval(checkStatus, 750);
        return () => window.clearInterval(timer);
    }, [enrollment?.id, enrollment?.status]);

    const startHardwareRegistration = async (method) => {
        if (isNew) return;
        const existingIdentifier = method === 'rfid' ? form.data.rfid_uid : form.data.fingerprint_code;
        if (existingIdentifier && !window.confirm(`Replace this faculty member's existing ${method === 'rfid' ? 'RFID card' : 'fingerprint'}? The old credential remains active until the new registration succeeds.`)) return;
        setEnrollmentError('');
        setEnrollment({ method, status: 'starting' });
        try {
            const { data } = await axios.post(`/employees/${employee.id}/credentials/enrollments`, {
                method,
                finger_label: method === 'fingerprint' ? form.data.finger_label : null,
            });
            setEnrollment(data);
        } catch (error) {
            setEnrollment(null);
            setEnrollmentError(error.response?.data?.message || 'Could not start device registration.');
        }
    };
    const cancelHardwareRegistration = async () => {
        if (!enrollment?.id) return;
        const { data } = await axios.delete(`/employees/${employee.id}/credentials/enrollments/${enrollment.id}`);
        setEnrollment(data);
    };
    const unlinkCredential = async (method) => {
        const identifier = method === 'rfid' ? form.data.rfid_uid : form.data.fingerprint_code;
        const confirmation = window.prompt(`Type UNLINK to remove this ${method === 'rfid' ? 'RFID card' : 'fingerprint'} from this faculty member.`);
        if (confirmation !== 'UNLINK') return;

        setEnrollmentError('');
        try {
            const { data } = await axios.post(`/employees/${employee.id}/credentials/${method}/unlink`, {
                confirmation,
                current_identifier: identifier,
            });
            setEnrollment(data);
            if (method === 'rfid') {
                form.setData('rfid_uid', '');
            }
        } catch (error) {
            setEnrollmentError(error.response?.data?.message || `Could not unlink the ${method} credential.`);
        }
    };
    const selectRank = (rankId) => {
        const rank = ranks.find((item) => String(item.id) === String(rankId));
        form.setData({
            ...form.data,
            faculty_rank_id: rankId,
            rate_amount: rank ? Number(rank.rate_amount || 0).toFixed(2) : '',
        });
    };
    const addEmploymentHistory = () => form.setData('employment_history', [
        ...form.data.employment_history,
        { employer: '', position: '', started_on: '', ended_on: '' },
    ]);
    const updateEmploymentHistory = (index, field, value) => form.setData('employment_history', form.data.employment_history.map((history, historyIndex) => historyIndex === index ? { ...history, [field]: value } : history));
    const removeEmploymentHistory = (index) => form.setData('employment_history', form.data.employment_history.filter((_, historyIndex) => historyIndex !== index));
    return <AppLayout title={employee.id ? 'Edit Faculty' : 'Add Faculty'} subtitle={isNew ? 'Set up the faculty employment record and initial login.' : 'Manage employment settings and attendance identifiers.'}>
        <form className="panel form-grid" onSubmit={submit}>
            <div className="form-section-title"><span>01</span><div><strong>Personal information</strong><small>Faculty identity and contact details</small></div></div>
            <div className="name-fields">
                <label><FieldLabel required note="Faculty member's legal first name.">First Name</FieldLabel><input value={form.data.first_name} autoComplete="given-name" onChange={(e) => updateName('first_name', e.target.value)} required /></label>
                <label><FieldLabel note="Include the full middle name when available.">Middle Name</FieldLabel><input value={form.data.middle_name} autoComplete="additional-name" onChange={(e) => form.setData('middle_name', e.target.value)} /></label>
                <label><FieldLabel required note="Faculty member's legal family name.">Last Name</FieldLabel><input value={form.data.last_name} autoComplete="family-name" onChange={(e) => updateName('last_name', e.target.value)} required /></label>
                <label><FieldLabel note="Examples: Jr., Sr., II, or III.">Suffix</FieldLabel><input value={form.data.suffix} maxLength="20" placeholder="Jr., III" onChange={(e) => form.setData('suffix', e.target.value)} /></label>
            </div>
            <div className="personal-contact-fields">
                <label><FieldLabel note="Generated system identifier for this faculty record.">Employee Number</FieldLabel><input value={form.data.employee_no} readOnly aria-readonly="true" /></label>
                <label><FieldLabel required note="Use an active institutional address ending in @cvsu.edu.ph.">Email Address</FieldLabel><input type="email" value={form.data.email} autoComplete="email" placeholder="name@cvsu.edu.ph" pattern="[^@\s]+@cvsu\.edu\.ph" onChange={(e) => { emailEdited.current = true; form.setData('email', e.target.value); }} required />{form.errors.email && <small className="field-error">{form.errors.email}</small>}</label>
                {!isNew && <label><FieldLabel note="Enter the 10-digit Philippine mobile number after +63.">Phone Number</FieldLabel><div className="phone-input"><span>+63</span><input type="tel" inputMode="numeric" autoComplete="tel-national" value={form.data.contact_no} maxLength="10" pattern="9[0-9]{9}" placeholder="9XX XXX XXXX" onChange={(e) => form.setData('contact_no', e.target.value.replace(/\D/g, '').slice(0, 10))} /></div>{form.errors.contact_no && <small className="field-error">{form.errors.contact_no}</small>}</label>}
            </div>
            <div className="form-section-title"><span>02</span><div><strong>Employment details</strong><small>Academic profile, rank, and contract</small></div></div>
            <div className="employment-fields">
                <label><FieldLabel required note="Options are arranged from the lowest to highest completed attainment.">Highest Educational Attainment</FieldLabel><select value={form.data.highest_educational_attainment} onChange={(e) => form.setData('highest_educational_attainment', e.target.value)} required><option value="">Select attainment</option>{attainmentOptions.map((option) => <option key={option}>{option}</option>)}</select>{form.errors.highest_educational_attainment && <small className="field-error">{form.errors.highest_educational_attainment}</small>}</label>
                <label><FieldLabel required note={`Select the first month of CvSU service. Current duration: ${serviceDuration(form.data.service_start_date)}.`}>Service Start Month</FieldLabel><input type="month" placeholder="MM / YYYY" max={new Date().toISOString().slice(0, 7)} value={form.data.service_start_date} onChange={(e) => form.setData('service_start_date', e.target.value)} required />{form.errors.service_start_date && <small className="field-error">{form.errors.service_start_date}</small>}</label>
                <label><FieldLabel required note="Selecting a rank automatically applies its official hourly rate.">Rank</FieldLabel><select value={form.data.faculty_rank_id} onChange={(e) => selectRank(e.target.value)} required><option value="">Select rank</option>{ranks.map((rank) => <option key={rank.id} value={rank.id}>{rank.name}</option>)}</select></label>
                <label><FieldLabel required note="Automatically based on the selected rank and maintained in the Ranks page.">Hourly Rate</FieldLabel><input type="number" min="0" max="999999.99" step="0.01" placeholder="0.00" value={form.data.rate_amount} readOnly aria-readonly="true" required /></label>
                <label><FieldLabel note="Inactive faculty cannot record attendance or be included in payroll.">Status</FieldLabel><select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
            </div>
            <fieldset className="contract-period"><legend>Contract Period</legend><label><FieldLabel note="First calendar day covered by the contract.">Start Date</FieldLabel><input type="date" placeholder="MM / DD / YYYY" value={form.data.contract_start || ''} onChange={(e) => form.setData('contract_start', e.target.value)} /></label><span>to</span><label><FieldLabel note="Last calendar day covered by the contract.">End Date</FieldLabel><input type="date" placeholder="MM / DD / YYYY" min={form.data.contract_start || undefined} value={form.data.contract_end || ''} onChange={(e) => form.setData('contract_end', e.target.value)} /></label></fieldset>
            {!isNew && <><div className="form-section-title"><span>03</span><div><strong>Employment history</strong><small>Previous teaching and professional experience</small></div></div>
            <div className="employment-history-list">
                {form.data.employment_history.map((history, index) => <div className="employment-history-row" key={index}>
                    <span className="history-icon"><BriefcaseBusiness size={18} /></span>
                    <label><FieldLabel required note="Name of the previous school, institution, or employer.">Institution / Employer</FieldLabel><input value={history.employer} onChange={(e) => updateEmploymentHistory(index, 'employer', e.target.value)} required /></label>
                    <label><FieldLabel required note="Position held at this institution.">Position</FieldLabel><input value={history.position} onChange={(e) => updateEmploymentHistory(index, 'position', e.target.value)} required /></label>
                    <label><FieldLabel required note="Month and year this employment began.">From</FieldLabel><input type="month" placeholder="MM / YYYY" value={history.started_on} onChange={(e) => updateEmploymentHistory(index, 'started_on', e.target.value)} required /></label>
                    <label><FieldLabel note="Month and year it ended. Leave empty if this is current employment.">Until</FieldLabel><input type="month" placeholder="MM / YYYY" min={history.started_on || undefined} value={history.ended_on} onChange={(e) => updateEmploymentHistory(index, 'ended_on', e.target.value)} /></label>
                    <button className="history-delete" type="button" title="Remove this employment history" onClick={() => removeEmploymentHistory(index)} aria-label="Remove employment history"><Trash2 size={16} /></button>
                </div>)}
                <button className="add-history-button" type="button" title="Add another previous employment record" onClick={addEmploymentHistory}><Plus size={16} />Add Employment</button>
                {form.errors.employment_history && <small className="field-error">{form.errors.employment_history}</small>}
            </div></>}
            <div className="form-section-title" id="attendance-identifiers"><span>04</span><div><strong>Attendance identifiers</strong><small>Hardware credentials used for time records</small></div></div>
            {flash?.temporaryCredentials && <section className="faculty-temporary-credentials" role="status">
                <KeyRound size={21} />
                <div><strong>Temporary faculty login</strong><span>Email: <b>{flash.temporaryCredentials.email}</b></span><span>Temporary password: <code>{flash.temporaryCredentials.password}</code></span><small>This password is shown only once. Give it securely to the faculty member.</small></div>
                <button type="button" onClick={async () => { await navigator.clipboard.writeText(`Email: ${flash.temporaryCredentials.email}\nTemporary password: ${flash.temporaryCredentials.password}`); setCredentialsCopied(true); }}>{credentialsCopied ? <Check size={17} /> : <Copy size={17} />}{credentialsCopied ? 'Copied' : 'Copy login'}</button>
            </section>}
            {enrollment && <div className={`credential-enrollment-status is-${enrollment.status}`}>
                {['starting', 'pending', 'processing'].includes(enrollment.status) ? <LoaderCircle className="spin" size={18} /> : <CircleCheck size={18} />}
                <span><strong>{enrollment.status === 'completed' ? (enrollment.operation === 'unlink' ? 'Credential unlinked' : 'Registration complete') : enrollment.status === 'failed' ? (enrollment.operation === 'unlink' ? 'Unlink failed' : 'Registration failed') : enrollment.status === 'expired' ? 'Registration expired' : enrollment.status === 'cancelled' ? 'Registration cancelled' : enrollment.operation === 'unlink' ? 'Waiting for fingerprint sensor' : 'Waiting for attendance device'}</strong><small>{enrollment.message || (enrollment.operation === 'unlink' ? 'The fingerprint will be removed from the sensor before it is unlinked.' : enrollment.method === 'rfid' ? 'Tap the RFID card on the RC522.' : 'Follow the fingerprint scanner prompts.')}</small></span>
                {['pending', 'processing'].includes(enrollment.status) && enrollment.operation !== 'unlink' && <button type="button" onClick={cancelHardwareRegistration} aria-label="Cancel registration"><X size={16} /></button>}
            </div>}
            {enrollmentError && <div className="credential-enrollment-error">{enrollmentError}</div>}
            <div className="credential-row">
                <div className="credential-heading"><span className="credential-icon"><Radio size={19} /></span><span><strong>RFID card</strong><small>{form.data.rfid_uid ? 'Registered' : 'Not registered'}</small></span></div>
                <label><FieldLabel note="This value is supplied by the RC522 after hardware registration.">Card UID</FieldLabel><input ref={rfidInput} value={form.data.rfid_uid} readOnly aria-readonly="true" placeholder="Register using the attendance device" /></label>
                <div className="credential-actions">
                    <button className="credential-action" type="button" title="Register or safely replace the RFID card using the attendance device" disabled={isNew || ['starting', 'pending', 'processing'].includes(enrollment?.status)} onClick={() => startHardwareRegistration('rfid')}>{form.data.rfid_uid ? <RefreshCw size={16} /> : <Radio size={16} />}{isNew ? 'Save first' : form.data.rfid_uid ? 'Replace' : 'Register'}</button>
                    {form.data.rfid_uid && <button className="credential-unlink" type="button" title="Permanently unlink this RFID card" onClick={() => unlinkCredential('rfid')} aria-label="Unlink RFID card"><Link2Off size={16} /></button>}
                </div>
            </div>
            <div className="credential-row">
                <div className="credential-heading"><span className="credential-icon"><Fingerprint size={19} /></span><span><strong>Fingerprint</strong><small>{form.data.fingerprint_code ? 'Registered' : 'Not registered'}</small></span></div>
                <label><FieldLabel note="This template slot is assigned automatically by the AS608 sensor.">Template ID</FieldLabel><input ref={fingerprintInput} readOnly aria-readonly="true" placeholder="Register using the attendance device" value={form.data.fingerprint_code} /></label>
                <label><FieldLabel note="Select the finger before starting registration on the AS608 sensor.">Finger</FieldLabel><select disabled={['starting', 'pending', 'processing'].includes(enrollment?.status)} value={form.data.finger_label} onChange={(e) => form.setData('finger_label', e.target.value)}><option value="">Select finger</option><option>Right thumb</option><option>Right index</option><option>Right middle</option><option>Left thumb</option><option>Left index</option><option>Left middle</option></select></label>
                <div className="credential-actions">
                    <button className="credential-action" type="button" title="Register or safely replace the fingerprint using the AS608 sensor" disabled={isNew || !form.data.finger_label || ['starting', 'pending', 'processing'].includes(enrollment?.status)} onClick={() => startHardwareRegistration('fingerprint')}>{form.data.fingerprint_code ? <RefreshCw size={16} /> : <Fingerprint size={16} />}{isNew ? 'Save first' : form.data.fingerprint_code ? 'Replace' : 'Register'}</button>
                    {form.data.fingerprint_code && <button className="credential-unlink" type="button" title="Permanently remove this fingerprint from the sensor and faculty record" onClick={() => unlinkCredential('fingerprint')} aria-label="Unlink fingerprint"><Link2Off size={16} /></button>}
                </div>
            </div>
            <div className="form-actions"><Link href="/employees">Cancel</Link><button type="submit" disabled={form.processing}>{form.processing ? 'Saving...' : isNew ? 'Create & Continue to Device Registration' : 'Save Faculty'}</button></div>
        </form>
    </AppLayout>;
}
