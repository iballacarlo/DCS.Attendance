import { Head, Link, router, usePage } from '@inertiajs/react';
import { Archive, CalendarDays, ChartNoAxesCombined, ChevronDown, ChevronLeft, ChevronRight, Clock3, DatabaseBackup, FileCheck2, GraduationCap, KeyRound, LayoutDashboard, LogOut, MailWarning, Menu, Moon, Settings2, ShieldCheck, Sun, UserRound, Users, X } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { dateLabel, fullName, roleLabel } from '../lib/format';
import '../../css/sidebarNavigation.css';

const navigation = [
    { id: 'dashboard', label: 'Dashboard', group: 'Overview', href: '/', icon: LayoutDashboard, tone: 'nav-dashboard', active: (url) => url === '/' },
    { id: 'faculty', label: 'Faculty', group: 'Faculty Management', icon: GraduationCap, roles: ['admin'], children: [
        { label: 'Faculty List', href: '/employees', icon: GraduationCap, tone: 'nav-faculty', active: (url) => url.startsWith('/employees') },
        { label: 'Archived Faculty', href: '/archived-faculty', icon: Archive, tone: 'nav-faculty', active: (url) => url.startsWith('/archived-faculty') },
        { label: 'Ranks', href: '/faculty-ranks', icon: ChartNoAxesCombined, tone: 'nav-ranks', active: (url) => url.startsWith('/faculty-ranks') },
    ] },
    { id: 'school', label: 'School Attendance', group: 'Operations', icon: Clock3, children: [
        { label: 'Attendance Records', mobileLabel: 'School Attendance', href: '/entrance', icon: Clock3, tone: 'nav-attendance', active: (url) => url.startsWith('/entrance') },
        { label: 'Daily Time Record', href: '/attendance/dtr', icon: FileCheck2, tone: 'nav-attendance', active: (url) => url.startsWith('/attendance/dtr') },
    ] },
    { id: 'classes', label: 'Class Attendance', group: 'Operations', icon: GraduationCap, children: [
        { label: 'Attendance Logs', mobileLabel: 'Class Attendance', href: '/attendance', icon: Clock3, tone: 'nav-attendance', active: (url) => url === '/attendance' },
        { label: 'Reports & Analytics', mobileLabel: 'Class Reports & Analytics', href: '/class-reports', icon: ChartNoAxesCombined, tone: 'nav-attendance', roles: ['admin'], active: (url) => url.startsWith('/class-reports') },
    ] },
    { id: 'schedule', label: 'My Schedule', group: 'Operations', href: '/my-schedule', icon: CalendarDays, tone: 'nav-calendar', roles: ['faculty'], active: (url) => url.startsWith('/my-schedule') },
    { id: 'leave', label: 'Leave', group: 'Operations', href: '/leave', icon: FileCheck2, tone: 'nav-leave', active: (url) => url.startsWith('/leave') },
    { id: 'settings', label: 'Settings', group: 'Administration', icon: Settings2, roles: ['admin'], mobileHref: '/settings/semesters', children: [
        { label: 'Academic Setup', href: '/settings/semesters', icon: CalendarDays, tone: 'nav-settings', active: (url) => url.startsWith('/settings/semesters') || url.startsWith('/settings/holidays-suspensions') || url.startsWith('/school-calendar') || url.startsWith('/settings/dtr-signatories') },
        { label: 'Accounts', href: '/settings/accounts', icon: Users, tone: 'nav-settings', active: (url) => url.startsWith('/settings/accounts') },
        { label: 'Backup & Recovery', href: '/settings/backup', icon: DatabaseBackup, tone: 'nav-settings', active: (url) => url.startsWith('/settings/backup') },
    ] },
    { label: 'My Profile', group: 'Account', href: '/profile', icon: UserRound, tone: 'nav-profile', roles: ['admin'], active: (url) => url.startsWith('/profile') },
    { id: 'faculty-settings', label: 'Settings', group: 'Account', icon: Settings2, roles: ['faculty'], children: [
        { label: 'Holidays & Suspensions', href: '/school-calendar', icon: CalendarDays, tone: 'nav-calendar', active: (url) => url.startsWith('/school-calendar') },
        { label: 'My Profile & Password', href: '/profile', icon: UserRound, tone: 'nav-profile', active: (url) => url.startsWith('/profile') },
    ] },
];

const isActive = (item, path) => item.children ? item.children.some((child) => isActive(child, path)) : item.active(path);

