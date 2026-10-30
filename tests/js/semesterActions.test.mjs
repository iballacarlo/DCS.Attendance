import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { transform } from 'esbuild';

const moduleUrl = (code) => `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
const runtimeUrl = moduleUrl(`export let answer = false; export const requests = []; export const prompts = [];
let values = [], cursor = 0;
export function beginRender() { cursor = 0; }
export function reset(value) { answer = value; requests.length = 0; prompts.length = 0; values = []; beginRender(); }
export function useDialog() { return { ask: async (options) => { prompts.push(options); return answer; }, dialog: null }; }
export function useState(initial) { const index = cursor++; if (!(index in values)) values[index] = typeof initial === 'function' ? initial() : initial; return [values[index], (value) => { values[index] = value; }]; }
export function useForm(data) {
    const [form] = useState(() => ({ data, errors: {}, processing: false }));
    for (const method of ['post', 'put', 'delete']) form[method] = (url) => requests.push({ method, url });
    form.setData = (key, value) => { form.data = typeof key === 'string' ? { ...form.data, [key]: value } : key; };
    form.clearErrors = () => { form.errors = {}; }; form.reset = () => { form.data = data; };
    return form;
}
export const Link = () => null; export const router = {};`);
const runtime = await import(runtimeUrl);
const sourceUrl = new URL('../../resources/js/Pages/Settings/Semesters.jsx', import.meta.url);
const emptyUrl = moduleUrl('export default function Empty() { return null; }');
const mocks = {
    'react': runtimeUrl,
    '@inertiajs/react': runtimeUrl,
    '../../hooks/useConfirmDialog': moduleUrl(`export { useDialog as default } from ${JSON.stringify(runtimeUrl)};`),
    '../../components/DateInput': emptyUrl,
    '../../components/YearFilterInput': emptyUrl,
    '../../components/AttendancePagination': emptyUrl,
    '../../components/SettingsTabs': emptyUrl,
    '../../Layouts/AppLayout': emptyUrl,
};
const { code } = await transform((await readFile(sourceUrl, 'utf8')).replace("import '../../../css/semesters.css';", ''), { loader: 'jsx', jsx: 'automatic', format: 'esm' });
const resolved = code.replace(/from "([^"]+)"/g, (_, name) => `from ${JSON.stringify(mocks[name] || (name.startsWith('.') ? new URL(`${name}.js`, sourceUrl).href : import.meta.resolve(name)))}`);
const { default: SemesterPage } = await import(moduleUrl(resolved));
const Semesters = (props) => { runtime.beginRender(); return SemesterPage(props); };
const term = { id: 4, label: 'First Semester 2026-2027', school_year: '2026-2027', semester: 'First Semester', status: 'ended', start_date: '2026-08-10', end_date: '2026-12-12' };
function nodes(node) {
    if (!node || typeof node !== 'object') return [];
    if (Array.isArray(node)) return node.flatMap(nodes);
    return [node, ...nodes(node.props?.children)];
}
test('closing semester sends no request until confirmation and Cancel keeps it open', async () => {
    for (const answer of [false, true]) {
        runtime.reset(answer);
        const tree = Semesters({ terms: [term], selectedTerm: term, faculty: [], history: null });
        const closeForm = nodes(tree).find((node) => node.type === 'form' && node.props.className === 'semester-activation');
        assert.equal(runtime.requests.length, 0);
        await closeForm.props.onSubmit({ preventDefault() {} });
        assert.equal(runtime.prompts.length, 1);
        assert.equal(runtime.prompts[0].confirmLabel, 'Close semester');
        assert.match(runtime.prompts[0].message, /read-only/);
        assert.equal(runtime.requests.length, answer ? 1 : 0);
        if (answer) assert.equal(runtime.requests[0].url, '/settings/semesters/4/transition');
    }
});
test('active semester exposes Edit details while closed semester stays read-only', () => {
    runtime.reset(false);
    const render = (status) => nodes(Semesters({ terms: [{ ...term, status }], selectedTerm: { ...term, status }, faculty: [], history: null }));
    assert.ok(render('active').some((node) => node.type === 'button' && node.props.children === 'Edit details'));
    assert.equal(render('closed').some((node) => node.type === 'button' && node.props.children === 'Edit details'), false);
});

test('Edit details opens its fields inside the selected semester and leaves the new-semester form closed', () => {
    runtime.reset(false);
    const props = { terms: [{ ...term, status: 'active' }], selectedTerm: { ...term, status: 'active' }, faculty: [], history: null, startDateLocked: true, today: '2026-10-30' };
    let tree = Semesters(props);
    nodes(tree).find((node) => node.type === 'button' && node.props.children === 'Edit details').props.onClick();
    tree = Semesters(props);
    const selected = nodes(tree).find((node) => node.props?.className === 'semester-selected-details');
    assert.equal(nodes(selected).filter((node) => node.type === 'form' && node.props.className === 'semester-fields').length, 1);
    assert.equal(nodes(tree).filter((node) => node.type === 'form' && node.props.className === 'semester-fields').length, 1);
    assert.ok(nodes(tree).some((node) => node.type === 'button' && node.props.children === 'New semester'));
    assert.ok(nodes(selected).some((node) => node.props?.value === '2026-08-10' && node.props.disabled === true));
    assert.equal(nodes(selected).some((node) => node.type === 'small' && String(node.props.children).includes('Fixed to preserve')), false);
    nodes(selected).find((node) => node.type === 'button' && node.props.children === 'Cancel editing').props.onClick();
    assert.equal(nodes(Semesters(props)).some((node) => node.type === 'form' && node.props.className === 'semester-fields'), false);
});

test('upcoming deletion requires confirmation and is not offered for active semesters', async () => {
    const upcoming = { ...term, status: 'scheduled', start_date: '2027-01-11', end_date: '2027-05-15' };
    for (const answer of [false, true]) {
        runtime.reset(answer);
        const tree = Semesters({ terms: [upcoming], selectedTerm: upcoming, faculty: [], history: null, today: '2026-10-30' });
        await nodes(tree).find((node) => node.type === 'button' && node.props.children === 'Delete semester').props.onClick();
        assert.equal(runtime.prompts.length, 1);
        assert.match(runtime.prompts[0].message, /prepared faculty schedules/);
        assert.equal(runtime.requests.length, answer ? 1 : 0);
        if (answer) assert.deepEqual(runtime.requests[0], { method: 'delete', url: '/settings/semesters/4' });
    }
    runtime.reset(false);
    const active = { ...term, status: 'active' };
    const tree = Semesters({ terms: [active], selectedTerm: active, faculty: [], history: null, today: '2026-10-30' });
    assert.equal(nodes(tree).some((node) => node.type === 'button' && node.props.children === 'Delete semester'), false);
});

test('Publish all is disabled without changes and only submits when saved schedules need publishing', () => {
    const active = { ...term, status: 'active' };
    for (const pending of [false, true]) {
        runtime.reset(false);
        const tree = Semesters({ terms: [active], selectedTerm: active, faculty: [{ id: 1, first_name: 'Test', last_name: 'Faculty', published_versions: 1, needs_publication: pending }], history: null });
        const label = pending ? 'Publish all schedules' : 'No changes to publish';
        const button = nodes(tree).find((node) => node.type === 'button' && node.props.children === label);
        assert.equal(button.props.disabled, !pending);
        const form = nodes(tree).find((node) => node.type === 'form' && nodes(node).includes(button));
        form.props.onSubmit({ preventDefault() {} });
        assert.equal(runtime.requests.length, pending ? 1 : 0);
    }
});
