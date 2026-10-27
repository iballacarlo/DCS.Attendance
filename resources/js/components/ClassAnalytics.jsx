import { useState } from 'react';
import { CalendarCheck2, CheckCircle2, Clock3, ScanLine } from 'lucide-react';
import { attendanceOverview, attendanceStatuses, attendanceTrend } from '../lib/classAnalytics';

function Legend() {
    return <div className="ca-legend">{attendanceStatuses.map((status) => <span key={status.key}><i className={`ca-dot is-${status.tone}`} />{status.label}</span>)}</div>;
}

function StatusSegments({ row, scale }) {
    return attendanceStatuses.map((status) => row[status.key] > 0 && <span key={status.key} className={`ca-segment is-${status.tone}`} style={{ '--segment-size': `${row[status.key] / scale * 100}%` }} />);
}

function CompletionRing({ summary, overview, onStatusSelect, selectedStatus }) {
    let offset = 0;
    return <div className="ca-ring-layout">
        <svg className="ca-ring" viewBox="0 0 180 180" role="img" aria-label={overview.due ? `${overview.rate}% of finished classes have complete attendance records` : 'No finished classes to measure'}>
            <circle className="ca-ring-track" cx="90" cy="90" r="70" fill="none" strokeWidth="16" />
            {overview.due > 0 && attendanceStatuses.map((status) => {
                const size = Number(summary[status.key] || 0) / overview.due * 100;
                const start = offset; offset += size;
                return size > 0 && <circle key={status.key} className={`ca-ring-segment is-${status.tone}`} role={onStatusSelect ? 'button' : undefined} tabIndex={onStatusSelect ? 0 : undefined} aria-label={`View ${summary[status.key]} ${status.label.toLowerCase()}`} onClick={() => onStatusSelect?.(status.key)} onKeyDown={(e) => { if (onStatusSelect && ['Enter', ' '].includes(e.key)) { e.preventDefault(); onStatusSelect(status.key); } }} cx="90" cy="90" r="70" fill="none" strokeWidth="16" pathLength="100" strokeDasharray={`${size} ${100 - size}`} strokeDashoffset={-start} transform="rotate(-90 90 90)" />;
            })}
            <text className="ca-ring-value" x="90" y="87" textAnchor="middle">{overview.rate == null ? '—' : `${overview.rate}%`}</text>
            <text className="ca-ring-label" x="90" y="108" textAnchor="middle">complete</text>
        </svg>
        <div className="ca-ring-key">{attendanceStatuses.map((status) => <div key={status.key} role={onStatusSelect ? 'button' : undefined} tabIndex={onStatusSelect ? 0 : undefined} aria-pressed={onStatusSelect ? selectedStatus === status.key : undefined} onClick={() => onStatusSelect?.(status.key)} onKeyDown={(e) => { if (onStatusSelect && ['Enter', ' '].includes(e.key)) { e.preventDefault(); onStatusSelect(status.key); } }}><i className={`ca-dot is-${status.tone}`} /><span>{status.label}</span><strong>{summary[status.key]}</strong></div>)}</div>
    </div>;
}

function DailyTrend({ trend, onSelect }) {
    const { unit, rows } = attendanceTrend(trend);
    const maximum = Math.max(1, ...rows.map((r) => r.ended));
    const hasFinishedClasses = rows.some((r) => r.ended > 0);
    return <section className="panel ca-trend">
        <div className="ca-heading"><div><h2>Attendance over time</h2><span className="ca-chart-unit">{unit === 'day' ? 'Daily' : unit === 'week' ? 'Weekly' : 'Monthly'}</span></div><span className="ca-chart-unit">Number of classes</span></div>
        {hasFinishedClasses ? <div className="ca-trend-chart">
            <div className="ca-y-axis" aria-hidden="true"><span>{maximum}</span><span>{maximum > 1 ? Math.floor(maximum / 2) : ''}</span><span>0</span></div>
            <div className="ca-trend-scroll"><div className="ca-columns" style={{ gridTemplateColumns: `repeat(${rows.length}, minmax(44px, 1fr))` }}>{rows.map((row) => {
                const description = `${row.label}: ${row.ended} finished classes, ${row.complete} complete, ${row.incomplete} missing IN or OUT, ${row.missed} without taps`;
                const choose = () => onSelect?.({ start_date: row.start_date, end_date: row.end_date });
                return <div className="ca-column" key={row.key} role={onSelect ? 'button' : 'img'} tabIndex={0} aria-label={`${description}${onSelect ? '. Select to filter this period.' : ''}`} title={description} onClick={choose} onKeyDown={(e) => { if (onSelect && ['Enter', ' '].includes(e.key)) { e.preventDefault(); choose(); } }}><div className="ca-column-plot" style={{ '--bar-height': `${row.ended / maximum * 140}px` }}><span className="ca-column-count">{row.ended}</span><div className="ca-column-bar"><StatusSegments row={row} scale={row.ended || 1} /></div></div><span className="ca-column-date">{row.label}</span></div>;
            })}</div></div>
        </div> : <div className="ca-empty"><CalendarCheck2 size={28} /><strong>No finished classes in this period</strong></div>}
        <Legend />
    </section>;
}

