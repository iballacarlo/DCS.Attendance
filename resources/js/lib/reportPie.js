export const pieCategories = [
    { key: 'on_time', label: 'On time & full class' },
    { key: 'late', label: 'Late only' },
    { key: 'late_undertime', label: 'Late & undertime' },
    { key: 'undertime', label: 'Undertime only' },
    { key: 'missed', label: 'Absent' },
    { key: 'incomplete', label: 'For review' },
];

export function matchesPieHighlight(key, highlight) {
    return !highlight || key === highlight || (key === 'late_undertime' && ['late', 'undertime'].includes(highlight));
}

export function pieGraphModel(rows, highlight = '') {
    const eligible = rows.filter((row) => row.ended && row.status !== 'excluded');
    const counts = Object.fromEntries(pieCategories.map(({ key }) => [key, 0]));
    for (const row of eligible) {
        if (row.status === 'missed') counts.missed++;
        else if (row.status !== 'complete') counts.incomplete++;
        else {
            const late = Number(row.late_minutes) > 0;
            const undertime = Number(row.early_departure_minutes) > 0;
            counts[late && undertime ? 'late_undertime' : late ? 'late' : undertime ? 'undertime' : 'on_time']++;
        }
    }
    const total = eligible.length;
    const categories = pieCategories.map((item) => ({ ...item, count: counts[item.key], percentage: total ? counts[item.key] / total * 100 : null }));
    const round = (n) => Math.round(n * 100) / 100;
    const point = (angle, radius = 126) => ({ x: round(320 + Math.cos(angle) * radius), y: round(185 + Math.sin(angle) * radius) });
    let angle = -Math.PI / 2;
    const slices = categories.filter((item) => item.count > 0).map((item) => {
        const endAngle = angle + item.count / total * Math.PI * 2;
        const start = point(angle);
        const end = point(endAngle);
        const mid = (angle + endAngle) / 2;
        const anchor = point(mid, 134);
        const side = Math.cos(mid) >= 0 ? 'right' : 'left';
        const path = item.count === total
            ? 'M320,59 A126,126 0 1 1 320,311 A126,126 0 1 1 320,59 Z'
            : `M320,185 L${start.x},${start.y} A126,126 0 ${endAngle - angle > Math.PI ? 1 : 0} 1 ${end.x},${end.y} Z`;
        angle = endAngle;
        return { ...item, path, anchor, side, highlighted: matchesPieHighlight(item.key, highlight) };
    });
    for (const side of ['left', 'right']) {
        const group = slices.filter((slice) => slice.side === side).sort((a, b) => a.anchor.y - b.anchor.y);
        let previous = -10;
        group.forEach((slice) => { slice.labelY = Math.max(38, slice.anchor.y, previous + 46); previous = slice.labelY; });
        const overflow = group.length ? Math.max(0, group.at(-1).labelY - 332) : 0;
        group.forEach((slice) => {
            slice.labelY = round(slice.labelY - overflow);
            slice.labelX = side === 'left' ? 10 : 630;
            slice.leader = `${slice.anchor.x},${slice.anchor.y} ${side === 'left' ? 178 : 462},${slice.labelY} ${slice.labelX},${slice.labelY}`;
        });
    }
    const highlights = [
        { key: 'on_time', label: 'On time & full class', count: counts.on_time },
        { key: 'late', label: 'Late', count: counts.late + counts.late_undertime },
        { key: 'undertime', label: 'Undertime', count: counts.undertime + counts.late_undertime },
        { key: 'missed', label: 'Absent', count: counts.missed },
        { key: 'late_undertime', label: 'Late & undertime', count: counts.late_undertime },
        { key: 'incomplete', label: 'For review', count: counts.incomplete },
    ];
    return { total, slices, highlights, selected: highlights.find((item) => item.key === highlight) || null };
}
