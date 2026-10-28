import { useState } from 'react';

const measures = { on_time: 'On time & full class', late: 'Late arrivals', undertime: 'Undertime', missed: 'Absent' };

export default function ClassPunctuality({ faculty, subjects, onSelect }) {
    const [group, setGroup] = useState('faculty');
    const [measure, setMeasure] = useState('late');
    const [page, setPage] = useState(1);
    const rows = [...(group === 'faculty' ? faculty : subjects)].filter((row) => row.ended > 0)
        .sort((a, b) => (b[measure] || 0) - (a[measure] || 0) || b.ended - a.ended || a.label.localeCompare(b.label));
    const pages = Math.max(1, Math.ceil(rows.length / 5));
    const current = Math.min(page, pages);
    const maximum = Math.max(1, ...rows.map((row) => row[measure] || 0));
    return <section className="panel ca-breakdown">
        <div className="ca-heading"><h2>Faculty attendance monitoring</h2><div className="ca-switch" role="group" aria-label="Group attendance by">{['faculty', 'subject'].map((value) => <button type="button" key={value} aria-pressed={group === value} onClick={() => { setGroup(value); setPage(1); }}>By {value}</button>)}</div></div>
        <label className="ca-measure">Compare<select value={measure} onChange={(event) => { setMeasure(event.target.value); setPage(1); }}>{Object.entries(measures).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
        {rows.length ? <div className="ca-breakdown-list">{rows.slice((current - 1) * 5, current * 5).map((row) => <div className="ca-breakdown-row" key={row.value ?? row.label}>
            <div className="ca-breakdown-heading">{onSelect && row.value != null && row.value !== '' ? <button type="button" className="ca-drilldown" onClick={() => onSelect({ [group === 'faculty' ? 'employee_id' : 'subject']: row.value })}>{row.label} <span aria-hidden="true">↗</span></button> : <strong>{row.label}</strong>}<span>{row[measure] || 0} / {row.ended} classes · {measures[measure]}</span></div>
            <div className={`ca-punctuality-track is-${measure}`} role="img" aria-label={`${row.label}: ${row[measure] || 0} ${measures[measure].toLowerCase()} out of ${row.ended} finished classes`}><span style={{ width: `${(row[measure] || 0) / maximum * 100}%` }} /></div>
            <div className="ca-row-counts"><span>On time: {row.on_time || 0}</span><span>Late: {row.late || 0}</span><span>Undertime: {row.undertime || 0}</span><span>Absent: {row.missed || 0}</span></div>
            <div className="ca-row-counts"><span>{row.total_late_minutes || 0} min late</span><span>{row.total_undertime_minutes || 0} min undertime</span></div>
        </div>)}</div> : <div className="ca-empty"><strong>No finished classes to compare</strong></div>}
        {pages > 1 && <nav className="session-pagination" aria-label="Faculty monitoring pages"><button type="button" disabled={current === 1} onClick={() => setPage(current - 1)}>Previous</button><span>{current} / {pages}</span><button type="button" disabled={current === pages} onClick={() => setPage(current + 1)}>Next</button></nav>}
    </section>;
}