function Breakdown({ faculty, subjects, onSelect }) {
    const [group, setGroup] = useState('faculty');
    const [expanded, setExpanded] = useState(false);
    const rows = [...(group === 'faculty' ? faculty : subjects)].filter((row) => row.ended > 0)
        .sort((a, b) => (b.incomplete + b.missed) - (a.incomplete + a.missed) || b.ended - a.ended || a.label.localeCompare(b.label));
    const maximum = Math.max(1, ...rows.map((r) => r.ended));
    const visible = expanded ? rows : rows.slice(0, 5);
    return <section className="panel ca-breakdown">
        <div className="ca-heading"><div><h2>Where are records missing?</h2></div><div className="ca-switch" role="group" aria-label="Group attendance by">{['faculty', 'subject'].map((value) => <button type="button" key={value} aria-pressed={group === value} onClick={() => { setGroup(value); setExpanded(false); }}>By {value}</button>)}</div></div>
        {visible.length ? <div className="ca-breakdown-list">{visible.map((row, index) => <div className="ca-breakdown-row" key={`${row.label}-${index}`}>
            <div className="ca-breakdown-heading">{onSelect && row.value != null && row.value !== '' ? <button type="button" className="ca-drilldown" onClick={() => onSelect({ [group === 'faculty' ? 'employee_id' : 'subject']: row.value })}>{row.label} <span aria-hidden="true">↗</span></button> : <strong>{row.label}</strong>}<span>{row.complete} of {row.ended} complete</span></div>
            <div className="ca-horizontal-track" role="img" aria-label={`${row.label}: ${row.complete} complete, ${row.incomplete} missing IN or OUT, ${row.missed} without taps`}><StatusSegments row={row} scale={maximum} /></div>
            <div className="ca-row-counts">{attendanceStatuses.map((status) => <span key={status.key}><i className={`ca-dot is-${status.tone}`} />{row[status.key]} {status.short.toLowerCase()}</span>)}</div>
        </div>)}</div> : <div className="ca-empty"><ScanLine size={28} /><strong>No finished classes to compare</strong></div>}
        {rows.length > 5 && <button type="button" className="ca-show-more" onClick={() => setExpanded((value) => !value)}>{expanded ? 'Show fewer' : `Show all ${rows.length} ${group === 'faculty' ? 'faculty members' : 'subjects'}`}</button>}
    </section>;
}

export default function ClassAnalytics({ report, onSelect, onStatusSelect, selectedStatus = '' }) {
    const { summary, trend, faculty_breakdown, subject_breakdown } = report;
    const overview = attendanceOverview(summary);
    const metrics = [
        { label: 'Classes finished', value: overview.due, help: 'Scheduled end time has passed', Icon: CalendarCheck2, tone: 'neutral' },
        { label: 'Complete records', value: summary.complete, help: 'Both IN and OUT recorded', Icon: CheckCircle2, tone: 'complete' },
        { label: 'Missing IN or OUT', value: summary.incomplete, help: 'One tap still needs checking', Icon: Clock3, tone: 'incomplete' },
        { label: 'No recorded taps', value: summary.missed, help: 'Finished classes without a record', Icon: ScanLine, tone: 'missed' },
    ];
    return <div className="class-analytics">
        <div className="ca-metrics">{metrics.map(({ label, value, Icon, tone }) => <article className={`panel ca-metric is-${tone}`} key={label}><span className="ca-metric-icon"><Icon size={20} /></span><span className="ca-metric-label">{label}</span><strong>{value}</strong></article>)}</div>
        <div className="ca-overview-grid"><section className="panel ca-overview"><div className="ca-heading"><div><h2>Record completion</h2></div></div><CompletionRing summary={summary} overview={overview} onStatusSelect={onStatusSelect} selectedStatus={selectedStatus} /></section><DailyTrend trend={trend} onSelect={onSelect} /></div>
        <div className="ca-period-note"><span>{overview.notYetFinished} pending</span><span>{summary.excluded} excluded</span></div>
        <Breakdown faculty={faculty_breakdown} subjects={subject_breakdown} onSelect={onSelect} />
    </div>;
}