function visibleNavigation(role) {
    const items = navigation.filter((item) => !item.roles || item.roles.includes(role)).map((item) => {
        const children = item.children?.filter((child) => !child.roles || child.roles.includes(role));

        // Faculty only has access to the attendance log. Keep it as one clear
        // destination instead of showing an expandable menu with a single item.
        if (role === 'faculty' && item.id === 'classes' && children?.length === 1) {
            const [child] = children;
            return {
                ...child,
                id: item.id,
                group: item.group,
                label: item.label,
                mobileLabel: item.label,
                icon: item.icon,
            };
        }

        if (role === 'faculty' && item.id === 'school' && children) {
            return {
                ...item,
                children: children.map((child) => child.href === '/entrance'
                    ? { ...child, label: 'My school attendance' }
                    : { ...child, label: 'My Daily Time Record' }),
            };
        }

        return children ? { ...item, children } : item;
    });

    if (role !== 'faculty') return items;

    const facultyOrder = ['dashboard', 'school', 'classes', 'schedule', 'leave', 'faculty-settings'];
    return items.sort((left, right) => facultyOrder.indexOf(left.id) - facultyOrder.indexOf(right.id));
}

function savedMenus(role) {
    try {
        const saved = JSON.parse(window.localStorage.getItem(`sidebar-menus-${role}`) || '{}');
        return saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
    } catch { return {}; }
}

