import DateInput from '../../components/DateInput';
import { Link, useForm } from '@inertiajs/react';
import axios from 'axios';
import { BriefcaseBusiness, Check, CircleCheck, CircleHelp, Copy, Fingerprint, KeyRound, Link2Off, LoaderCircle, Nfc, Plus, Radio, RefreshCw, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import { scanRfid } from '../../lib/scanRfid';

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
    const isNew = !employee.id;
    const emailEdited = useRef(Boolean(employee.email));
    const rfidInput = useRef(null);
    const fingerprintInput = useRef(null);
    const [enrollment, setEnrollment] = useState(null);
    const [enrollmentError, setEnrollmentError] = useState('');
    const [nfcPhase, setNfcPhase] = useState('idle');
    const [nfcMessage, setNfcMessage] = useState('');
    const nfcScanner = useRef(null);
    const nfcBusy = nfcPhase !== 'idle';
    useEffect(() => () => nfcScanner.current?.abort(), []);
    const { ask, dialog } = useConfirmDialog();
    const form = useForm({
        employee_no: employee.employee_no || nextEmployeeNumber || '', first_name: employee.first_name || '', middle_name: employee.middle_name || '', last_name: employee.last_name || '', suffix: employee.suffix || '', email: employee.email || '', contact_no: localPhoneNumber(employee.contact_no), highest_educational_attainment: employee.highest_educational_attainment || '', service_start_date: employee.service_start_date?.slice(0, 7) || '', faculty_rank_id: employee.faculty_rank_id || '', status: employee.status || 'active', contract_start: employee.contract_start || '', contract_end: employee.contract_end || '', employment_history: (employee.employment_histories || []).map((history) => ({ ...history, started_on: history.started_on?.slice(0, 7) || '', ended_on: history.ended_on?.slice(0, 7) || '' })), rfid_uid: employee.rfid_cards?.[0]?.rfid_uid || '', fingerprint_code: employee.fingerprint_templates?.[0]?.fingerprint_code || '', finger_label: employee.fingerprint_templates?.[0]?.finger_label || '', rfid_reregister: false, fingerprint_reregister: false,
    });
    const updateName = (field, value) => {
        const updated = { ...form.data, [field]: value };
        form.setData(emailEdited.current ? updated : { ...updated, email: suggestedEmail(updated.first_name, updated.last_name) });
    };
    const submit = (event) => {
        event.preventDefault();
        if (nfcBusy) return;
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
                    if (data.operation === 'unlink') {
                        form.setData(data.method === 'rfid' ? 'rfid_uid' : 'fingerprint_code', '');
                    } else if (data.method === 'rfid') {
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
        if (isNew || nfcBusy || form.processing) return;
        const existingIdentifier = method === 'rfid' ? form.data.rfid_uid : form.data.fingerprint_code;
        const credentialLabel = method === 'rfid' ? 'RFID card' : 'fingerprint';
        if (existingIdentifier && !await ask({
            title: `Replace ${credentialLabel}?`,
            message: `Register a new ${credentialLabel} for this faculty member? The existing credential remains active until the replacement succeeds.`,
            confirmLabel: 'Start Replacement',
            tone: 'warning',
        })) return;
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
        if (nfcBusy || form.processing) return;
        const identifier = method === 'rfid' ? form.data.rfid_uid : form.data.fingerprint_code;
        const credentialLabel = method === 'rfid' ? 'RFID card' : 'fingerprint';
        if (!await ask({
            title: `Unlink ${credentialLabel}?`,
            message: `Permanently remove this ${credentialLabel} from the faculty member? It can no longer be used for attendance after unlinking.`,
            confirmLabel: 'Unlink Credential',
            tone: 'danger',
        })) return;

        setEnrollmentError('');
        try {
            const { data } = await axios.post(`/employees/${employee.id}/credentials/${method}/unlink`, {
                confirmation: 'UNLINK',
                current_identifier: identifier,
            });
            setEnrollment(data);
            if (data.status === 'completed') {
                form.setData(method === 'rfid' ? 'rfid_uid' : 'fingerprint_code', '');
            }
        } catch (error) {
            setEnrollmentError(error.response?.data?.message || `Could not unlink the ${method} credential.`);
        }
    };
    const registerViaNfc = async () => {
        if (isNew || nfcBusy || nfcScanner.current || form.processing || ['starting', 'pending', 'processing'].includes(enrollment?.status)) return;
        const controller = new AbortController();
        nfcScanner.current = controller;
        setNfcPhase('scanning');
        setNfcMessage('Hold the RFID card near the back of this phone.');
        setEnrollmentError('');
        setEnrollment(null);
        try {
            const identifier = await scanRfid({ signal: controller.signal, onReadingError: setNfcMessage });
            if (controller.signal.aborted) return;
            controller.abort(); // Stop reading before showing the confirmation.
            setNfcPhase('confirming');
            setNfcMessage('Card scanned. Confirm the faculty and card below.');
            const previous = form.data.rfid_uid;
            const confirmed = await ask({
                title: previous ? 'Replace RFID card?' : 'Register RFID card?',
                message: `Register card ${identifier} for ${employee.first_name} ${employee.last_name}?${previous && previous !== identifier ? ` This replaces card ${previous}.` : ''}`,
                confirmLabel: previous ? 'Confirm Registration' : 'Register Card',
                tone: previous ? 'warning' : undefined,
            });
            if (!confirmed) { setNfcMessage('Registration cancelled.'); return; }
            setNfcPhase('saving');
            setNfcMessage('Registering RFID card...');
            const { data } = await axios.post(`/employees/${employee.id}/credentials/rfid/nfc`, {
                identifier, current_identifier: previous || null, confirmation: 'REGISTER',
            });
            form.setData('rfid_uid', data.identifier);
            form.setDefaults('rfid_uid', data.identifier);
            setEnrollment(data);
            setNfcMessage('');
        } catch (error) {
            if (error.name !== 'AbortError') {
                setNfcMessage('');
                setEnrollmentError(Object.values(error.response?.data?.errors || {})[0]?.[0] || error.response?.data?.message || error.message || 'Could not register this RFID card.');
            }
        } finally {
            controller.abort();
            nfcScanner.current = null;
            setNfcPhase('idle');
        }
    };
    const selectRank = (rankId) => {
        form.setData({
            ...form.data,
            faculty_rank_id: rankId,
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
            {isNew && <><div className="form-section-title"><span>01</span><div><strong>Personal information</strong><small>Initial faculty identity and institutional email</small></div></div>
            <div className="name-fields">
                <label><FieldLabel required note="Faculty member's legal first name.">First Name</FieldLabel><input value={form.data.first_name} autoComplete="given-name" onChange={(e) => updateName('first_name', e.target.value)} required /></label>
                <label><FieldLabel note="Include the full middle name when available.">Middle Name</FieldLabel><input value={form.data.middle_name} autoComplete="additional-name" onChange={(e) => form.setData('middle_name', e.target.value)} /></label>
                <label><FieldLabel required note="Faculty member's legal family name.">Last Name</FieldLabel><input value={form.data.last_name} autoComplete="family-name" onChange={(e) => updateName('last_name', e.target.value)} required /></label>
                <label><FieldLabel note="Examples: Jr., Sr., II, or III.">Suffix</FieldLabel><input value={form.data.suffix} maxLength="20" placeholder="Jr., III" onChange={(e) => form.setData('suffix', e.target.value)} /></label>
            </div>
            <div className="personal-contact-fields">
                <label><FieldLabel note="Generated system identifier for this faculty record.">Employee Number</FieldLabel><input value={form.data.employee_no} readOnly aria-readonly="true" /></label>
                <label><FieldLabel required note="Use an active institutional address ending in @cvsu.edu.ph.">Email Address</FieldLabel><input type="email" value={form.data.email} autoComplete="email" placeholder="name@cvsu.edu.ph" pattern="[^@\s]+@cvsu\.edu\.ph" onChange={(e) => { emailEdited.current = true; form.setData('email', e.target.value); }} required />{form.errors.email && <small className="field-error">{form.errors.email}</small>}</label>
            </div>
            </>}
            <div className="form-section-title"><span>{isNew ? '02' : '01'}</span><div><strong>Official employment details</strong><small>Rank, status, and contract managed by the administrator</small></div></div>
            <div className="employment-fields">
                {!isNew && <label><FieldLabel note="Generated system identifier for this faculty record.">Employee Number</FieldLabel><input value={form.data.employee_no} readOnly aria-readonly="true" /></label>}
                <label><FieldLabel required note="Faculty academic rank.">Rank</FieldLabel><select value={form.data.faculty_rank_id} onChange={(e) => selectRank(e.target.value)} required><option value="">Select rank</option>{ranks.map((rank) => <option key={rank.id} value={rank.id}>{rank.name}</option>)}</select></label>
                <label><FieldLabel note="Inactive faculty keep their schedules and records, but cannot record attendance. You can reactivate them later.">Status</FieldLabel><select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
            </div>
            <fieldset className="contract-period"><legend>Contract Period</legend><label><FieldLabel note="First calendar day covered by the contract.">Start Date</FieldLabel><DateInput placeholder="MM / DD / YYYY" value={form.data.contract_start || ''} onChange={(e) => form.setData('contract_start', e.target.value)} /></label><span>to</span><label><FieldLabel note="Last calendar day covered by the contract.">End Date</FieldLabel><DateInput placeholder="MM / DD / YYYY" min={form.data.contract_start || undefined} value={form.data.contract_end || ''} onChange={(e) => form.setData('contract_end', e.target.value)} /></label></fieldset>
            {false && !isNew && <><div className="form-section-title"><span>03</span><div><strong>Employment history</strong><small>Previous teaching and professional experience</small></div></div>
            <div className="employment-history-list">
                {form.data.employment_history.map((history, index) => <div className="employment-history-row" key={index}>
                    <span className="history-icon"><BriefcaseBusiness size={18} /></span>
                    <label><FieldLabel required note="Name of the previous school, institution, or employer.">Institution / Employer</FieldLabel><input value={history.employer} onChange={(e) => updateEmploymentHistory(index, 'employer', e.target.value)} required /></label>
                    <label><FieldLabel required note="Position held at this institution.">Position</FieldLabel><input value={history.position} onChange={(e) => updateEmploymentHistory(index, 'position', e.target.value)} required /></label>
                    <label><FieldLabel required note="Month and year this employment began.">From</FieldLabel><input type="month" placeholder="MM / YYYY" value={history.started_on} onChange={(e) => updateEmploymentHistory(index, 'started_on', e.target.value)} required /></label>
                    <label><FieldLabel note="Month and year it ended. Leave empty if this is current employment.">Until</FieldLabel><input type="month" placeholder="MM / YYYY" min={history.started_on || undefined} value={history.ended_on} onChange={(e) => updateEmploymentHistory(index, 'ended_on', e.target.value)} /></label>
                    <button className="history-delete ui-control ui-action ui-danger" type="button" title="Remove this employment history" onClick={() => removeEmploymentHistory(index)} aria-label="Remove employment history"><Trash2 size={16} /></button>
                </div>)}
                <button className="add-history-button ui-control ui-action ui-primary" type="button" title="Add another previous employment record" onClick={addEmploymentHistory}><Plus size={16} />Add Employment</button>
                {form.errors.employment_history && <small className="field-error">{form.errors.employment_history}</small>}
            </div></>}
            {!isNew && <p className="form-ownership-note">Personal information, academic profile, and employment history are maintained by the faculty in My Profile.</p>}
            <div className="form-section-title" id="attendance-identifiers"><span>{isNew ? '03' : '02'}</span><div><strong>Attendance identifiers</strong><small>RFID and fingerprint registration</small></div></div>
            {enrollment && <div className={`credential-enrollment-status is-${enrollment.status}`}>
                {['starting', 'pending', 'processing'].includes(enrollment.status) ? <LoaderCircle className="spin" size={18} /> : <CircleCheck size={18} />}
                <span><strong>{enrollment.status === 'completed' ? (enrollment.operation === 'unlink' ? 'Credential unlinked' : 'Registration complete') : enrollment.status === 'failed' ? (enrollment.operation === 'unlink' ? 'Unlink failed' : 'Registration failed') : enrollment.status === 'expired' ? 'Registration expired' : enrollment.status === 'cancelled' ? 'Registration cancelled' : enrollment.operation === 'unlink' ? 'Waiting for fingerprint sensor' : 'Waiting for attendance device'}</strong><small>{enrollment.message || (enrollment.operation === 'unlink' ? 'The fingerprint will be removed from the sensor before it is unlinked.' : enrollment.method === 'rfid' ? 'Tap the RFID card on the RC522.' : 'Follow the fingerprint scanner prompts.')}</small></span>
                {['pending', 'processing'].includes(enrollment.status) && enrollment.operation !== 'unlink' && <button className="ui-control ui-action ui-secondary" type="button" onClick={cancelHardwareRegistration} aria-label="Cancel registration"><X size={16} /></button>}
            </div>}
            {enrollmentError && <div className="credential-enrollment-error">{enrollmentError}</div>}
            {nfcMessage && <div className="credential-nfc-message" role="status">{nfcMessage}</div>}
            <div className="credential-row credential-row--rfid">
                <div className="credential-heading"><span className="credential-icon"><Radio size={19} /></span><span><strong>RFID card</strong><small>{form.data.rfid_uid ? 'Registered' : 'Not registered'}</small></span></div>
                <label><FieldLabel note="Read from the RFID card using the attendance device or cellphone NFC.">Card UID</FieldLabel><input ref={rfidInput} value={form.data.rfid_uid} readOnly aria-readonly="true" placeholder="Register using hardware or NFC" /></label>
                <div className="credential-actions">
                    <button className="credential-action ui-control ui-action ui-secondary" type="button" title="Register or safely replace the RFID card using the attendance device" disabled={isNew || nfcBusy || form.processing || ['starting', 'pending', 'processing'].includes(enrollment?.status)} onClick={() => startHardwareRegistration('rfid')}><Radio size={16} />{isNew ? 'Save first' : 'Register'}</button>
                    <button className="credential-action ui-control ui-action ui-secondary" type="button" title={isNew ? 'Save the faculty record first' : 'Register RFID using cellphone NFC'} disabled={isNew || form.processing || ['confirming', 'saving'].includes(nfcPhase) || ['starting', 'pending', 'processing'].includes(enrollment?.status)} onClick={nfcPhase === 'scanning' ? () => { nfcScanner.current?.abort(); setNfcMessage('Scan stopped.'); } : registerViaNfc}><Nfc size={16} />{nfcPhase === 'scanning' ? 'Stop Scan' : nfcPhase === 'saving' ? 'Registering...' : 'Register via NFC'}</button>
                    {form.data.rfid_uid && <button className="credential-unlink ui-control ui-action ui-danger" type="button" title="Permanently unlink this RFID card" disabled={nfcBusy || form.processing} onClick={() => unlinkCredential('rfid')} aria-label="Unlink RFID card"><Link2Off size={16} /></button>}
                </div>
            </div>
            <div className="credential-row credential-row--fingerprint">
                <div className="credential-heading"><span className="credential-icon"><Fingerprint size={19} /></span><span><strong>Fingerprint</strong><small>{form.data.fingerprint_code ? 'Registered' : 'Not registered'}</small></span></div>
                <label><FieldLabel note="This template slot is assigned automatically by the AS608 sensor.">Template ID</FieldLabel><input ref={fingerprintInput} readOnly aria-readonly="true" placeholder="Register using the attendance device" value={form.data.fingerprint_code} /></label>
                <label><FieldLabel note="Select the finger before starting registration on the AS608 sensor.">Finger</FieldLabel><select disabled={nfcBusy || form.processing || ['starting', 'pending', 'processing'].includes(enrollment?.status)} value={form.data.finger_label} onChange={(e) => form.setData('finger_label', e.target.value)}><option value="">Select finger</option><option>Right thumb</option><option>Right index</option><option>Right middle</option><option>Left thumb</option><option>Left index</option><option>Left middle</option></select></label>
                <div className="credential-actions">
                    <button className="credential-action ui-control ui-action ui-secondary" type="button" title="Register or safely replace the fingerprint using the AS608 sensor" disabled={isNew || nfcBusy || form.processing || !form.data.finger_label || ['starting', 'pending', 'processing'].includes(enrollment?.status)} onClick={() => startHardwareRegistration('fingerprint')}>{form.data.fingerprint_code ? <RefreshCw size={16} /> : <Fingerprint size={16} />}{isNew ? 'Save first' : form.data.fingerprint_code ? 'Replace' : 'Register'}</button>
                    {form.data.fingerprint_code && <button className="credential-unlink ui-control ui-action ui-danger" type="button" title="Permanently remove this fingerprint from the sensor and faculty record" disabled={nfcBusy || form.processing} onClick={() => unlinkCredential('fingerprint')} aria-label="Unlink fingerprint"><Link2Off size={16} /></button>}
                </div>
            </div>
            <div className="form-actions"><Link className="ui-control ui-action ui-secondary" href="/employees">Cancel</Link><button className="ui-control ui-action ui-primary" type="submit" disabled={form.processing || nfcBusy}>{form.processing ? 'Saving...' : isNew ? 'Create & Continue to Device Registration' : 'Save Faculty'}</button></div>
        </form>
        {dialog}
    </AppLayout>;
}
