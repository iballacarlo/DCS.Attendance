import { lineGraphModel } from '../lib/reportGraph';

export default function ReportTrendLines({ bars, series = [], selectedDay, highlight = '', measure, description, onChoose, onStatusSelect = () => {} }) {
    const plots = (series.length ? series : [{ key: measure, label: '', bars }])
        .map((item) => ({ ...item, plot: lineGraphModel(item.bars) }));
    const axis = plots.find((item) => item.plot.points.length)?.plot;
    if (!axis) return <p className="report-empty">No finished classes for these graph filters.</p>;

    return <div className="report-trend-lines">
        {series.length > 0 && <ul className="report-line-legend" aria-label="Attendance lines">{plots.map((item) => <li key={item.key} className={`is-${item.key}${highlight && highlight !== item.key ? ' is-muted' : ''}`}><button type="button" aria-pressed={highlight === item.key} title={`Highlight ${item.label}`} onClick={() => onStatusSelect(item.key)}><i aria-hidden="true" />{item.label}</button></li>)}</ul>}
        <div className={`report-line-scroll is-${measure}${selectedDay ? ' has-selection' : ''}`}>
            <svg className="report-line-chart" viewBox={`0 0 ${axis.width} ${axis.height}`} role="group" aria-label="Daily attendance trend">
                {[0, 50, 100].map((value) => {
                    const y = axis.bottom - value / 100 * (axis.bottom - axis.top);
                    return <g className="report-line-axis" key={value} aria-hidden="true"><line x1={axis.left} x2={axis.right} y1={y} y2={y} /><text x={axis.left - 10} y={y + 4} textAnchor="end">{value}%</text></g>;
                })}
                {plots.map(({ key, label, plot }) => <g key={key} className={`report-line-series is-${key}${highlight && highlight !== key ? ' is-muted' : ''}`} aria-label={label || undefined}>
                    {plot.path && <path className="report-line-path" d={plot.path} aria-hidden="true" />}
                    {plot.points.map((point) => {
                        const data = { ...point, statusLabel: label };
                        return <g key={point.value} className={`report-line-point${selectedDay === point.value ? ' is-selected' : ''}`} role="button" tabIndex={0} aria-label={description(data)} aria-pressed={selectedDay === point.value}
                            onClick={() => onChoose(data)} onKeyDown={(event) => { if (['Enter', ' '].includes(event.key)) { event.preventDefault(); onChoose(data); } }}>
                            <title>{description(data)}</title><circle className="report-line-target" cx={point.x} cy={point.y} r="14" />
                            <circle className="report-line-dot" cx={point.x} cy={point.y} r="4" aria-hidden="true" />
                            {!series.length && <text className="report-line-count" x={point.x} y={point.y - 12} textAnchor="middle" aria-hidden="true">{point.count} {point.count === 1 ? 'class' : 'classes'}</text>}
                        </g>;
                    })}
                </g>)}
                {axis.ticks.map((point, index) => <text key={point.value} className="report-line-date" x={point.x} y={axis.bottom + 27} textAnchor={axis.ticks.length === 1 ? 'middle' : index === 0 ? 'start' : index === axis.ticks.length - 1 ? 'end' : 'middle'} aria-hidden="true">{point.label}</text>)}
            </svg>
        </div>
    </div>;
}
