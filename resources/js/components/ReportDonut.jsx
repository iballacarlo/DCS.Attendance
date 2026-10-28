import { donutGraphModel } from '../lib/reportGraph';

export default function ReportDonut({ bars, measure, onChoose }) {
    const status = donutGraphModel(bars, measure);
    const description = `${status.label}: ${status.count}/${status.total} finished classes`;
    const otherDescription = `Other classes: ${status.other}/${status.total} finished classes`;
    const choose = () => onChoose(status);
    return <div className={`report-donut-layout is-${status.key}`}>
        <svg className="report-donut" viewBox="0 0 200 200" role="group" aria-label={description}>
            <circle className="report-donut-track" cx="100" cy="100" r="72" fill="none" strokeWidth="18" role="img" aria-label={otherDescription}><title>{otherDescription}</title></circle>
            {status.percentage > 0 && <circle className="report-donut-segment" cx="100" cy="100" r="72" fill="none" strokeWidth="18" pathLength="100" strokeDasharray={`${status.percentage} ${100 - status.percentage}`} transform="rotate(-90 100 100)"
                role="button" tabIndex={0} aria-label={`View faculty breakdown — ${description}`} onClick={choose}
                onKeyDown={(event) => { if (['Enter', ' '].includes(event.key)) { event.preventDefault(); choose(); } }}><title>{description}</title></circle>}
            <text className="report-donut-value" x="100" y="100" textAnchor="middle" aria-hidden="true">{status.count}/{status.total}</text>
            <text className="report-donut-caption" x="100" y="122" textAnchor="middle" aria-hidden="true">finished classes</text>
        </svg>
        <div className="report-donut-details">
            <h3>{status.label}</h3>
            <p>{status.percentage == null ? 'No finished classes' : `${Math.round(status.percentage * 10) / 10}% of finished classes`}</p>
            <div className="report-donut-legend">
                <button type="button" className="report-donut-status" aria-label={`View faculty breakdown — ${description}`} onClick={choose}><span className="report-donut-dot" aria-hidden="true" /><span>{status.label}</span><strong>{status.count}</strong></button>
                <div className="report-donut-other" title={otherDescription}><span className="report-donut-dot" aria-hidden="true" /><span>Other classes</span><strong>{status.other}</strong></div>
            </div>
        </div>
    </div>;
}
