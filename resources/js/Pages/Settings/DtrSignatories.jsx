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
        if (await ask({ title: 'Delete DTR signatory?', message: `Remove ${item.name} from the signatory history? Historical DTRs within this period will no longer show this signatory.`, confirmLabel: 'Delete Signatory', tone: 'danger' })) {
            router.delete(`/settings/dtr-signatories/${item.id}`, { preserveScroll: true });
        }
    };

    return <AppLayout title="DTR Signatory" subtitle="Maintain the authorized in charge printed on DTR certifications.">
        <SettingsTabs />
        <section className="signatory-settings-layout">
            <form className="panel calendar-signatory-form" onSubmit={submit}>
                <div className="panel-heading"><div><span className="bento-eyebrow">Certification</span><h2>{editing ? 'Edit signatory period' : 'Add DTR signatory'}</h2></div><UserRoundCheck size={22} /></div>
                <label>Name<input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />{form.errors.name && <small className="field-error">{form.errors.name}</small>}</label>
                <div className="form-pair"><label>Effective from<input type="date" value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} required /></label><label>Effective to<input type="date" min={form.data.effective_from || undefined} value={form.data.effective_to} onChange={(e) => form.setData('effective_to', e.target.value)} /></label></div>
                <p className="form-note">Leave “Effective to” blank for the current signatory. Effective periods cannot overlap.</p>
                <div className="form-actions">{editing && <button type="button" className="secondary" onClick={reset}>Cancel</button>}<button disabled={form.processing}><Save size={17} />Save Signatory</button></div>
            </form>
            <section className="panel signatory-history"><div className="panel-heading"><h2>Signatory History</h2><span>{signatories.length} record(s)</span></div>{signatories.length ? signatories.map((item) => <article className="signatory-item" key={item.id}><div><strong>{item.name}</strong><span>In Charge</span><small>{item.effective_from.slice(0, 10)} to {item.effective_to?.slice(0, 10) || 'present'}</small></div><div className="row-actions"><button type="button" title="Edit" onClick={() => edit(item)}><Edit3 size={16} /></button><button type="button" title="Delete" onClick={() => remove(item)}><Trash2 size={16} /></button></div></article>) : <p className="empty-row">No DTR signatory has been configured.</p>}</section>
        </section>
        {dialog}
    </AppLayout>;
}
