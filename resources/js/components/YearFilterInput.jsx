export default function YearFilterInput({ value, onYearChange, academic = false, allowEmpty = false, allowedYears, disabled = false }) {
    const normalize = (raw, final = false) => {
        if (raw === '' && allowEmpty) return '';
        if (academic && final && /^\d{4}$/.test(raw)) raw = `${raw}-${Number(raw) + 1}`;
        if (!(academic ? /^\d{4}-\d{4}$/ : /^\d{4}$/).test(raw)) return null;
        const year = Number(raw.slice(0, 4));
        if (year < 2000 || year > 2100 || (academic && Number(raw.slice(5)) !== year + 1)) return null;
        return allowedYears && !allowedYears.includes(raw) ? null : raw;
    };
    const update = (event, final = false) => {
        if (!final && event.currentTarget.value === '') return;
        const next = normalize(event.currentTarget.value, final);
        if (next !== null) {
            event.currentTarget.value = next;
            if (next !== (event.currentTarget.dataset.committedYear ?? String(value ?? ''))) {
                event.currentTarget.dataset.committedYear = next;
                onYearChange(next);
            }
        } else if (final) event.currentTarget.value = event.currentTarget.dataset.committedYear ?? String(value ?? '');
    };
    return <input key={`${value}-${academic}`} type="text" inputMode="numeric" maxLength={academic ? 9 : 4}
        defaultValue={value ?? ''} disabled={disabled} placeholder={allowEmpty ? (academic ? 'All academic years' : 'All years') : academic ? 'YYYY-YYYY' : 'YYYY'}
        aria-label={academic ? 'Academic Year' : 'Year'} title={academic ? 'Type an academic year, for example 2026-2027. You can also type 2026 and press Enter.' : 'Type a four-digit year.'}
        onChange={(event) => update(event)} onBlur={(event) => update(event, true)}
        onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); update(event, true); } }} />;
}
