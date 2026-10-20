import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, ChartNoAxesCombined, ChevronLeft, ChevronRight, Clock3, GraduationCap, KeyRound, LayoutDashboard, LogOut, MailWarning, Moon, Settings2, ShieldCheck, Sun, UserRound, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';
import { dateLabel, fullName, roleLabel } from '../lib/format';

const navigation = [
    { label: 'Dashboard', group: 'Overview', href: '/', icon: LayoutDashboard, tone: 'nav-dashboard', active: (url) => url === '/' },
    { label: 'Faculty', group: 'Faculty Management', href: '/employees', icon: GraduationCap, tone: 'nav-faculty', roles: ['admin'], active: (url) => url.startsWith('/employees') },
    { label: 'Ranks', group: 'Faculty Management', href: '/faculty-ranks', icon: ChartNoAxesCombined, tone: 'nav-ranks', roles: ['admin'], active: (url) => url.startsWith('/faculty-ranks') },
    { label: 'Attendance', group: 'Operations', href: '/attendance', icon: Clock3, tone: 'nav-attendance', active: (url) => url.startsWith('/attendance') },
    { label: 'School Calendar', group: 'Operations', href: '/school-calendar', icon: CalendarDays, tone: 'nav-calendar', active: (url) => url.startsWith('/school-calendar') },
    { label: 'Payroll', group: 'Operations', href: '/payroll', icon: Wallet, tone: 'nav-payroll', active: (url) => url.startsWith('/payroll') },
    { label: 'My Profile', group: 'Account', href: '/profile', icon: UserRound, tone: 'nav-profile', active: (url) => url.startsWith('/profile') },
    { label: 'Settings', group: 'Administration', href: '/settings/accounts', icon: Settings2, tone: 'nav-settings', roles: ['admin'], active: (url) => url.startsWith('/settings') },
];

