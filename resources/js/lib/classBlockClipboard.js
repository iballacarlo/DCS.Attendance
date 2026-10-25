const marker = 'dcs-class-details';

export function classDetailsForPaste(details, lectureOnlySubjects = []) {
    return { class_mode: lectureOnlySubjects.includes(details.subject) || details.class_mode === 'laboratory' ? 'lecture' : 'laboratory', section: details.section, subject: details.subject };
}

export function encodeClassDetails(block) {
    if (block?.schedule_type !== 'class' || !['lecture', 'laboratory'].includes(block.class_mode) || !block.subject || !block.section) return null;
    return JSON.stringify({ type: marker, version: 1, class_mode: block.class_mode, section: block.section, subject: block.subject });
}

export function decodeClassDetails(text) {
    try {
        const value = JSON.parse(text);
        if (value?.type !== marker || value.version !== 1 || !['lecture', 'laboratory'].includes(value.class_mode)
            || typeof value.section !== 'string' || typeof value.subject !== 'string' || !value.section || !value.subject) return null;
        return { class_mode: value.class_mode, section: value.section, subject: value.subject };
    } catch {
        return null;
    }
}
