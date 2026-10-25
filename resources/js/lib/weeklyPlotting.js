export const plottingDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
export const minutesOf = (value) => {
    const [hour, minute] = String(value).split(':').map(Number);
    return hour * 60 + minute;
};
export const plottingTime = (minute) => `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;

export function weeklyPlotting(blocks) {
    const valid = blocks.map((block, index) => ({ ...block, key: index, start: minutesOf(block.start_time), end: minutesOf(block.end_time) }))
        .filter((block) => block.day_of_week >= 1 && block.day_of_week <= 6 && block.end > block.start);
    const first = Math.min(7 * 60, ...valid.map((b) => Math.floor(b.start / 30) * 30));
    const last = Math.max(21 * 60, ...valid.map((b) => Math.ceil(b.end / 30) * 30));
    const boundaries = [...new Set([...Array.from({ length: (last - first) / 30 + 1 }, (_, i) => first + i * 30), ...valid.flatMap((b) => [b.start, b.end])])].sort((a, b) => a - b);
    const rows = boundaries.slice(0, -1).map((start, index) => ({ start, end: boundaries[index + 1],
        cells: plottingDays.map((_, day) => {
            const block = valid.find((b) => Number(b.day_of_week) === day + 1 && b.start <= start && b.end > start);
            if (!block) return { span: 1, block: null };
            if (block.start !== start) return null;
            return { span: boundaries.indexOf(block.end) - index, block };
        }),
    }));
    const courses = new Map();
    for (const block of valid.filter((b) => b.schedule_type === 'class')) {
        const key = JSON.stringify([block.subject || 'Not specified', block.section || 'Not specified']);
        if (!courses.has(key)) courses.set(key, { subject: block.subject || 'Not specified', section: block.section || 'Not specified', lecture: 0, laboratory: 0, unspecified: 0 });
        const mode = ['lecture', 'laboratory'].includes(block.class_mode) ? block.class_mode : 'unspecified';
        courses.get(key)[mode] += (block.end - block.start) / 60;
    }
    return { rows, courses: [...courses.values()].sort((a, b) => a.subject.localeCompare(b.subject, undefined, { numeric: true }) || a.section.localeCompare(b.section)),
        consultationHours: valid.filter((b) => b.schedule_type === 'consultation').reduce((sum, b) => sum + (b.end - b.start) / 60, 0),
        payableHours: valid.filter((b) => ['class', 'consultation'].includes(b.schedule_type)).reduce((sum, b) => sum + (b.end - b.start) / 60, 0),
    };
}
