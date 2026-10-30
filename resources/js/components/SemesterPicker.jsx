import YearFilterInput from './YearFilterInput';
import { constrainCutoff } from '../lib/cutoff.js';
import { router, usePage } from '@inertiajs/react';

export default function SemesterPicker({ value, onChange, legacyOnly = false, disabled = false, compact = false, options }) {
    const page = usePage();
    const terms = (options || page.props.semesterOptions || []).filter((term) => term.status !== 'draft');
    if (!terms.length || page.url.split('?')[0] === '/') return null;
    const selected = value ?? new URLSearchParams(page.url.split('?')[1]).get('term_id') ?? '';
    const change = (next) => {
        if (onChange) { onChange(next); return; }
        const params = Object.fromEntries(new URLSearchParams(page.url.split('?')[1]));
        const term = terms.find((item) => String(item.id) === next);
        params.term_id = next; params.page = 1;
        if (term) {
            const cutoff = constrainCutoff(params.cutoff_start || params.start_date, term).start;
            if (page.url.startsWith('/attendance/dtr')) params.start_date = cutoff;
            else params.cutoff_start = cutoff;
        }
        router.get(page.url.split('?')[0], params, { preserveScroll: true, preserveState: false });
    };
    const termYear = (term) => term.school_year || term.label?.match(/\d{4}-\d{4}/)?.[0] || '';
    const current = terms.find((term) => String(term.id) === String(selected));
    const academicYear = current ? termYear(current) : '';
    const matching = academicYear ? terms.filter((term) => termYear(term) === academicYear) : [];
    const changeYear = (year) => {
        if (!year) { change(''); return; }
        const candidates = terms.filter((term) => termYear(term) === year);
        const next = candidates.find((term) => term.semester === current?.semester) || candidates[0];
        if (next) change(String(next.id));
    };
    return <div className={`semester-picker-fields${compact ? ' semester-picker-compact' : ''}`}>
        <label>Academic Year<YearFilterInput value={academicYear} academic allowEmpty={!legacyOnly} disabled={disabled}
            allowedYears={[...new Set(terms.map(termYear))]} onYearChange={changeYear} /></label>
        <label>Semester<select value={selected} disabled={disabled || !academicYear} onChange={(event) => change(event.target.value)}>
            <option value="" disabled={legacyOnly}>{legacyOnly ? 'Select semester' : 'All semesters'}</option>
            {matching.map((term) => <option key={term.id} value={term.id} title={term.label}>{term.semester || term.label}</option>)}
        </select></label>
    </div>;
}