export default function AppLayout({ title, subtitle, children }) {
    const page = usePage();
    const { auth, flash, errors } = page.props;
    const { url } = page;
    const user = auth.user;
    const [sidebarCollapsed, setSidebarCollapsed] = useState(
        () => window.localStorage.getItem('sidebar-collapsed') === 'true',
    );
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
    const availableNavigation = navigation.filter((item) => !item.roles || item.roles.includes(user.role));
    const navigationGroups = [...new Set(availableNavigation.map((item) => item.group))];
    const mobileWeekday = new Intl.DateTimeFormat('en-PH', { weekday: 'long' }).format(clock);
    const mobileDate = new Intl.DateTimeFormat('en-PH', {
        month: 'short', day: 'numeric', year: 'numeric',
    }).format(clock);
    const module = url.startsWith('/employees') ? 'faculty'
        : url.startsWith('/faculty-ranks') ? 'ranks'
            : url.startsWith('/settings') ? 'settings'
                : url.startsWith('/profile') ? 'profile'
                : url.startsWith('/attendance') ? 'attendance'
                    : url.startsWith('/school-calendar') ? 'calendar'
                    : url.startsWith('/payroll') ? 'payroll'
                        : 'dashboard';
    const logout = () => router.post('/logout');

    return (
        <>
            <Head title={`${title} | Payroll System`} />
            <aside className={`sidebar${sidebarCollapsed ? ' is-collapsed' : ''}`}>
                <div className="brand">
                    <Link className="brand-logo-link" href="/" aria-label="Go to dashboard">
                        <div className="brand-logos">
                            <img src="/images/cvsu-logo.png" alt="Cavite State University logo" />
                        </div>
                    </Link>
                    <div className="brand-copy">
                        <small>Cavite State University</small>
                        <strong>Imus Campus</strong>
                        <span>DCS Payroll</span>
                    </div>
                </div>
                <nav aria-label="Main navigation">
                    {navigationGroups.map((group) => <div className="nav-group" key={group}>
                        <div className="sidebar-section-label">{group}</div>
                        {availableNavigation.filter((item) => item.group === group).map((item) => (
                            <Link key={item.href} href={item.href} title={sidebarCollapsed ? item.label : undefined} className={`${item.tone}${item.active(url) ? ' active' : ''}`} aria-current={item.active(url) ? 'page' : undefined}>
                                <span className="nav-icon"><item.icon size={18} strokeWidth={1.8} /></span>
                                <span className="nav-copy">{item.label}</span>
                            </Link>
                        ))}
                    </div>)}
                </nav>
                <div className="sidebar-account">
                    <ShieldCheck size={16} />
                    <span><small>Signed in as</small><strong>{user.employee ? fullName(user.employee) : user.name}</strong></span>
                </div>
                <button className="logout-button" type="button" onClick={logout} aria-label="Logout"><LogOut size={17} /><span>Logout</span></button>
            </aside>
            <div className="mobile-branding">
                <div className="mobile-branding-identity">
                    <Link className="mobile-logo-link" href="/" aria-label="Go to dashboard">
                        <img src="/images/cvsu-logo.png" alt="Cavite State University logo" />
                    </Link>
                    <span><small>Cavite State University</small><strong>Imus Campus</strong><b>DCS Payroll</b></span>
                </div>
                <div className="mobile-branding-actions">
                    <button className="mobile-theme-toggle" type="button" onClick={toggleTheme} aria-label={darkMode ? 'Use light mode' : 'Use dark mode'} title={darkMode ? 'Use light mode' : 'Use dark mode'}>{darkMode ? <Sun size={18} /> : <Moon size={18} />}</button>
                    <button className="mobile-logout" type="button" onClick={logout} aria-label="Logout"><LogOut size={17} /><span>Logout</span></button>
                </div>
            </div>
            <button className={`sidebar-toggle${sidebarCollapsed ? ' is-collapsed' : ''}`} type="button" onClick={toggleSidebar} aria-label={sidebarCollapsed ? 'Expand sidebar' : 'Minimize sidebar'} title={sidebarCollapsed ? 'Expand sidebar' : 'Minimize sidebar'}>{sidebarCollapsed ? <ChevronRight size={18} strokeWidth={2.5} aria-hidden="true" /> : <ChevronLeft size={18} strokeWidth={2.5} aria-hidden="true" />}</button>
            <main className={`main module-${module}${sidebarCollapsed ? ' sidebar-collapsed' : ''}`}>
                <nav className="mobile-navigation" aria-label="Mobile navigation" style={{ '--mobile-nav-count': availableNavigation.length }}>
                    {availableNavigation.map((item) => (
                        <Link key={item.href} href={item.href} className={`${item.tone}${item.active(url) ? ' active' : ''}`} aria-label={item.label} title={item.label} aria-current={item.active(url) ? 'page' : undefined}>
                            <span className="nav-icon"><item.icon size={18} strokeWidth={1.8} /></span>
                            <span>{item.label}</span>
                        </Link>
                    ))}
                </nav>
                <header className="topbar">
                    <div className="editorial-heading">
                        <div>
                            <h1>{title}</h1>
                            <p>{subtitle}</p>
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
                {user.must_change_password && <section className="password-change-reminder" role="status"><KeyRound size={20} /><div><strong>Change your temporary password</strong><span>Your account is still using the temporary password issued by the administrator.</span></div><Link href="/profile">Change password</Link></section>}
                {user.role === 'faculty' && !user.email_verified && <section className="email-verification-reminder" role="status"><MailWarning size={20} /><div><strong>Verify your email address</strong><span>Open the verification link sent to {user.email}. This confirms that the address belongs to you.</span></div><button type="button" onClick={() => router.post('/email/verification-notification', {}, { preserveScroll: true })}>Resend link</button></section>}
                {flash?.success && <div className="alert success">{flash.success}</div>}
                {flash?.error && <div className="alert error">{flash.error}</div>}
                {Object.keys(errors || {}).length > 0 && <div className="alert error">{Object.values(errors)[0]}</div>}
                {children}
            </main>
        </>
    );
}
