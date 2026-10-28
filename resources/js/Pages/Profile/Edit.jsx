import { formatDate } from '../../lib/format';
import { useForm } from '@inertiajs/react';
import { BriefcaseBusiness, GraduationCap, KeyRound, Plus, Save, Trash2, UserRound } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import { money } from '../../lib/format';

const attainmentOptions = [
    "Bachelor's Degree",
    'Post-Baccalaureate Certificate or Diploma',
    "Master's Degree Units",
    "Master's Degree",
    'Doctorate Degree Units',
    'Doctorate Degree',
    'Postdoctoral Studies',
];

function localPhoneNumber(value) {
    const digits = String(value || '').replace(/\D/g, '');
    if (digits.startsWith('63')) return digits.slice(2, 12);
    if (digits.startsWith('0')) return digits.slice(1, 11);
    return digits.slice(0, 10);
}

function ErrorMessage({ message }) {
    return message ? <small className="field-error">{message}</small> : null;
}

export default function ProfileEdit({ profileUser }) {
    const employee = profileUser.employee;
    const form = useForm({
        name: profileUser.name || '',
        first_name: employee?.first_name || '',
        middle_name: employee?.middle_name || '',
        last_name: employee?.last_name || '',
        suffix: employee?.suffix || '',
        email: profileUser.email || employee?.email || '',
        contact_no: localPhoneNumber(employee?.contact_no),
        highest_educational_attainment: employee?.highest_educational_attainment || '',
        service_start_date: employee?.service_start_date?.slice(0, 7) || '',
        tin_no: employee?.tin_no || '',
        gsis_no: employee?.gsis_no || '',
        pag_ibig_no: employee?.pag_ibig_no || '',
        philhealth_no: employee?.philhealth_no || '',
        employment_history: (employee?.employment_histories || []).map((history) => ({
            employer: history.employer || '',
            position: history.position || '',
            started_on: history.started_on?.slice(0, 7) || '',
            ended_on: history.ended_on?.slice(0, 7) || '',
        })),
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            email: data.email.trim().toLowerCase(),
            contact_no: employee && data.contact_no ? `+63${data.contact_no}` : null,
            service_start_date: employee && data.service_start_date ? `${data.service_start_date}-01` : null,
            employment_history: employee ? data.employment_history.map((history) => ({
                ...history,
                started_on: `${history.started_on}-01`,
                ended_on: history.ended_on ? `${history.ended_on}-01` : null,
            })) : [],
        }));
        form.put('/profile', {
            preserveScroll: true,
            onSuccess: () => form.reset('current_password', 'password', 'password_confirmation'),
        });
    };
    const addEmployment = () => form.setData('employment_history', [...form.data.employment_history, { employer: '', position: '', started_on: '', ended_on: '' }]);
    const updateEmployment = (index, field, value) => form.setData('employment_history', form.data.employment_history.map((history, historyIndex) => historyIndex === index ? { ...history, [field]: value } : history));
    const removeEmployment = (index) => form.setData('employment_history', form.data.employment_history.filter((_, historyIndex) => historyIndex !== index));

    return <AppLayout title="My Profile" subtitle="Review and maintain your personal account information.">
        <form className="panel form-grid profile-form" onSubmit={submit}>
            <div className="form-section-title"><span><UserRound size={17} /></span><div><strong>Personal information</strong><small>Information displayed on your account</small></div></div>
            {employee ? <>
                <div className="name-fields">
                    <label>First Name<input autoComplete="given-name" value={form.data.first_name} onChange={(event) => form.setData('first_name', event.target.value)} required /><ErrorMessage message={form.errors.first_name} /></label>
                    <label>Middle Name<input autoComplete="additional-name" value={form.data.middle_name} onChange={(event) => form.setData('middle_name', event.target.value)} /></label>
                    <label>Last Name<input autoComplete="family-name" value={form.data.last_name} onChange={(event) => form.setData('last_name', event.target.value)} required /><ErrorMessage message={form.errors.last_name} /></label>
                    <label>Suffix<input maxLength="20" placeholder="Jr., III" value={form.data.suffix} onChange={(event) => form.setData('suffix', event.target.value)} /></label>
                </div>
                <div className="profile-contact-fields">
                    <label>Institutional Email<input type="email" autoComplete="email" placeholder="name@cvsu.edu.ph" pattern="[^@\s]+@cvsu\.edu\.ph" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} required /><ErrorMessage message={form.errors.email} /></label>
                    <label>Phone Number<div className="phone-input"><span>+63</span><input type="tel" inputMode="numeric" autoComplete="tel-national" maxLength="10" pattern="9[0-9]{9}" placeholder="9XX XXX XXXX" value={form.data.contact_no} onChange={(event) => form.setData('contact_no', event.target.value.replace(/\D/g, '').slice(0, 10))} /></div><ErrorMessage message={form.errors.contact_no} /></label>
                </div>
                <div className="profile-government-fields">
                    <label>TIN Number<input maxLength="30" placeholder="000-000-000-000" value={form.data.tin_no} onChange={(event) => form.setData('tin_no', event.target.value)} /><ErrorMessage message={form.errors.tin_no} /></label>
                    <label>GSIS Number<input maxLength="30" placeholder="Enter GSIS number" value={form.data.gsis_no} onChange={(event) => form.setData('gsis_no', event.target.value)} /><ErrorMessage message={form.errors.gsis_no} /></label>
                    <label>Pag-IBIG Number<input maxLength="30" placeholder="0000-0000-0000" value={form.data.pag_ibig_no} onChange={(event) => form.setData('pag_ibig_no', event.target.value)} /><ErrorMessage message={form.errors.pag_ibig_no} /></label>
                    <label>PhilHealth Number<input maxLength="30" placeholder="00-000000000-0" value={form.data.philhealth_no} onChange={(event) => form.setData('philhealth_no', event.target.value)} /><ErrorMessage message={form.errors.philhealth_no} /></label>
                </div>
                <div className="form-section-title"><span><GraduationCap size={17} /></span><div><strong>Professional information</strong><small>Complete your educational and service profile</small></div></div>
                <div className="profile-contact-fields">
                    <label>Highest Educational Attainment<select value={form.data.highest_educational_attainment} onChange={(event) => form.setData('highest_educational_attainment', event.target.value)}><option value="">Select attainment</option>{attainmentOptions.map((option) => <option key={option}>{option}</option>)}</select><ErrorMessage message={form.errors.highest_educational_attainment} /></label>
                    <label>Service Start Month<input type="month" max={new Date().toISOString().slice(0, 7)} value={form.data.service_start_date} onChange={(event) => form.setData('service_start_date', event.target.value)} /><ErrorMessage message={form.errors.service_start_date} /></label>
                </div>
                <div className="profile-employment-history">
                    {form.data.employment_history.map((history, index) => <div className="profile-history-row" key={index}>
                        <label>Institution / Employer<input value={history.employer} onChange={(event) => updateEmployment(index, 'employer', event.target.value)} required /></label>
                        <label>Position<input value={history.position} onChange={(event) => updateEmployment(index, 'position', event.target.value)} required /></label>
                        <label>From<input type="month" value={history.started_on} onChange={(event) => updateEmployment(index, 'started_on', event.target.value)} required /></label>
                        <label>Until<input type="month" min={history.started_on || undefined} value={history.ended_on} onChange={(event) => updateEmployment(index, 'ended_on', event.target.value)} /></label>
                        <button className="history-delete ui-control ui-action ui-danger" type="button" onClick={() => removeEmployment(index)} title="Remove employment" aria-label="Remove employment"><Trash2 size={16} /></button>
                    </div>)}
                    <button className="add-history-button ui-control ui-action ui-primary" type="button" onClick={addEmployment}><Plus size={16} />Add Employment</button>
                    <ErrorMessage message={form.errors.employment_history} />
                </div>
                <div className="form-section-title"><span><BriefcaseBusiness size={17} /></span><div><strong>Employment details</strong><small>Managed by the system administrator</small></div></div>
                <div className="profile-employment-grid">
                    <label>Employee Number<input value={employee.employee_no || '-'} readOnly /></label>
                    <label>Rank<input value={employee.faculty_rank?.name || '-'} readOnly /></label>
                    <label>Employment Status<input value={employee.status || '-'} readOnly /></label>
                    <label>Contract Start<input value={formatDate(employee.contract_start)} readOnly /></label>
                    <label>Contract End<input value={formatDate(employee.contract_end)} readOnly /></label>
                </div>
            </> : <div className="profile-contact-fields">
                <label>Display Name<input autoComplete="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /><ErrorMessage message={form.errors.name} /></label>
                <label>Email Address<input type="email" autoComplete="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} required /><ErrorMessage message={form.errors.email} /></label>
            </div>}

            <div className="form-section-title"><span><KeyRound size={17} /></span><div><strong>Account security</strong><small>Leave these fields blank to keep your current password</small></div></div>
            <div className="profile-security-grid">
                <label>Current Password<input type="password" autoComplete="off" placeholder="Enter current password" value={form.data.current_password} onChange={(event) => form.setData('current_password', event.target.value)} /><ErrorMessage message={form.errors.current_password} /></label>
                <label>New Password<input type="password" autoComplete="new-password" minLength="12" maxLength="72" placeholder="Minimum 12 characters" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} /><ErrorMessage message={form.errors.password} /></label>
                <label>Confirm New Password<input type="password" autoComplete="new-password" minLength="12" maxLength="72" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} /></label>
            </div>
            <div className="form-actions"><button className="ui-control ui-action ui-primary" type="submit" disabled={form.processing}><Save size={17} />{form.processing ? 'Saving...' : 'Save Changes'}</button></div>
        </form>
    </AppLayout>;
}