import { useEffect, useReducer } from 'react';
import { ArrowLeft, RotateCcw } from 'lucide-react';
import { graphChoices, graphReducer, graphView, initialGraphState, reportMeasures } from '../lib/reportGraph';
import ReportChart from './ReportChart';

const viewLabels = { overall: 'Overall', faculty: 'By Faculty', subject: 'By Subject', daily: 'Daily Trend' };
const graphTypes = {
    donut: { label: 'Pie - Attendance distribution', title: 'Class attendance distribution', purpose: 'Select a slice or its label to highlight matching classes.' },
    bar: { label: 'Bar - Comparison', title: 'Attendance comparison', purpose: 'Compare status rates overall, by faculty, or by subject.' },
    line: { label: 'Line - Daily trend', title: 'Daily attendance trend', purpose: 'Compare on time, late, undertime and absent classes together over time.' },
};

export function AttendanceGraph({ rows, state, dispatch }) {
    useEffect(() => { dispatch({ type: 'refresh', rows }); }, [rows]);
    const current = graphView(rows, state);
    const choices = graphChoices(rows, state.faculty);
    const graphType = graphTypes[state.chartType];
    const selectedDay = current.allBars.find((bar) => bar.value === state.day);
    const selectedBar = current.bars.find((bar) => bar.value === state.barSelection);
    const selection = state.chartType === 'line' ? selectedDay : state.chartType === 'bar' ? selectedBar : null;
    const lineFocus = state.chartType === 'line' && reportMeasures.find((item) => item.key === state.lineHighlight);
    const chosenFaculty = choices.faculties.find((item) => item.value === state.faculty)?.label;
    const chosenSubject = choices.subjects.find((item) => item.value === state.subject)?.label;
    const description = (bar) => `${state.view === 'overall' ? bar.label : `${bar.statusLabel || current.measure.label} — ${bar.label}`}: ${bar.count} ${bar.count === 1 ? 'class' : 'classes'}${bar.percentage == null ? '' : ` (${Math.round(bar.percentage * 10) / 10}%)`}. Based on ${bar.total} finished classes.`;
    const selectionDescription = state.chartType === 'line' && selectedDay
        ? `${selectedDay.label}: ${current.series.map((item) => `${item.label}: ${item.bars.find((bar) => bar.value === selectedDay.value)?.count ?? 0}`).join(' · ')} classes.`
        : selection ? description(selection) : lineFocus ? `${lineFocus.label} highlighted` : '';
    const choose = (bar) => dispatch(state.view === 'daily' ? { type: 'day', day: bar.value } : { type: 'bar-select', value: bar.value });

    return <section className="panel report-graph" aria-label="Interactive attendance graph">
        <div className="report-graph-heading"><div><h2>{graphType.title}</h2><p>{graphType.purpose}</p></div>
            <div className="report-graph-actions"><button type="button" className="ui-control ui-action ui-secondary report-action-back" disabled={!state.history.length} onClick={() => dispatch({ type: 'back' })}><ArrowLeft size={15} />Back</button>
                <button type="button" className="ui-control ui-action ui-secondary report-action-reset" onClick={() => dispatch({ type: 'reset' })}><RotateCcw size={15} />Reset graph</button></div>
        </div>
        <div className="report-graph-controls">
            <label>Graph type<select value={state.chartType} onChange={(event) => dispatch({ type: 'chart', chartType: event.target.value })}>{Object.entries(graphTypes).map(([value, type]) => <option value={value} key={value}>{type.label}</option>)}</select></label>
            {state.chartType === 'bar' && <label>Compare<select value={state.view} onChange={(event) => dispatch({ type: 'view', view: event.target.value })}>{Object.entries(viewLabels).filter(([value]) => value !== 'daily').map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label>}
            <label>Faculty<select value={state.faculty} onChange={(event) => dispatch({ type: 'filter', field: 'faculty', value: event.target.value })}><option value="">All faculty in report</option>{choices.faculties.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
            <label>Subject<select value={state.subject} onChange={(event) => dispatch({ type: 'filter', field: 'subject', value: event.target.value })}><option value="">All subjects in report</option>{choices.subjects.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
        </div>
        <div className="report-graph-caption"><span>{current.total ? `${current.total} finished classes` : 'No finished classes'}</span>{state.view !== 'overall' && state.chartType === 'bar' && <strong>{current.measure.label}</strong>}{chosenFaculty && <span>{chosenFaculty}</span>}{chosenSubject && <span>{chosenSubject}</span>}
            <span className="report-help" tabIndex={0} title="Counts use eligible finished classes, including For review. A class can be both late and undertime.">About these counts</span>
        </div>
        <div className="report-graph-content" key={`${state.view}:${state.chartType}:${state.chartType === 'line' ? '' : state.measure}:${state.faculty}:${state.subject}:${current.page}`}>
            {state.chartType !== 'donut' && <p className="report-plot-scale">Percentage of finished classes (0-100%)</p>}
            <ReportChart bars={current.bars} series={current.series} rows={current.endedRows} chartType={state.chartType} daily={state.view === 'daily'} selectedDay={state.day} selectedBar={state.barSelection} measure={state.measure} lineHighlight={state.lineHighlight} onLineHighlight={(measure) => dispatch({ type: 'summary', measure })} highlight={state.highlight} onHighlight={(highlight) => dispatch({ type: 'highlight', highlight })} description={description} onChoose={choose} />
            {(selection || lineFocus) && <div className="report-chart-selection"><output className="report-graph-selection" aria-live="polite" key={selection?.value || state.lineHighlight}>{selectionDescription}</output>
                <div><button type="button" className="report-pie-show-all" onClick={() => dispatch({ type: 'clear-selection' })}>Show all</button>
                    {state.chartType === 'bar' && <button type="button" className="ui-control ui-action ui-secondary report-action-back" onClick={() => dispatch(state.view === 'overall' ? { type: 'overall', measure: selection.key } : { type: 'group', value: selection.value })}>{state.view === 'overall' ? 'View faculty breakdown' : 'View daily trend'}</button>}</div>
            </div>}
        </div>
        {current.pages > 1 && <nav className="session-pagination" aria-label="Graph pages"><button type="button" disabled={current.page === 1} onClick={() => dispatch({ type: 'page', page: current.page - 1 })}>Previous</button><span>{current.page} / {current.pages}</span><button type="button" disabled={current.page === current.pages} onClick={() => dispatch({ type: 'page', page: current.page + 1 })}>Next</button></nav>}
    </section>;
}

export default function ReportAnalytics({ report, onReview }) {
    const [state, dispatch] = useReducer(graphReducer, undefined, initialGraphState);
    const total = report.summary.ended || 0;
    return <div className="report-analytics">
        <div className="report-summary-context"><span>{total ? `Based on ${total} finished classes` : 'No finished classes'}</span>{report.summary.incomplete > 0 && <button type="button" onClick={onReview} className="report-review-link">For review: {report.summary.incomplete}</button>}</div>
        <div className="report-summary-cards">{reportMeasures.map((item) => <article className={`panel report-summary-card is-${item.key}`} key={item.key}><h2><button type="button" className="report-summary-select" aria-pressed={state.chartType === 'donut' ? state.highlight === item.key : state.chartType === 'line' ? state.lineHighlight === item.key : state.view === 'overall' ? state.barSelection === item.key : state.measure === item.key} onClick={() => dispatch({ type: 'summary', measure: item.key })}>{item.label}</button></h2><strong>{report.summary[item.key] || 0}<span>{report.summary[item.key] === 1 ? ' class' : ' classes'}</span></strong>{['late', 'undertime'].includes(item.key) && <small>{report.summary[item.key === 'late' ? 'total_late_minutes' : 'total_undertime_minutes'] || 0} minutes total</small>}</article>)}</div>
        <AttendanceGraph rows={report.rows} state={state} dispatch={dispatch} />
    </div>;
}
