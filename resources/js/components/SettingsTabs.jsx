import { Link, usePage } from '@inertiajs/react';
import { CalendarRange, DatabaseBackup, FileSignature, Users } from 'lucide-react';

export default function SettingsTabs() {
    const { url, props } = usePage();
    const isAdmin = props.auth.user.role === 'admin';
    return <nav className="settings-tabs" aria-label="Settings sections">
        {isAdmin && <Link href="/settings/general" className={url.startsWith('/settings/general') ? 'active' : ''}><CalendarRange size={17} />General</Link>}
        {isAdmin && <Link href="/settings/accounts" className={url.startsWith('/settings/accounts') ? 'active' : ''}><Users size={17} />Accounts</Link>}
        <Link href="/settings/dtr-signatories" className={url.startsWith('/settings/dtr-signatories') ? 'active' : ''}><FileSignature size={17} />DTR Signatory</Link>
        {isAdmin && <Link href="/settings/backup" className={url.startsWith('/settings/backup') ? 'active' : ''}><DatabaseBackup size={17} />Backup &amp; Recovery</Link>}
    </nav>;
}
