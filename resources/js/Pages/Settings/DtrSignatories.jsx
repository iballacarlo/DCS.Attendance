import '../../../css/dtrSignatories.css';
import { formatDate } from '../../lib/format';
import DateInput from '../../components/DateInput';
import { router, useForm } from '@inertiajs/react';
import { Edit3, Save, Trash2, UserRoundCheck } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import SettingsTabs from '../../components/SettingsTabs';
import useConfirmDialog from '../../hooks/useConfirmDialog';

const blank = { name: '', effective_from: '', effective_to: '' };

export default function DtrSignatories({ signatories }) {
    const [editing, setEditing] = useState(null);
    const form = useForm(blank);
    const { ask, dialog } = useConfirmDialog();
    const edit = (item) => {
        setEditing(item.id);
        form.setData({ name: item.name, effective_from: item.effective_from.slice(0, 10), effective_to: item.effective_to?.slice(0, 10) || '' });
    };
    const reset = () => { setEditing(null); form.setData(blank); form.clearErrors(); };
    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: reset };
        editing ? form.put(`/settings/dtr-signatories/${editing}`, options) : form.post('/settings/dtr-signatories', options);
    };
    const remove = async (item) => {
        if (await ask({ title: 'Delete dean record?', message: `Remove ${item.name} from the dean history? Historical DTRs within this period will no longer show this signatory.`, confirmLabel: 'Delete dean', tone: 'danger' })) {
            router.delete(`/settings/dtr-signatories/${item.id}`, { preserveScroll: true });
        }
    };

    return <AppLayout title="Dean Management" subtitle="Manage the dean and their effective dates.">
        <SettingsTabs />
        <section className="signatory-settings-layout">
            <form className="panel calendar-signatory-form" onSubmit={submit}>
                <div className="panel-heading"><div><span className="bento-eyebrow">Academic Administration</span><h2>{editing ? 'Edit dean' : 'Add dean'}</h2></div><UserRoundCheck size={22} /></div>
                <label>Dean's name<input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />{form.errors.name && <small className="field-error">{form.errors.name}</small>}</label>
                <div className="form-pair"><label>Effective from<DateInput value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} required /></label><label>Effective to<DateInput min={form.data.effective_from || undefined} value={form.data.effective_to} onChange={(e) => form.setData('effective_to', e.target.value)} /></label></div>
                <div className="form-actions">{editing && <button type="button" className="secondary ui-control ui-action ui-secondary" onClick={reset}>Cancel</button>}<button className="ui-control ui-action ui-primary" disabled={form.processing}><Save size={17} />Save dean</button></div>
            </form>
            <section className="panel signatory-history">
                <div className="panel-heading"><h2>Dean History</h2><span>{signatories.length} {signatories.length === 1 ? 'record' : 'records'}</span></div>
                {signatories.length ? signatories.map((item) => <article className="signatory-item" key={item.id}>
                    <div className="dtr-signatory-details">
                        <h3>{item.name}</h3>
                        <p className="dtr-signatory-role">Dean</p>
                        <p className="dtr-signatory-period">{formatDate(item.effective_from)} to {item.effective_to ? formatDate(item.effective_to) : 'present'}</p>
                    </div>
                    <div className="row-actions dtr-signatory-actions">
                        <button className="ui-control ui-action ui-secondary" type="button" aria-label={`Edit ${item.name}`} onClick={() => edit(item)}><Edit3 size={16} aria-hidden="true" /><span>Edit</span></button>
                        <button className="ui-control ui-action ui-danger" type="button" aria-label={`Delete ${item.name}`} onClick={() => remove(item)}><Trash2 size={16} aria-hidden="true" /><span>Delete</span></button>
                    </div>
                </article>) : <p className="empty-row">No dean has been added yet.</p>}
            </section>
        </section>
        {dialog}
    </AppLayout>;
}
