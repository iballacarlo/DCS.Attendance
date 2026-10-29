import { Link } from '@inertiajs/react';
import '../../css/attendancePagination.css';

export default function AttendancePagination({ records, perPage = 10, onPerPage, label = 'Attendance pages' }) {
    const pages = records.last_page > 1 ? (records.links || []).slice(1, -1).map((link) => ({ ...link, label: link.label === '...' ? '…' : link.label })) : [];
    const links = [
        { label: 'Previous', url: records.prev_page_url },
        ...pages,
        { label: 'Next', url: records.next_page_url },
    ];
    return <div className="attendance-list-footer">
        <p>Showing <strong>{records.from ?? 0}–{records.to ?? 0}</strong> of <strong>{records.total || 0}</strong> records</p>
        <label>Rows per page<select value={perPage} onChange={(event) => onPerPage(Number(event.target.value))}>{[10, 25, 50].map((size) => <option value={size} key={size}>{size}</option>)}</select></label>
        <nav className="attendance-record-pagination" aria-label={label}>{links.map((link, index) => link.url
            ? <Link key={index} href={link.url} preserveScroll preserveState className={link.active ? 'is-current' : ''} aria-current={link.active ? 'page' : undefined} aria-label={/^\d+$/.test(link.label) ? `Go to page ${link.label}` : link.label}>{link.label}</Link>
            : <span key={index} aria-disabled={link.label !== '…' ? 'true' : undefined}>{link.label}</span>)}
            <span className="attendance-page-position">Page {records.current_page || 1} of {records.last_page || 1}</span>
        </nav>
    </div>;
}
