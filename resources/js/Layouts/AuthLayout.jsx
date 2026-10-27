import { Head } from '@inertiajs/react';
import { CalendarClock, ScanLine, ShieldCheck } from 'lucide-react';

export default function AuthLayout({ title, children }) {
    return <>
        <Head title={`${title} | DCS Attendance & DTR`} />
        <main className="login-page">
            <div className="login-backdrop" />
            <div className="login-overlay" />
            <header className="login-nav">
                <div className="login-nav-brand">
                    <div className="brand-logos"><img src="/images/cvsu-logo.png" alt="Cavite State University logo" /><img src="/images/dcs-logo.png" alt="Department of Computer Studies logo" /></div>
                    <div><strong>CvSU Imus</strong><span>DCS Attendance & DTR</span></div>
                </div>
                <span className="login-campus">Department of Computer Studies</span>
            </header>

            <section className="login-hero">
                <div className="login-copy">
                    <span className="login-eyebrow">Department of Computer Studies</span>
                    <h1>Class Attendance<br />&amp; Daily Time Record</h1>
                    <p>RFID-BASED CLASS ATTENDANCE MONITORING WITH DAILY TIME RECORD GENERATION SYSTEM FOR CONTRACT OF SERVICE FACULTY MEMBERS OF THE DEPARTMENT OF COMPUTER STUDIES</p>
                </div>
                {children}
            </section>

            <section className="login-proof" aria-label="System capabilities">
                <div><ScanLine size={22} /><span><strong>RFID &amp; Biometrics</strong><small>Reliable attendance capture</small></span></div>
                <div><CalendarClock size={22} /><span><strong>Schedule Based</strong><small>School attendance for daily time records</small></span></div>
                <div><ShieldCheck size={22} /><span><strong>Role Secured</strong><small>Access for authorized personnel</small></span></div>
            </section>
        </main>
    </>;
}
