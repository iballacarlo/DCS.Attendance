import { Link } from '@inertiajs/react';

export default function Pagination({ links }) {
    if (!links || links.length <= 3) return null;
    return <nav className="pagination" aria-label="Pagination">{links.map((link, index) => (
        link.url ? <Link key={index} href={link.url} aria-current={link.active ? 'page' : undefined} className={link.active ? 'active' : ''} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} aria-disabled="true" dangerouslySetInnerHTML={{ __html: link.label }} />
    ))}</nav>;
}
