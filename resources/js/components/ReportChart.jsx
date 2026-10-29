import ReportTrendLines from './ReportTrendLines';
import ReportPie from './ReportPie';

export default function ReportChart({ bars, chartType, daily, selectedDay, selectedBar = '', measure, description, onChoose, rows = [], series = [], lineHighlight = '', onLineHighlight, highlight = '', onHighlight }) {
    if (chartType === 'donut') return <ReportPie rows={rows} highlight={highlight} onHighlight={onHighlight} />;
    if (!bars.length) return <p className="report-empty">No finished classes for these graph filters.</p>;
    if (chartType === 'line') return <ReportTrendLines bars={bars} series={series} selectedDay={selectedDay} highlight={lineHighlight} measure={measure} description={description} onChoose={onChoose} onStatusSelect={onLineHighlight} />;
    const selection = daily ? selectedDay : selectedBar;
    return <div className={`report-local-bars${selection ? ' has-selection' : ''}`} role="group" aria-label="Attendance comparison">{bars.map((bar) => <button type="button" key={bar.value} className={`report-local-bar is-${bar.key}${selection === bar.value ? ' is-selected' : ''}`} aria-label={description(bar)} aria-pressed={selection === bar.value} title={description(bar)} onClick={() => onChoose(bar)}>
        <span className="report-local-bar-label">{bar.label}</span><span className="report-local-bar-track" aria-hidden="true"><span style={{ width: `${bar.percentage || 0}%` }} /></span><strong>{bar.count} {bar.count === 1 ? 'class' : 'classes'}</strong>
    </button>)}</div>;
}
