import { matchesSessionStatus, shortChartDate } from './classAnalytics.js';

export const reportMeasures = [
    { key: 'on_time', label: 'On time & full class' },
    { key: 'late', label: 'Late' },
    { key: 'undertime', label: 'Undertime' },
    { key: 'missed', label: 'Absent' },
];
const unspecified = '__unspecified_subject__';
const subjectKey = (row) => row.subject || unspecified;
// Keep the internal "donut" key compatible with saved graph state; the UI now renders a pie.
export const initialGraphState = () => ({ view: 'overall', chartType: 'donut', highlight: '', lineHighlight: '', barSelection: '', measure: 'late', faculty: '', subject: '', day: '', page: 1, history: [] });
export function graphHasChanges(state) {
    return Object.entries(initialGraphState()).some(([key, value]) => Array.isArray(value)
        ? (state[key]?.length || 0) > 0 : (state[key] ?? value) !== value);
}
const chartForView = (view) => view === 'daily' ? 'line' : 'bar';
const chartAllowed = (chartType, view) => ['bar', 'line', 'donut'].includes(chartType)
    && (chartType !== 'bar' || view !== 'daily')
    && (chartType !== 'line' || view === 'daily') && (chartType !== 'donut' || view === 'overall');

export function graphChoices(rows, faculty = '') {
    const faculties = new Map();
    const subjects = new Map();
    for (const row of rows) {
        faculties.set(String(row.employee_id), row.faculty);
        if (!faculty || String(row.employee_id) === faculty) subjects.set(subjectKey(row), row.subject || 'Unspecified subject');
    }
    const options = (map) => [...map].map(([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label));
    return { faculties: options(faculties), subjects: options(subjects) };
}

function validSelection(state, rows) {
    const next = { ...state };
    if (!chartAllowed(next.chartType, next.view)) next.chartType = chartForView(next.view);
    if (next.faculty && !rows.some((row) => String(row.employee_id) === next.faculty)) next.faculty = '';
    if (next.subject && !graphChoices(rows, next.faculty).subjects.some((option) => option.value === next.subject)) next.subject = '';
    if (next.day && !scopedRows(rows, next).some((row) => row.date === next.day && row.ended && row.status !== 'excluded')) next.day = '';
    const current = graphView(rows, next);
    next.page = current.page;
    if (next.barSelection && !current.bars.some((bar) => bar.value === next.barSelection)) next.barSelection = '';
    return next;
}

export function graphReducer(state, action) {
    if (action.type === 'reset') return graphHasChanges(state) ? initialGraphState() : state;
    if (action.type === 'refresh') {
        const next = validSelection(state, action.rows);
        next.history = state.history.map((previous) => validSelection(previous, action.rows));
        return JSON.stringify(next) === JSON.stringify(state) ? state : next;
    }
    if (action.type === 'back') {
        if (!state.history.length) return state;
        return { ...state.history.at(-1), history: state.history.slice(0, -1) };
    }
    if (action.type === 'page') return { ...state, page: action.page, barSelection: '' };
    if (action.type === 'day') return { ...state, day: state.day === action.day ? '' : action.day };
    if (action.type === 'bar-select') return { ...state, barSelection: state.barSelection === action.value ? '' : action.value,
        ...(state.view === 'overall' && reportMeasures.some((item) => item.key === action.value) ? { measure: action.value } : {}) };
    if (action.type === 'clear-selection') return { ...state, barSelection: '', day: '', highlight: '', lineHighlight: '' };
    if (action.type === 'summary') {
        if (!reportMeasures.some((item) => item.key === action.measure)) return state;
        if (state.chartType === 'donut') return graphReducer(state, { type: 'highlight', highlight: action.measure });
        if (state.chartType === 'line') return { ...state, measure: action.measure, lineHighlight: state.lineHighlight === action.measure ? '' : action.measure, day: '' };
        if (state.view === 'overall') return graphReducer(state, { type: 'bar-select', value: action.measure });
        return { ...state, measure: action.measure, barSelection: '', day: '', page: 1 };
    }
    const { history, ...previous } = state;
    if (action.type === 'highlight') {
        if (!['', 'on_time', 'late', 'undertime', 'late_undertime', 'missed', 'incomplete'].includes(action.highlight)) return state;
        return { ...state, view: 'overall', chartType: 'donut', highlight: state.chartType === 'donut' && state.highlight === action.highlight ? '' : action.highlight,
            day: '', barSelection: '', page: 1, history: state.chartType === 'donut' ? history : [...history, previous] };
    }
    if (action.type === 'chart') {
        if (!['bar', 'line', 'donut'].includes(action.chartType) || action.chartType === state.chartType) return state;
        const view = action.chartType === 'line' ? 'daily' : action.chartType === 'donut' || state.view === 'daily' ? 'overall' : state.view;
        return { ...state, view, chartType: action.chartType, day: '', lineHighlight: '', barSelection: '', page: 1, ...(action.chartType === 'donut' ? { measure: 'late', highlight: '' } : {}), history: [...history, previous] };
    }
    if (action.type === 'view') return { ...state, view: action.view, chartType: chartForView(action.view), day: '', barSelection: '', page: 1, history: [...history, previous] };
    if (action.type === 'overall') return { ...state, view: 'faculty', chartType: 'bar', measure: action.measure, day: '', barSelection: '', page: 1, history: [...history, previous] };
    if (action.type === 'group') return { ...state, view: 'daily', chartType: 'line', lineHighlight: '', [state.view === 'faculty' ? 'faculty' : 'subject']: action.value, day: '', barSelection: '', page: 1, history: [...history, previous] };
    if (action.type === 'filter') return { ...state, [action.field]: action.value, ...(action.field === 'faculty' ? { subject: '' } : {}), day: '', barSelection: '', page: 1 };
    return state;
}

function scopedRows(rows, state) {
    return rows.filter((row) => (!state.faculty || String(row.employee_id) === state.faculty) && (!state.subject || subjectKey(row) === state.subject));
}

export function graphView(rows, state) {
    const ended = scopedRows(rows, state).filter((row) => row.ended && row.status !== 'excluded');
    const measure = reportMeasures.find((item) => item.key === state.measure) || reportMeasures[1];
    const metric = (items, status) => {
        const count = items.filter((row) => matchesSessionStatus(row, status)).length;
        return { count, total: items.length, percentage: items.length ? count / items.length * 100 : null };
    };
    let bars;
    if (state.view === 'overall') bars = reportMeasures.map((item) => ({ ...item, value: item.key, ...metric(ended, item.key) }));
    else {
        const groups = new Map();
        for (const row of ended) {
            const value = state.view === 'faculty' ? String(row.employee_id) : state.view === 'subject' ? subjectKey(row) : row.date;
            if (!groups.has(value)) groups.set(value, []);
            groups.get(value).push(row);
        }
        bars = [...groups].map(([value, items]) => ({ value, key: measure.key,
            label: state.view === 'faculty' ? items[0].faculty : state.view === 'subject' ? items[0].subject || 'Unspecified subject' : shortChartDate(value),
            ...metric(items, measure.key) }));
        bars.sort(state.view === 'daily' ? (a, b) => a.value.localeCompare(b.value)
            : (a, b) => b.percentage - a.percentage || b.count - a.count || a.label.localeCompare(b.label));
    }
    const pages = ['faculty', 'subject'].includes(state.view) ? Math.max(1, Math.ceil(bars.length / 5)) : 1;
    const page = Math.max(1, Math.min(state.page, pages));
    const visible = ['faculty', 'subject'].includes(state.view) ? bars.slice((page - 1) * 5, page * 5) : bars;
    const days = new Map();
    if (state.view === 'daily') {
        for (const row of ended) {
            if (!days.has(row.date)) days.set(row.date, []);
            days.get(row.date).push(row);
        }
    }
    const series = state.view === 'daily' ? reportMeasures.map((item) => ({ ...item,
        bars: [...days].sort(([a], [b]) => a.localeCompare(b)).map(([date, items]) => ({ key: item.key, value: date, label: shortChartDate(date), ...metric(items, item.key) })),
    })) : [];
    return { bars: visible, allBars: bars, series, endedRows: ended, total: ended.length, measure, page, pages };
}

// Plot actual dates at their calendar spacing; days without observations are not zeroes.
export function lineGraphModel(bars) {
    const bounds = { width: 720, height: 270, left: 48, right: 700, top: 28, bottom: 218 };
    const observations = bars.filter((bar) => bar.total > 0 && Number.isFinite(bar.percentage)).sort((a, b) => a.value.localeCompare(b.value));
    if (!observations.length) return { ...bounds, points: [], path: '' };
    const timestamps = observations.map((bar) => Date.parse(`${bar.value}T00:00:00Z`));
    const span = timestamps.at(-1) - timestamps[0];
    const round = (number) => Math.round(number * 100) / 100;
    const points = observations.map((bar, index) => ({ ...bar,
        x: round(span ? bounds.left + (timestamps[index] - timestamps[0]) / span * (bounds.right - bounds.left) : (bounds.left + bounds.right) / 2),
        y: round(bounds.bottom - bar.percentage / 100 * (bounds.bottom - bounds.top)),
    }));
    // Full MM/DD/YYYY labels need their own space; keep every observation
    // interactive while only labeling a readable subset of actual dates.
    const ticks = points.filter((point, index) => index === 0 || index === points.length - 1);
    for (const point of points.slice(1, -1)) {
        if (ticks.every((tick) => Math.abs(tick.x - point.x) >= 100)) ticks.push(point);
    }
    ticks.sort((a, b) => a.x - b.x);
    return { ...bounds, points, ticks, path: points.length > 1 ? points.map((point, index) => `${index ? 'L' : 'M'}${point.x},${point.y}`).join(' ') : '' };
}

export function donutGraphModel(bars, measure = 'late') {
    const status = reportMeasures.find((item) => item.key === measure) || reportMeasures[1];
    const bar = bars.find((item) => item.key === status.key);
    const count = Number(bar?.count) || 0;
    const total = Number(bar?.total) || 0;
    return { ...status, value: status.key, count, total, other: total - count, percentage: total ? count / total * 100 : null };
}
