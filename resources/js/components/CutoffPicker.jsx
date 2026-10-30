import YearFilterInput from './YearFilterInput';
import { usePage } from '@inertiajs/react';
import { constrainCutoff, cutoffMonths, cutoffPeriod, semesterCutoffs } from '../lib/cutoff.js';

export default function CutoffPicker({ value, onChange, disabled = false, termId }) {
    const page = usePage();
    const selectedTerm = termId ?? new URLSearchParams(page.url.split('?')[1]).get('term_id') ?? '';
    const term = (page.props.semesterOptions || []).find((item) => String(item.id) === String(selectedTerm));
    const choices = semesterCutoffs(term);
    const period = constrainCutoff(value, term);
    const [year, month, day] = period.start.split('-');
    const years = choices.length ? [...new Set(choices.map((item) => item.start.slice(0, 4)))]
        : undefined;
    const months = choices.length ? [...new Set(choices.filter((item) => item.start.startsWith(`${year}-`)).map((item) => item.start.slice(5, 7)))]
        : cutoffMonths.map((_, index) => String(index + 1).padStart(2, '0'));
    const days = choices.length ? choices.filter((item) => item.start.startsWith(`${year}-${month}-`)).map((item) => item.start.slice(8)) : ['01', '16'];
    const commit = (nextYear = year, nextMonth = month, nextDay = day) => {
        if (!choices.length) {
            const next = cutoffPeriod(`${nextYear}-${nextMonth}-${nextDay}`);
            if (next) onChange(next);
            return;
        }
        const inYear = choices.filter((item) => item.start.startsWith(`${nextYear}-`));
        const inMonth = inYear.filter((item) => item.start.slice(5, 7) === nextMonth);
        const candidates = inMonth.length ? inMonth : inYear;
        const next = candidates.find((item) => item.start.slice(8) === nextDay) || candidates[0];
        if (next) onChange(next);
    };
    return <div className="cutoff-picker" role="group" aria-label="Cutoff period">
        <div className="cutoff-picker-fields">
            <label>Month<select disabled={disabled} value={month} onChange={(event) => commit(year, event.target.value)}>{months.map((number) => <option value={number} key={number}>{cutoffMonths[Number(number) - 1]}</option>)}</select></label>
            <label className="cutoff-picker-year">Year<YearFilterInput disabled={disabled} value={year} allowedYears={years} onYearChange={(next) => commit(next)} /></label>
            <label>Cutoff<select disabled={disabled} value={day} onChange={(event) => commit(year, month, event.target.value)}>{days.map((number) => <option key={number} value={number}>{number === '01' ? '1–15' : '16–month end'}</option>)}</select></label>
        </div>
    </div>;
}
