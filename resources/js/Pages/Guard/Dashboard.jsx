import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, LogOut, ShieldCheck } from 'lucide-react';
import '../../../css/kiosk.css';

export default function GuardDashboard() {
    const { auth } = usePage().props;
    return <>
        <Head title="Dashboard | DCS Attendance & DTR" />
        <main className="guard-kiosk">
            <header className="guard-kiosk-header"><div className="kiosk-brand"><img src="/images/cvsu-logo.png" alt="Cavite State University" /><span><strong>DCS Attendance &amp; DTR</strong><small>Cavite State University · Imus Campus</small></span></div><button type="button" className="kiosk-signout" aria-label="Sign out" onClick={() => router.post('/logout')}><LogOut size={16} /><span>Sign out</span></button></header>
            <section className="guard-home" aria-labelledby="guard-home-title">
                <ShieldCheck size={36} strokeWidth={1.5} />
                <h1 id="guard-home-title">Dashboard</h1>
                <p>Welcome, {auth.user.name}.</p>
                <Link className="guard-home-open" href="/entrance-kiosk">Open Attendance Kiosk<ArrowUpRight size={20} /></Link>
            </section>
        </main>
    </>;
}
