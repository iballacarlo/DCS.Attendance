import { lineGraphModel } from '../lib/reportGraph';
import ReportDonut from './ReportDonut';

export default function ReportChart({ bars, chartType, daily, selectedDay, measure, description, onChoose }) {
    if (chartType === 'donut') return <ReportDonut bars={bars} measure={measure} onChoose={onChoose} />;
    if (!bars.length) return <p className="report-empty">No finished classes for these graph filters.</p>;
    if (chartType === 'line') {
        const plot = lineGraphModel(bars);
        if (!plot.points.length) return <p className="report-empty">No finished classes for these graph filters.</p>;
        return <div className={`report-line-scroll is-${measure}`}>
            <svg className="report-line-chart" viewBox={`0 0 ${plot.width} ${plot.height}`} role="group" aria-label="Daily attendance trend">
                {[0, 50, 100].map((value) => {
                    const y = plot.bottom - value / 100 * (plot.bottom - plot.top);
                    return <g className="report-line-axis" key={value} aria-hidden="true"><line x1={plot.left} x2={plot.right} y1={y} y2={y} /><text x={plot.left - 10} y={y + 4} textAnchor="end">{value}%</text></g>;
                })}
                {plot.path && <path className="report-line-path" d={plot.path} aria-hidden="true" />}
                {plot.points.map((point) => <g key={point.value} className={`report-line-point${selectedDay === point.value ? ' is-selected' : ''}`} role="button" tabIndex={0} aria-label={description(point)} aria-pressed={selectedDay === point.value} onClick={() => onChoose(point)}
                    onKeyDown={(event) => { if (['Enter', ' '].includes(event.key)) { event.preventDefault(); onChoose(point); } }}>
                    <title>{description(point)}</title>
                    <circle className="report-line-target" cx={point.x} cy={point.y} r="18" />
                    <circle className="report-line-dot" cx={point.x} cy={point.y} r="5" aria-hidden="true" />
                    <text className="report-line-count" x={point.x} y={point.y - 12} textAnchor="middle" aria-hidden="true">{point.count}/{point.total}</text>
                    <text className="report-line-date" x={point.x} y={plot.bottom + 27} textAnchor="middle" aria-hidden="true">{point.label}</text>
                </g>)}
            </svg>
        </div>;
    }
    return <div className="report-local-bars" role="group" aria-label="Attendance comparison">{bars.map((bar) => <button type="button" key={bar.value} className={`report-local-bar is-${bar.key}${daily && selectedDay === bar.value ? ' is-selected' : ''}`} aria-label={description(bar)} aria-pressed={daily ? selectedDay === bar.value : undefined} title={description(bar)} onClick={() => onChoose(bar)}>
        <span className="report-local-bar-label">{bar.label}</span><span className="report-local-bar-track" aria-hidden="true"><span style={{ width: `${bar.percentage || 0}%` }} /></span><strong>{bar.count}/{bar.total}</strong>
    </button>)}</div>;
}
