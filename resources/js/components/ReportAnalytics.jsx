import { useEffect, useReducer } from 'react';
import { ArrowLeft, RotateCcw } from 'lucide-react';
import { graphChoices, graphReducer, graphView, initialGraphState, reportMeasures } from '../lib/reportGraph';
import ReportChart from './ReportChart';

const viewLabels = { overall: 'Overall', faculty: 'By Faculty', subject: 'By Subject', daily: 'Daily Trend' };
const graphTypes = {
    bar: { label: 'Bar - Comparison', title: 'Attendance comparison', purpose: 'Compare status rates overall, by faculty, or by subject.' },
    line: { label: 'Line - Daily trend', title: 'Daily attendance trend', purpose: 'See how the selected status changes from day to day.' },
    donut: { label: 'Donut - Status share', title: 'Attendance share', purpose: 'See one status as a share of all finished classes.' },
};

function AttendanceGraph({ rows }) {
    const [state, dispatch] = useReducer(graphReducer, undefined, initialGraphState);
    useEffect(() => { dispatch({ type: 'refresh', rows }); }, [rows]);
    const current = graphView(rows, state);
    const choices = graphChoices(rows, state.faculty);
    const graphType = graphTypes[state.chartType];
    const selectedDay = current.allBars.find((bar) => bar.value === state.day);
    const chosenFaculty = choices.faculties.find((item) => item.value === state.faculty)?.label;
    const chosenSubject = choices.subjects.find((item) => item.value === state.subject)?.label;
    const description = (bar) => `${state.view === 'overall' ? bar.label : `${current.measure.label} — ${bar.label}`}: ${bar.count}/${bar.total} finished classes${bar.percentage == null ? '' : ` (${Math.round(bar.percentage * 10) / 10}%)`}`;
    const choose = (bar) => dispatch(state.view === 'overall' ? { type: 'overall', measure: bar.key }
        : state.view === 'daily' ? { type: 'day', day: bar.value } : { type: 'group', value: bar.value });

    return <section className="panel report-graph" aria-label="Interactive attendance graph">
        <div className="report-graph-heading"><div><h2>{graphType.title}</h2><p>{graphType.purpose}</p></div>
            <div className="report-graph-actions"><button type="button" className="ui-control ui-action ui-secondary report-action-back" disabled={!state.history.length} onClick={() => dispatch({ type: 'back' })}><ArrowLeft size={15} />Back</button>
                <button type="button" className="ui-control ui-action ui-secondary report-action-reset" onClick={() => dispatch({ type: 'reset' })}><RotateCcw size={15} />Reset graph</button></div>
        </div>
        <div className="report-graph-controls">
            <label>Graph type<select value={state.chartType} onChange={(event) => dispatch({ type: 'chart', chartType: event.target.value })}>{Object.entries(graphTypes).map(([value, type]) => <option value={value} key={value}>{type.label}</option>)}</select></label>
            {state.chartType === 'bar' && <label>Compare<select value={state.view} onChange={(event) => dispatch({ type: 'view', view: event.target.value })}>{Object.entries(viewLabels).filter(([value]) => value !== 'daily').map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label>}
            {(state.view !== 'overall' || state.chartType === 'donut') && <label>Status<select value={state.measure} onChange={(event) => dispatch({ type: 'filter', field: 'measure', value: event.target.value })}>{reportMeasures.map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}</select></label>}
            <label>Faculty<select value={state.faculty} onChange={(event) => dispatch({ type: 'filter', field: 'faculty', value: event.target.value })}><option value="">All faculty in report</option>{choices.faculties.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
            <label>Subject<select value={state.subject} onChange={(event) => dispatch({ type: 'filter', field: 'subject', value: event.target.value })}><option value="">All subjects in report</option>{choices.subjects.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
        </div>
        <div className="report-graph-caption"><span>{current.total ? `${current.total} finished classes` : 'No finished classes'}</span>{chosenFaculty && <span>{chosenFaculty}</span>}{chosenSubject && <span>{chosenSubject}</span>}
            <span className="report-help" tabIndex={0} title="Each count is out of all finished eligible classes, including For review. A class can be both late and undertime.">How to read: count / finished classes</span>
        </div>
        <div className="report-graph-content" key={`${state.view}:${state.chartType}:${state.measure}:${state.faculty}:${state.subject}:${current.page}`}>
            {state.chartType !== 'donut' && <p className="report-plot-scale">Percentage of finished classes (0-100%)</p>}
            <ReportChart bars={current.bars} chartType={state.chartType} daily={state.view === 'daily'} selectedDay={state.day} measure={state.measure} description={description} onChoose={choose} />
            {state.view === 'daily' && <output className="report-graph-selection" aria-live="polite">{selectedDay ? description(selectedDay) : current.total ? 'Select a day to see its count.' : 'No finished classes for these graph filters.'}</output>}
        </div>
        {current.pages > 1 && <nav className="session-pagination" aria-label="Graph pages"><button type="button" disabled={current.page === 1} onClick={() => dispatch({ type: 'page', page: current.page - 1 })}>Previous</button><span>{current.page} / {current.pages}</span><button type="button" disabled={current.page === current.pages} onClick={() => dispatch({ type: 'page', page: current.page + 1 })}>Next</button></nav>}
    </section>;
}

export default function ReportAnalytics({ report, onReview }) {
    const total = report.summary.ended || 0;
    return <div className="report-analytics">
        <div className="report-summary-context"><span>{total ? `${total} finished classes` : 'No finished classes'}</span>{report.summary.incomplete > 0 && <button type="button" onClick={onReview} className="report-review-link">For review: {report.summary.incomplete}</button>}</div>
        <div className="report-summary-cards">{reportMeasures.map((item) => <article className={`panel report-summary-card is-${item.key}`} key={item.key}><h2>{item.label}</h2><strong>{report.summary[item.key] || 0}<span>/{total}</span></strong>{['late', 'undertime'].includes(item.key) && <small>{report.summary[item.key === 'late' ? 'total_late_minutes' : 'total_undertime_minutes'] || 0} minutes total</small>}</article>)}</div>
        <AttendanceGraph rows={report.rows} />
    </div>;
}
