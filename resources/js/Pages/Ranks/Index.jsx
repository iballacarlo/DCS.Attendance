import { useForm } from '@inertiajs/react';
import { Save } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import { money } from '../../lib/format';

function RankRow({ rank }) {
    const form = useForm({ rate_amount: rank.rate_amount });
    const submit = (event) => { event.preventDefault(); form.put(`/faculty-ranks/${rank.id}`); };
    return <tr><td><strong>{rank.name}</strong></td><td>{rank.salary_grade || '-'}</td><td>{rank.monthly_salary ? money(rank.monthly_salary) : '-'}</td><td><form className="inline-form rank-rate-form" onSubmit={submit}><span className="rate-input"><span>PHP</span><input aria-label={`${rank.name} hourly rate`} type="number" min="0" step="0.01" value={form.data.rate_amount} onChange={(e) => form.setData('rate_amount', e.target.value)} /></span><span className="rate-unit">/ hour</span><button className="primary-table-action ui-control ui-action ui-primary" type="submit" disabled={form.processing}><Save size={16} />{form.processing ? 'Saving' : 'Save Rate'}</button></form></td><td>{rank.employees_count}</td></tr>;
}
export default function RanksIndex({ ranks }) {
    return <AppLayout title="Ranks" subtitle="Set one hourly rate per rank. Faculty with the same rank use the same rate.">
        <div className="panel"><div className="panel-heading"><h2>Official Ranks</h2></div><div className="table-wrap"><table><thead><tr><th>Rank</th><th>Salary Grade</th><th>Monthly Reference</th><th>Hourly Rate</th><th>Faculty Assigned</th></tr></thead><tbody>{ranks.map((rank) => <RankRow key={rank.id} rank={rank} />)}{ranks.length === 0 && <tr><td colSpan="5" className="empty-row">No faculty ranks found.</td></tr>}</tbody></table></div></div>
    </AppLayout>;
}
