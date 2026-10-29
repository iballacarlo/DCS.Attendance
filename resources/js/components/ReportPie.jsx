import { pieGraphModel } from '../lib/reportPie';

const countLabel = (count) => `${count} ${count === 1 ? 'class' : 'classes'}`;

export default function ReportPie({ rows = [], highlight = '', onHighlight = () => {} }) {
    const model = pieGraphModel(rows, highlight);
    const describe = (slice) => `${slice.label}: ${countLabel(slice.count)} (${Math.round(slice.percentage * 10) / 10}%)`;
    const activate = (event, key) => {
        if (['Enter', ' '].includes(event.key)) { event.preventDefault(); onHighlight(key); }
    };
    const plot = (compact) => <svg className={compact ? 'report-pie-compact' : 'report-pie-wide'} viewBox={compact ? '170 35 300 300' : '0 0 640 380'} role="group" aria-label="Class attendance distribution">
        {!model.total && <circle className="report-pie-empty" cx="320" cy="185" r="126" />}
        {model.slices.map((slice) => <path key={slice.key} className={`report-pie-slice is-${slice.key}${slice.highlighted ? ' is-highlighted' : ' is-muted'}`} d={slice.path}
            role="button" tabIndex={0} aria-label={`${describe(slice)}. Highlight ${slice.key === 'late' ? 'Late' : slice.key === 'undertime' ? 'Undertime' : slice.label}.`}
            aria-pressed={!!highlight && slice.highlighted} onClick={() => onHighlight(slice.key)} onKeyDown={(event) => activate(event, slice.key)}><title>{describe(slice)}</title></path>)}
        {!compact && model.slices.map((slice) => <g className={`report-pie-label${slice.highlighted ? '' : ' is-muted'}`} key={slice.key}
            role="button" tabIndex={0} aria-label={`Highlight ${describe(slice)}`} aria-pressed={!!highlight && slice.highlighted}
            onClick={() => onHighlight(slice.key)} onKeyDown={(event) => activate(event, slice.key)}>
            <title>{describe(slice)}</title><rect className="report-pie-label-target" x={slice.side === 'left' ? slice.labelX : slice.labelX - 165} y={slice.labelY - 26} width="165" height="48" rx="4" />
            <polyline points={slice.leader} /><text x={slice.labelX} y={slice.labelY - 7} textAnchor={slice.side === 'left' ? 'start' : 'end'}>{slice.label}</text>
            <text className="report-pie-label-detail" x={slice.labelX} y={slice.labelY + 16} textAnchor={slice.side === 'left' ? 'start' : 'end'}>{countLabel(slice.count)} · {Math.round(slice.percentage * 10) / 10}%</text>
        </g>)}
        {!model.total && <text className="report-pie-empty-label" x="320" y="190" textAnchor="middle">No finished classes</text>}
    </svg>;

    return <div className="report-pie-layout">
        <div className="report-pie-selection" hidden={!highlight}><output aria-live="polite"><span className="report-pie-selection-text" key={highlight}>{model.selected ? `${model.selected.label}: ${countLabel(model.selected.count)}` : ''}</span></output>
            {highlight && <button type="button" className="report-pie-show-all" onClick={() => onHighlight('')}>Show all</button>}</div>
        {plot(false)}{plot(true)}
        <div className="report-pie-legend report-pie-mobile-legend" role="group" aria-label="Mobile chart labels">{model.slices.map((item) => <button type="button" key={item.key}
            className={`report-pie-legend-item is-${item.key}${highlight && !model.slices.some((slice) => slice.key === item.key && slice.highlighted) && highlight !== item.key ? ' is-muted' : ''}`}
            aria-pressed={highlight === item.key} onClick={() => onHighlight(item.key)}><i aria-hidden="true" /><span>{item.label}</span><strong>{countLabel(item.count)}</strong></button>)}</div>
        <p className="report-pie-note">Late and Undertime highlights include the “Late &amp; undertime” slice. Each class appears in only one slice.</p>
    </div>;
}
