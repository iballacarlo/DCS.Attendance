import AppLayout from '../../Layouts/AppLayout';

export default function RanksIndex({ ranks }) {
    return <AppLayout title="Faculty Ranks" subtitle="Academic ranks used in faculty profiles."><section className="panel"><div className="table-wrap"><table><thead><tr><th>Rank</th><th>Assigned faculty</th><th>Status</th></tr></thead><tbody>{ranks.map((rank) => <tr key={rank.id}><td>{rank.name}</td><td>{rank.employees_count}</td><td>{rank.is_active ? 'Active' : 'Inactive'}</td></tr>)}</tbody></table></div></section></AppLayout>;
}
