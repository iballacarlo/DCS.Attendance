export default function ClassStatusChart({ metrics, total, onSelect, selectedStatus }) {
    return <section className="panel ca-status-chart">
        <div className="ca-heading"><h2>Class attendance comparison</h2><span className="ca-chart-unit">{total ? `${total} finished classes` : 'No finished classes in this period'}</span></div>
        <div className="ca-status-bars" role="group" aria-label="Class attendance by status">
            {metrics.map(({ label, value, status, tone }) => {
                const count = Number(value || 0);
                const percentage = total > 0 ? count / total * 100 : 0;
                const description = `${label}: ${count} of ${total} finished classes (${Math.round(percentage)}%)`;
                return <div key={status} className={`ca-status-bar is-${tone}`} role={onSelect ? 'button' : 'img'}
                    tabIndex={onSelect ? 0 : undefined} aria-label={description} title={description}
                    aria-pressed={onSelect ? selectedStatus === status : undefined}
                    onClick={() => onSelect?.(status)}
                    onKeyDown={(event) => { if (onSelect && ['Enter', ' '].includes(event.key)) { event.preventDefault(); onSelect(status); } }}>
                    <span className="ca-status-bar-label">{label}</span>
                    <span className="ca-status-bar-track" aria-hidden="true"><span style={{ width: `${percentage}%` }} /></span>
                    <span className="ca-status-bar-value"><strong>{count}</strong><small>{Math.round(percentage)}%</small></span>
                </div>;
            })}
        </div>
    </section>;
}