export default function AppLayout({ title, subtitle, children }) {
    const page = usePage();
    const { auth, flash, errors } = page.props;
    const { url } = page;
    const user = auth.user;
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const sidebar = useRef(null);
    const mobileMenuTrigger = useRef(null);
    const sidebarNavigation = useRef(null);
    const sidebarScrollKey = `sidebar-scroll-${user.id}`;
    const rememberSidebarScroll = () => {
        if (!sidebarNavigation.current?.getClientRects().length) return;
        try { window.sessionStorage.setItem(sidebarScrollKey, String(sidebarNavigation.current.scrollTop)); } catch { /* Scrolling still works when storage is unavailable. */ }
    };
    // Each page mounts its own layout. Restore only the sidebar, before paint;
    // the page content should retain Inertia's normal scroll behavior.
    useLayoutEffect(() => {
        const navigation = sidebarNavigation.current;
        if (!navigation?.getClientRects().length) return;
        try {
            const position = Number(window.sessionStorage.getItem(sidebarScrollKey));
            if (Number.isFinite(position) && position >= 0) navigation.scrollTop = position;
        } catch { /* Use the default position when storage is unavailable. */ }
    }, [sidebarScrollKey, mobileMenuOpen]);
    const path = url.split('?')[0];
    const availableNavigation = visibleNavigation(user.role);
    const [expandedMenus, setExpandedMenus] = useState(() => ({ ...savedMenus(user.role), ...Object.fromEntries(availableNavigation.filter((item) => item.children && isActive(item, path)).map((item) => [item.id, true])) }));
    useEffect(() => {
        setExpandedMenus((previous) => ({ ...previous, ...Object.fromEntries(visibleNavigation(user.role).filter((item) => item.children && isActive(item, path)).map((item) => [item.id, true])) }));
    }, [path, user.role]);
    useEffect(() => {
        try { window.localStorage.setItem(`sidebar-menus-${user.role}`, JSON.stringify(expandedMenus)); } catch { /* Navigation still works when storage is unavailable. */ }
    }, [expandedMenus, user.role]);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(
        () => window.localStorage.getItem('sidebar-collapsed') === 'true',
    );
    const navigationCollapsed = sidebarCollapsed && !mobileMenuOpen;
    useEffect(() => {
        if (!mobileMenuOpen) return;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const trigger = mobileMenuTrigger.current;
        sidebar.current?.querySelector('.mobile-sidebar-close')?.focus({ preventScroll: true });
        const handleKey = (event) => {
            if (event.key === 'Escape') { event.preventDefault(); setMobileMenuOpen(false); }
            if (event.key !== 'Tab') return;
            const controls = [...sidebar.current.querySelectorAll('a[href], button:not(:disabled)')].filter((element) => element.getClientRects().length && !element.closest('[inert]'));
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        };
        const desktop = window.matchMedia('(min-width: 901px)');
        const closeOnDesktop = () => { if (desktop.matches) setMobileMenuOpen(false); };
        document.addEventListener('keydown', handleKey);
        desktop.addEventListener('change', closeOnDesktop);
        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', handleKey);
            desktop.removeEventListener('change', closeOnDesktop);
            trigger?.focus({ preventScroll: true });
        };
    }, [mobileMenuOpen]);
    const [clock, setClock] = useState(new Date());
    const [darkMode, setDarkMode] = useState(() => document.documentElement.dataset.theme === 'dark');
    useEffect(() => {
        const timer = window.setInterval(() => setClock(new Date()), 60000);
        return () => window.clearInterval(timer);
    }, []);
    const toggleSidebar = () => {
        const next = !sidebarCollapsed;
        setSidebarCollapsed(next);
        window.localStorage.setItem('sidebar-collapsed', String(next));
    };
    const toggleTheme = () => {
        const nextTheme = darkMode ? 'light' : 'dark';
        document.documentElement.dataset.theme = nextTheme;
        window.localStorage.setItem('theme', nextTheme);
        setDarkMode(!darkMode);
    };
    const navigationGroups = [...new Set(availableNavigation.map((item) => item.group))];
    const toggleMenu = (id) => {
        if (navigationCollapsed) {
            setSidebarCollapsed(false);
            window.localStorage.setItem('sidebar-collapsed', 'false');
            setExpandedMenus((previous) => ({ ...previous, [id]: true }));
        } else setExpandedMenus((previous) => ({ ...previous, [id]: !previous[id] }));
    };
    const navigationLink = (item) => <Link key={item.href} href={item.href} onClick={() => setMobileMenuOpen(false)} title={navigationCollapsed ? item.label : undefined} aria-label={item.label} className={`${item.tone}${isActive(item, path) ? ' active' : ''}`} aria-current={isActive(item, path) ? 'page' : undefined}>
        <span className="nav-icon"><item.icon size={18} strokeWidth={1.8} /></span>
        <span className="nav-copy">{item.label}</span>
    </Link>;
    const mobileWeekday = new Intl.DateTimeFormat('en-PH', { weekday: 'long' }).format(clock);
    const mobileDate = dateLabel(clock);
    const module = (url.startsWith('/employees') || url.startsWith('/archived-faculty')) ? 'faculty'
        : url.startsWith('/faculty-ranks') ? 'ranks'
            : url.startsWith('/settings') ? 'settings'
                : url.startsWith('/leave') ? 'leave'
                : url.startsWith('/profile') ? 'profile'
                : url.startsWith('/attendance') ? 'attendance'
                    : url.startsWith('/school-calendar') ? 'calendar'
                        : 'dashboard';
    const logout = () => router.post('/logout');

    return (
        <>
            <Head title={`${title} | DCS Attendance & DTR`} />
            {mobileMenuOpen && <button type="button" className="sidebar-backdrop" tabIndex={-1} aria-label="Close navigation menu" onClick={() => setMobileMenuOpen(false)} />}
            <aside ref={sidebar} id="main-sidebar" className={`sidebar${navigationCollapsed ? ' is-collapsed' : ''}${mobileMenuOpen ? ' is-mobile-open' : ''}`} role={mobileMenuOpen ? 'dialog' : undefined} aria-modal={mobileMenuOpen ? true : undefined} aria-label={mobileMenuOpen ? 'Navigation menu' : undefined}>
                <div className="mobile-sidebar-heading"><strong>Menu</strong><button type="button" className="mobile-sidebar-close" aria-label="Close navigation menu" onClick={() => setMobileMenuOpen(false)}><X size={22} /></button></div>
                <div className="brand">
                    <Link className="brand-logo-link" href="/" aria-label="Go to dashboard">
                        <div className="brand-logos">
                            <img src="/images/cvsu-logo.png" alt="Cavite State University logo" />
                        </div>
                    </Link>
                    <div className="brand-copy">
                        <small>Cavite State University</small>
                        <strong>Imus Campus</strong>
                        <span>DCS Attendance & DTR</span>
                    </div>
                </div>
                <nav ref={sidebarNavigation} onScroll={rememberSidebarScroll} aria-label="Main navigation">
                    {navigationGroups.map((group) => <div className="nav-group" key={group}>
                        <div className="sidebar-section-label">{group}</div>
                        {availableNavigation.filter((item) => item.group === group).map((item) => item.children ? <div className="sidebar-menu" key={item.id}>
                            <button type="button" className={`sidebar-menu-toggle${isActive(item, path) ? ' contains-current' : ''}`} onClick={() => toggleMenu(item.id)} aria-label={item.label} title={navigationCollapsed ? item.label : undefined} aria-expanded={!navigationCollapsed && !!expandedMenus[item.id]} aria-controls={`sidebar-submenu-${item.id}`}>
                                <span className="nav-icon"><item.icon size={18} strokeWidth={1.8} /></span>
                                <span className="nav-copy">{item.label}</span>
                                <ChevronDown size={15} className={`sidebar-menu-chevron${expandedMenus[item.id] ? ' is-open' : ''}`} aria-hidden="true" />
                            </button>
                            <div id={`sidebar-submenu-${item.id}`} className={`sidebar-submenu${!navigationCollapsed && expandedMenus[item.id] ? ' is-open' : ''}`} aria-hidden={navigationCollapsed || !expandedMenus[item.id]} inert={navigationCollapsed || !expandedMenus[item.id] ? true : undefined}>
                                <div className="sidebar-submenu-inner">{item.children.map(navigationLink)}</div>
                            </div>
                        </div> : navigationLink(item))}
                    </div>)}
                </nav>
                <div className="sidebar-account">
                    <ShieldCheck size={16} />
                    <span><small>Signed in as</small><strong>{user.employee ? fullName(user.employee) : user.name}</strong></span>
                </div>
                <button className="logout-button" type="button" onClick={logout} aria-label="Logout"><LogOut size={17} /><span>Logout</span></button>
            </aside>
            <div className="mobile-branding" inert={mobileMenuOpen ? true : undefined}>
                <div className="mobile-branding-identity">
                    <Link className="mobile-logo-link" href="/" aria-label="Go to dashboard">
                        <img src="/images/cvsu-logo.png" alt="Cavite State University logo" />
                    </Link>
                    <span><small>Cavite State University</small><strong>Imus Campus</strong><b>DCS Attendance & DTR</b></span>
                </div>
                <div className="mobile-branding-actions">
                    <button className="mobile-theme-toggle" type="button" onClick={toggleTheme} aria-label={darkMode ? 'Use light mode' : 'Use dark mode'} title={darkMode ? 'Use light mode' : 'Use dark mode'}>{darkMode ? <Sun size={18} /> : <Moon size={18} />}</button>
                    <button ref={mobileMenuTrigger} className="mobile-menu-trigger" type="button" onClick={() => setMobileMenuOpen(true)} aria-expanded={mobileMenuOpen} aria-controls="main-sidebar" aria-haspopup="dialog"><Menu size={21} /><span>Menu</span></button>
                </div>
            </div>
            <button className={`sidebar-toggle${sidebarCollapsed ? ' is-collapsed' : ''}`} type="button" onClick={toggleSidebar} aria-label={sidebarCollapsed ? 'Expand sidebar' : 'Minimize sidebar'} title={sidebarCollapsed ? 'Expand sidebar' : 'Minimize sidebar'}>{sidebarCollapsed ? <ChevronRight size={18} strokeWidth={2.5} aria-hidden="true" /> : <ChevronLeft size={18} strokeWidth={2.5} aria-hidden="true" />}</button>
            <main id="workspace" className={`main module-${module}${sidebarCollapsed ? ' sidebar-collapsed' : ''}`} inert={mobileMenuOpen ? true : undefined}>
                <header className="topbar">
                    <div className="editorial-heading">
                        <div>
                            <h1>{title}</h1>
                            {subtitle && <p>{subtitle}</p>}
                        </div>
                    </div>
                    <div className="topbar-meta">
                        <span className="date-chip">
                            <CalendarDays size={16} />
                            <span className="desktop-date-label">{dateLabel(clock)}</span>
                            <span className="mobile-date-label"><strong>{mobileWeekday}</strong><span>{mobileDate}</span></span>
                        </span>
                        <button className="theme-toggle" type="button" onClick={toggleTheme} aria-label={darkMode ? 'Use light mode' : 'Use dark mode'} title={darkMode ? 'Use light mode' : 'Use dark mode'}>{darkMode ? <Sun size={18} /> : <Moon size={18} />}</button>
                        <Link className="user-chip" href="/profile" title="View and edit your profile"><span className="user-avatar">{(user.employee ? fullName(user.employee) : user.name).charAt(0)}</span><span className="user-copy"><strong>{user.employee ? fullName(user.employee) : user.name}</strong><small>{roleLabel(user.role)}</small></span></Link>
                    </div>
                </header>
                {user.must_change_password && <section className="password-change-reminder" role="status"><KeyRound size={20} /><div><strong>Change your temporary password</strong><span>Your account is still using the temporary password issued by the administrator.</span></div><Link href="/account/password">Change password</Link></section>}
                {user.role === 'faculty' && !user.email_verified && <section className="email-verification-reminder" role="status"><MailWarning size={20} /><div><strong>Verify your email address</strong><span>Open the verification link sent to {user.email}. This confirms that the address belongs to you.</span></div><button type="button" onClick={() => router.post('/email/verification-notification', {}, { preserveScroll: true })}>Resend link</button></section>}
                {flash?.success && <div className="alert success">{flash.success}</div>}
                {flash?.error && <div className="alert error">{flash.error}</div>}
                {Object.keys(errors || {}).length > 0 && <div className="alert error">{Object.values(errors)[0]}</div>}
                {children}
            </main>
        </>
    );
}
