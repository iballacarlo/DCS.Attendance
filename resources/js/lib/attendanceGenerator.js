export const generationKey = (filters) => JSON.stringify([filters.mode, filters.cutoff_start, String(filters.employee_id || ''), !!filters.overwrite]);

export function generationPayload(filters) {
    return {
        mode: filters.mode,
        cutoff_start: filters.cutoff_start,
        employee_id: filters.employee_id || null,
        overwrite: !!filters.overwrite,
        confirm_overwrite: !!filters.overwrite,
    };
}

export function generationError(failure) {
    const response = failure?.response;
    if (response?.status === 401) return 'Your session expired. Sign in again.';
    if (response?.status === 403 || response?.status === 404) return 'Generator access is unavailable.';
    if (response?.status === 419) return 'Your session expired. Reload this page and sign in again.';
    if (response?.status === 423) return 'Change your temporary password before generating records.';
    if (response?.status === 429) return 'Too many requests. Wait a moment and try again.';

    return Object.values(response?.data?.errors || {})[0]?.[0] || 'Could not complete the request. Try generating again.';
}
