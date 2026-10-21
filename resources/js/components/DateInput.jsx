import { useEffect, useRef, useState } from 'react';
import { formatDate } from '../lib/format';

// Keep API/form values as ISO while displaying an explicit, locale-independent date.
export default function DateInput({ value = '', onChange, min, max, ...props }) {
    const [text, setText] = useState(value ? formatDate(value) : '');
    const input = useRef(null);
    useEffect(() => { setText(value ? formatDate(value) : ''); input.current?.setCustomValidity(''); }, [value]);
    const update = (event) => {
        const text = event.target.value;
        setText(text);
        if (!text) { event.target.setCustomValidity(''); onChange?.({ target: { value: '' } }); return; }
        const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(text);
        const iso = match ? `${match[3]}-${match[1]}-${match[2]}` : '';
        const date = iso ? new Date(`${iso}T12:00:00`) : null;
        const valid = date && !Number.isNaN(date.getTime()) && date.getFullYear() === Number(match[3]) && date.getMonth() + 1 === Number(match[1]) && date.getDate() === Number(match[2]);
        const inRange = valid && (!min || iso >= min) && (!max || iso <= max);
        event.target.setCustomValidity(inRange ? '' : `Enter a valid date in MM/DD/YYYY format${min ? ` on or after ${formatDate(min)}` : ''}${max ? ` on or before ${formatDate(max)}` : ''}.`);
        if (inRange) onChange?.({ target: { value: iso } });
    };
    return <input {...props} ref={input} type="text" inputMode="numeric" placeholder="MM/DD/YYYY" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" maxLength={10} value={text} onChange={update} />;
}
