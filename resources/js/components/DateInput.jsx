import { useEffect, useRef, useState } from 'react';
import { CalendarDays } from 'lucide-react';
import { displayInputDate, inputDateError, parseInputDate } from '../lib/dateInputValue.js';
import '../../css/dateInput.css';

// ISO values remain unchanged for requests; visible dates never depend on browser locale.
export default function DateInput({ value = '', onChange, min, max, type = 'date', ...props }) {
    const withTime = type === 'datetime-local';
    const [text, setText] = useState(displayInputDate(value, withTime));
    const input = useRef(null);
    const picker = useRef(null);
    useEffect(() => { setText(displayInputDate(value, withTime)); }, [value, withTime]);
    useEffect(() => { input.current?.setCustomValidity(inputDateError(text, min, max, withTime)); }, [text, min, max, withTime]);
    const update = (event) => {
        const next = event.target.value;
        setText(next);
        const error = inputDateError(next, min, max, withTime);
        event.target.setCustomValidity(error);
        if (!error) onChange?.({ target: { value: parseInputDate(next, withTime) } });
    };
    return <span className="date-input">
        <input {...props} ref={input} type="text" inputMode={withTime ? 'text' : 'numeric'}
            placeholder={withTime ? 'MM/DD/YYYY HH:mm' : 'MM/DD/YYYY'}
            pattern={withTime ? '[0-9]{2}/[0-9]{2}/[0-9]{4} [0-9]{2}:[0-9]{2}' : '[0-9]{2}/[0-9]{2}/[0-9]{4}'}
            maxLength={withTime ? 16 : 10} value={text} onChange={update} />
        <button type="button" className="date-input-open" aria-label={withTime ? 'Choose date and time' : 'Choose date'} disabled={props.disabled || props.readOnly}
            onClick={() => { try { picker.current.showPicker(); } catch { picker.current?.focus(); } }}><CalendarDays size={18} aria-hidden="true" /></button>
        <input ref={picker} className="date-input-native" type={withTime ? 'datetime-local' : 'date'} tabIndex={-1} aria-hidden="true"
            min={min} max={max} value={value || ''} disabled={props.disabled || props.readOnly}
            onChange={(event) => { const next = event.target.value; setText(displayInputDate(next, withTime)); input.current?.setCustomValidity(''); onChange?.({ target: { value: next } }); }} />
    </span>;
}
