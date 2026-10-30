import { Link, usePage } from '@inertiajs/react';
import { CalendarDays, FileSignature } from 'lucide-react';

export default function SettingsTabs() {
    const { url, props } = usePage();
    const semesters = url.startsWith('/settings/semesters');
    const holidays = url.startsWith('/settings/holidays-suspensions') || url.startsWith('/school-calendar');
    const signatories = url.startsWith('/settings/dtr-signatories');
    if (props.auth.user.role !== 'admin' || (!semesters && !holidays && !signatories)) return null;

    return <nav className="settings-tabs" aria-label="Academic setup sections">
        <Link href="/settings/semesters" aria-current={semesters ? 'page' : undefined} className={semesters ? 'active' : ''}><CalendarDays size={17} />Academic Years &amp; Semesters</Link>
        <Link href="/settings/holidays-suspensions" aria-current={holidays ? 'page' : undefined} className={holidays ? 'active' : ''}><CalendarDays size={17} />Holidays &amp; Suspensions</Link>
        <Link href="/settings/dtr-signatories" aria-current={signatories ? 'page' : undefined} className={signatories ? 'active' : ''}><FileSignature size={17} />Dean Management</Link>
    </nav>;
}
