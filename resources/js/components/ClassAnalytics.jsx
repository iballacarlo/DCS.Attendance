import ClassPunctuality from './ClassPunctuality';
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

export default function ClassAnalytics({ report, onSelect, onStatusSelect, selectedStatus = '' }) {
    const { summary, trend, faculty_breakdown, subject_breakdown } = report;
    const overview = attendanceOverview(summary);
    const metrics = [
        { label: 'Classes finished', value: overview.due, Icon: CalendarCheck2, tone: 'neutral' },
        { label: 'On time & full class', value: summary.on_time || 0, Icon: CheckCircle2, tone: 'complete', status: 'on_time' },
        { label: 'Late', value: summary.late || 0, Icon: Clock3, tone: 'late', status: 'late', detail: `${summary.total_late_minutes || 0} minutes total` },
        { label: 'Undertime', value: summary.undertime || 0, Icon: Clock3, tone: 'undertime', status: 'undertime', detail: `${summary.total_undertime_minutes || 0} minutes total` },
        { label: 'Absent / no taps', value: summary.missed, Icon: ScanLine, tone: 'missed', status: 'missed' },
        { label: 'Incomplete IN/OUT', value: summary.incomplete, Icon: Clock3, tone: 'incomplete', status: 'incomplete' },
    ];
    return <div className="class-analytics">
        <div className="ca-metrics">{metrics.map(({ label, value, Icon, tone, status, detail }) => <article className={`panel ca-metric is-${tone}`} key={label} role={onStatusSelect && status ? 'button' : undefined} tabIndex={onStatusSelect && status ? 0 : undefined} aria-pressed={onStatusSelect && status ? selectedStatus === status : undefined} onClick={() => status && onStatusSelect?.(status)} onKeyDown={(event) => { if (status && onStatusSelect && ['Enter', ' '].includes(event.key)) { event.preventDefault(); onStatusSelect(status); } }}><span className="ca-metric-icon"><Icon size={20} /></span><span className="ca-metric-label">{label}</span><strong>{value}</strong>{detail && <small>{detail}</small>}</article>)}</div>
        <p className="ca-status-note">Finished classes only. A class can be both late and undertime. Absent means no recorded class taps.</p>
        <div className="ca-overview-grid"><section className="panel ca-overview"><div className="ca-heading"><div><h2>Record completion</h2></div></div><CompletionRing summary={summary} overview={overview} onStatusSelect={onStatusSelect} selectedStatus={selectedStatus} /></section><DailyTrend trend={trend} onSelect={onSelect} /></div>
        <div className="ca-period-note"><span>{overview.notYetFinished} pending</span><span>{summary.excluded} excluded</span></div>
        <ClassPunctuality faculty={faculty_breakdown} subjects={subject_breakdown} onSelect={onSelect} />
    </div>;
}
