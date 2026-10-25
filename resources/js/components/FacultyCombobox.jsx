import { useEffect, useId, useState } from 'react';
import { fullName } from '../lib/format';

export default function FacultyCombobox({ employees = [], selectedId = '', selectedIds = [], multiple = false, disabled = false, onSelect, label = 'Faculty', allowClear = false, clearLabel = 'All faculty', className = '' }) {
    const id = useId();
    const chosen = (item) => multiple ? selectedIds.some((value) => String(value) === String(item.id)) : String(item.id) === String(selectedId);
    const selectedName = employees.filter(chosen).map(fullName).join('; ');
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    useEffect(() => { if (!multiple) setOpen(false); }, [selectedId, multiple]);
    const terms = query.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
    const matches = employees.filter((item) => terms.every((term) => fullName(item).toLocaleLowerCase().includes(term)));
    const options = allowClear ? [{ id: '', clear: true }, ...matches] : matches;
    useEffect(() => { if (open) document.getElementById(`${id}-option-${active}`)?.scrollIntoView({ block: 'nearest' }); }, [active, open, query]);
    const close = () => setOpen(false);
    const choose = (item) => {
        if (!item || disabled) return;
        if (multiple) {
            onSelect(item.clear ? [] : chosen(item) ? selectedIds.filter((value) => String(value) !== String(item.id)) : [...selectedIds, item.id]);
            setQuery(''); return;
        }
        setOpen(false);
        if (item.clear || String(item.id) !== String(selectedId)) onSelect(item.id);
    };
    const keyDown = (event) => {
        if (event.key === 'Escape') { event.preventDefault(); close(); }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!open) { setQuery(''); setActive(0); setOpen(true); return; }
            setActive((current) => Math.max(0, Math.min(options.length - 1, current + (event.key === 'ArrowDown' ? 1 : -1))));
        }
        if (event.key === 'Enter' && open) { event.preventDefault(); choose(options[active]); }
    };
    return <div className={`dtr-faculty-select faculty-combobox ${className}`}>
        {label && <label htmlFor={id}>{label}</label>}
        <input id={id} role="combobox" aria-label={label || 'Faculty'} aria-autocomplete="list" aria-expanded={open} aria-controls={`${id}-list`} aria-activedescendant={open && options[active] ? `${id}-option-${active}` : undefined}
            autoComplete="off" placeholder={allowClear ? clearLabel : 'Type faculty name'} disabled={disabled} value={open ? query : selectedName}
            onFocus={() => { setQuery(''); setActive(0); setOpen(true); }} onClick={() => { if (!open) { setQuery(''); setActive(0); setOpen(true); } }} onBlur={close} onKeyDown={keyDown}
            onChange={(event) => { setQuery(event.target.value); setActive(0); setOpen(true); }} />
        {open && <div id={`${id}-list`} role="listbox" aria-label="Faculty names" aria-multiselectable={multiple || undefined} className="faculty-combobox-list">
            {options.map((item, index) => <button type="button" role="option" aria-selected={item.clear ? !selectedName : chosen(item)} id={`${id}-option-${index}`} key={item.clear ? 'all' : item.id} tabIndex={-1}
                className={`faculty-combobox-option${index === active ? ' is-active' : ''}`} onMouseDown={(event) => event.preventDefault()} onClick={() => choose(item)}>{item.clear ? clearLabel : <>{multiple && chosen(item) ? '✓ ' : ''}{fullName(item)}</>}</button>)}
            {!matches.length && <div className="faculty-combobox-empty" role="status">No matching faculty.</div>}
        </div>}
    </div>;
}
