import { useEffect, useRef } from 'react';
import { ChevronDown } from 'lucide-react';

export default function ReportPopover({ label, children, className = '' }) {
    const ref = useRef(null);
    useEffect(() => {
        const dismiss = (event) => {
            const element = ref.current;
            if (!element?.open) return;
            if (event.type === 'keydown' && event.key === 'Escape') {
                if (!element.contains(document.activeElement)) return;
                event.preventDefault(); element.open = false; element.querySelector('summary')?.focus();
            } else if (event.type === 'pointerdown' && !element.contains(event.target)) element.open = false;
        };
        document.addEventListener('pointerdown', dismiss);
        document.addEventListener('keydown', dismiss);
        return () => { document.removeEventListener('pointerdown', dismiss); document.removeEventListener('keydown', dismiss); };
    }, []);
    return <details ref={ref} className={`report-popover ${className}`}>
        <summary className="ui-control ui-action ui-secondary">{label}<ChevronDown size={15} aria-hidden="true" /></summary>
        <div className="report-popover-body" onClick={(event) => { if (event.target.closest('[data-close-popover]')) ref.current.open = false; }}>{children}</div>
    </details>;
}
