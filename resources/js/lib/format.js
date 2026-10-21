export const fullName = (person) => {
    if (!person) return '-';
    const middleInitial = person.middle_name?.trim().charAt(0);
    return `${person.last_name}, ${person.first_name}${middleInitial ? ` ${middleInitial}.` : ''}${person.suffix?.trim() ? ` ${person.suffix.trim()}` : ''}`;
};

export const money = (value) => `PHP ${Number(value || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})}`;

export const time12 = (value) => {
    if (!value) return '-';
    const [hours, minutes] = String(value).slice(0, 5).split(':').map(Number);
    const suffix = hours >= 12 ? 'PM' : 'AM';
    const hour = hours % 12 || 12;
    return `${String(hour).padStart(2, '0')}:${String(minutes).padStart(2, '0')} ${suffix}`;
};

export const dateToday = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());

export const formatDate = (value) => {
    if (!value) return '-';
    const iso = value instanceof Date ? new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(value) : String(value).slice(0, 10);
    const [year, month, day] = iso.split('-');
    return `${month}/${day}/${year}`;
};

export const roleLabel = (role) => ({
    admin: 'Administrator',
    payroll_staff: 'HR',
    faculty: 'Faculty',
}[role] || role);

export const dateLabel = (date = new Date()) => formatDate(date);

export const clockLabel = (date = new Date()) => new Intl.DateTimeFormat('en-PH', {
    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true,
}).format(date);
