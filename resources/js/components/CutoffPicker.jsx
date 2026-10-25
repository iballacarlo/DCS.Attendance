import { useEffect, useId, useState } from 'react';
import { currentCutoff, cutoffMonths, cutoffPeriod } from '../lib/cutoff';

export default function CutoffPicker({ value, onChange, disabled = false }) {
    const fallback = currentCutoff().start;
    const [year, month, day] = (value || fallback).split('-');
    const [yearDraft, setYearDraft] = useState(year);
    const [error, setError] = useState('');
    const id = useId();
    useEffect(() => { setYearDraft(year); setError(''); }, [year]);
    const commit = (nextYear = yearDraft, nextMonth = month, nextDay = day) => {
        const period = cutoffPeriod(`${nextYear}-${nextMonth}-${nextDay}`);
        if (!period) { setError('Enter a year from 2000 to 2100.'); return; }
        setError(''); onChange(period);
    };
    return <div className="cutoff-picker" role="group" aria-label="Cutoff period">
        <div className="cutoff-picker-fields"><label>Month<select disabled={disabled} value={month} onChange={(event) => commit(undefined, event.target.value)}>{cutoffMonths.map((name, index) => <option value={String(index + 1).padStart(2, '0')} key={name}>{name}</option>)}</select></label>
            <label className="cutoff-picker-year">Year<input type="number" min="2000" max="2100" step="1" disabled={disabled} value={yearDraft} aria-invalid={!!error} aria-describedby={error ? id : undefined} onChange={(event) => setYearDraft(event.target.value)} onBlur={() => { if (yearDraft !== year) commit(); }} onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); event.currentTarget.blur(); } }} /></label>
            <label>Cutoff<select disabled={disabled} value={day} onChange={(event) => commit(undefined, month, event.target.value)}><option value="01">1–15</option><option value="16">16–month end</option></select></label></div>
        {error && <p id={id} className="cutoff-picker-error" role="alert">{error}</p>}
    </div>;
}
