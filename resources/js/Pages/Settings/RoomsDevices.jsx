import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Building2, Copy, DoorOpen, Monitor, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import SettingsTabs from '../../components/SettingsTabs';
import useConfirmDialog from '../../hooks/useConfirmDialog';

const currentAssignment = (device) => device.assignments?.find((item) => !item.effective_to) || device.assignments?.[0];
const activeRooms = (rooms, buildingId, devices = [], currentDeviceId = null) => {
    const roomsWithActiveTerminal = new Set(devices
        .filter((device) => device.is_active && Number(device.id) !== Number(currentDeviceId))
        .map((device) => currentAssignment(device)?.room_id)
        .filter(Boolean)
        .map(Number));

    return rooms.filter((room) => room.is_active
        && String(room.building_id) === String(buildingId)
        && !roomsWithActiveTerminal.has(Number(room.id)));
};

export default function RoomsDevices({ buildings = [], rooms = [], devices = [] }) {
    const building = useForm({ name: '' });
    const room = useForm({ building_id: '', code: '' });
    const device = useForm({ building_id: '', room_id: '' });
    const [deviceBuildings, setDeviceBuildings] = useState({});
    const [roomFilter, setRoomFilter] = useState(buildings.find((item) => item.is_active)?.id || 'unassigned');
    const [editing, setEditing] = useState(null);
    const [copiedTerminalId, setCopiedTerminalId] = useState(null);
    const { ask, dialog } = useConfirmDialog();

    const addBuilding = (event) => { event.preventDefault(); building.post('/settings/buildings', { preserveScroll: true, onSuccess: () => building.reset() }); };
    const addRoom = (event) => { event.preventDefault(); room.post('/settings/rooms', { preserveScroll: true, onSuccess: () => room.reset('code') }); };
    const addDevice = (event) => { event.preventDefault(); device.post('/settings/devices', { preserveScroll: true, onSuccess: () => device.reset() }); };
    const assign = (item, roomId, active) => router.put(`/settings/devices/${item.id}/assignment`, { room_id: roomId, is_active: active }, { preserveScroll: true });
    const updateRoom = async (item, values) => {
        const active = values.is_active ?? item.is_active;
        if (active !== item.is_active && !(await ask({ title: `${active ? 'Activate' : 'Deactivate'} room?`, message: `${item.code} will ${active ? 'be available' : 'no longer be available'} for new schedules. Existing records are kept.`, confirmLabel: active ? 'Activate room' : 'Deactivate room', tone: active ? 'default' : 'warning' }))) return;
        router.put(`/settings/rooms/${item.id}`, { building_id: values.building_id ?? item.building_id, code: item.code, is_active: active }, { preserveScroll: true });
    };
    const toggleBuilding = async (item) => {
        const active = !item.is_active;
        if (!(await ask({ title: `${active ? 'Activate' : 'Deactivate'} building?`, message: `${item.name} will ${active ? 'be available' : 'no longer be available'} for room setup.`, confirmLabel: active ? 'Activate building' : 'Deactivate building', tone: active ? 'default' : 'warning' }))) return;
        router.put(`/settings/buildings/${item.id}`, { name: item.name, is_active: active }, { preserveScroll: true });
    };
    const editBuilding = (item) => setEditing({ type: 'building', item, value: item.name });
    const editRoom = (item) => setEditing({ type: 'room', item, value: item.code });
    const saveEdit = (event) => {
        event.preventDefault();
        const value = editing?.value?.trim();
        if (!value) return;
        const { item, type } = editing;
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (type === 'building') router.put(`/settings/buildings/${item.id}`, { name: value, is_active: item.is_active }, options);
        else router.put(`/settings/rooms/${item.id}`, { building_id: item.building_id, code: value, is_active: item.is_active }, options);
    };
    const remove = async (url, label) => {
        if (!(await ask({ title: `Delete ${label}?`, message: 'This cannot be undone. Records that depend on it are protected and cannot be deleted.', confirmLabel: 'Delete', tone: 'danger' }))) return;
        router.delete(url, { preserveScroll: true });
    };
    const copyTerminalId = async (terminalId) => {
        try {
            await navigator.clipboard.writeText(terminalId);
            setCopiedTerminalId(terminalId);
        } catch {
            setCopiedTerminalId(terminalId);
        }
    };
    const toggleTerminal = async (item, assignment) => {
        const active = !item.is_active;
        if (!(await ask({ title: `${active ? 'Enable' : 'Disable'} terminal?`, message: active ? 'This terminal can record attendance again.' : 'This terminal will stop accepting attendance taps until it is enabled again.', confirmLabel: active ? 'Enable terminal' : 'Disable terminal', tone: active ? 'default' : 'warning' }))) return;
        assign(item, assignment?.room_id, active);
    };

    return <AppLayout title="Rooms & Devices" subtitle="Set up classroom locations and the terminals assigned to them.">
        <SettingsTabs />
        <section className="panel rooms-device-buildings">
            <div className="panel-heading rd-panel-heading"><div><span className="rd-title-icon"><Building2 size={18} /></span><div><h2>Buildings</h2><p>Add the campus buildings that contain monitored classrooms.</p></div></div><span className="rd-count">{buildings.length} total</span></div>
            <form className="compact-form-row" onSubmit={addBuilding}>
                <label>Building name<input value={building.data.name} onChange={(e) => building.setData('name', e.target.value)} placeholder="Enter building name" required /></label>
                <button disabled={building.processing}><Plus size={17} />Add building</button>
            </form>
            {Object.values(building.errors).map((message) => <p className="field-error" key={message}>{message}</p>)}
            <div className="building-chip-list compact-scroll-list">{buildings.length ? buildings.map((item) => <article key={item.id}><div className="rd-record"><strong>{item.name}</strong><small className={item.is_active ? 'rd-active' : ''}>{item.is_active ? 'Active' : 'Inactive'}</small></div><div className="compact-actions"><button className="button-secondary rd-edit-action" onClick={() => editBuilding(item)}><Pencil size={15} />Edit</button><button className={item.is_active ? 'rd-state-action' : 'rd-enable-action'} onClick={() => toggleBuilding(item)}><Power size={15} />{item.is_active ? 'Deactivate' : 'Activate'}</button><button className="button-danger" onClick={() => remove(`/settings/buildings/${item.id}`, 'this building')}><Trash2 size={15} />Delete</button></div></article>) : <p className="rd-empty">No buildings added yet.</p>}</div>
        </section>
        <div className="room-settings-grid rooms-device-grid">
            <section className="panel"><div className="panel-heading rd-panel-heading"><div><span className="rd-title-icon"><DoorOpen size={18} /></span><div><h2>Rooms</h2><p>Select a building before adding or viewing its rooms.</p></div></div><span className="rd-count">{rooms.length} total</span></div>
                <label>View rooms in<select value={roomFilter} onChange={(e) => setRoomFilter(e.target.value)}><option value="unassigned">Needs building assignment</option>{buildings.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                <form className="compact-form-row" onSubmit={addRoom}>
                    <label>Building<select value={room.data.building_id} onChange={(e) => room.setData('building_id', e.target.value)} required><option value="">Select building</option>{buildings.filter((item) => item.is_active).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                    <label>Room<input value={room.data.code} onChange={(e) => room.setData('code', e.target.value)} placeholder="Enter room" required /></label>
                    <button disabled={room.processing || !room.data.building_id}><Plus size={17} />Add room</button>
                </form>
                {Object.values(room.errors).map((message) => <p className="field-error" key={message}>{message}</p>)}
                <div className="room-card-list compact-scroll-list">{rooms.filter((item) => roomFilter === 'unassigned' ? !item.building_id : String(item.building_id) === String(roomFilter)).length ? rooms.filter((item) => roomFilter === 'unassigned' ? !item.building_id : String(item.building_id) === String(roomFilter)).map((item) => <article key={item.id}><div className="rd-record"><strong>{item.code}</strong><small>{item.building ? item.building.name : 'Building not assigned'}</small></div><div className="compact-actions"><button className="button-secondary rd-edit-action" onClick={() => editRoom(item)}><Pencil size={15} />Edit</button><button className={item.is_active ? 'rd-state-action' : 'rd-enable-action'} disabled={!item.building_id} onClick={() => updateRoom(item, { is_active: !item.is_active })}><Power size={15} />{item.is_active ? 'Deactivate' : 'Activate'}</button><button className="button-danger" onClick={() => remove(`/settings/rooms/${item.id}`, 'this room')}><Trash2 size={15} />Delete</button></div></article>) : <p className="rd-empty">No rooms in this building.</p>}</div>
            </section>
            <section className="panel"><div className="panel-heading rd-panel-heading"><div><span className="rd-title-icon"><Monitor size={18} /></span><div><h2>Classroom terminals</h2><p>Choose the terminal’s room. The system creates its Terminal ID for the TFT configuration.</p></div></div><span className="rd-count">{devices.length} total</span></div>
                <form className="compact-form-row" onSubmit={addDevice}>
                    <label>Building<select value={device.data.building_id} onChange={(e) => device.setData({ ...device.data, building_id: e.target.value, room_id: '' })} required><option value="">Select building</option>{buildings.filter((item) => item.is_active).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                    <label>Assigned room<select disabled={!device.data.building_id} value={device.data.room_id} onChange={(e) => device.setData('room_id', e.target.value)} required><option value="">Select room</option>{activeRooms(rooms, device.data.building_id, devices).map((item) => <option key={item.id} value={item.id}>{item.code}</option>)}</select></label>
                    <button disabled={device.processing || !device.data.room_id}><Plus size={17} />Add terminal</button>
                </form>
                <p className="rd-terminal-help">After adding: copy the Terminal ID → paste it into <code>DEVICE_CODE</code> in <code>device_config.h</code> → upload the sketch. It identifies the physical terminal; it is not a room name.</p>
                {Object.values(device.errors).map((message) => <p className="field-error" key={message}>{message}</p>)}
                <div className="device-card-list compact-scroll-list">{devices.length ? devices.map((item) => {
                    const assignment = currentAssignment(item);
                    const assignedRoom = rooms.find((entry) => Number(entry.id) === Number(assignment?.room_id));
                    const buildingId = deviceBuildings[item.id] ?? assignedRoom?.building_id ?? '';
                    return <article key={item.id}><div className="rd-record"><div className="rd-terminal-id"><code>Terminal ID: {item.code}</code><button type="button" className="rd-copy-id" onClick={() => copyTerminalId(item.code)} aria-label={`Copy Terminal ID ${item.code}`} title="Copy Terminal ID"><Copy size={15} /></button></div><small>{item.last_seen_at ? `Last seen: ${new Date(item.last_seen_at).toLocaleString()}` : 'Not connected yet'}</small></div><label>Building<select value={buildingId} onChange={(e) => setDeviceBuildings((current) => ({ ...current, [item.id]: e.target.value }))}><option value="">Select building</option>{buildings.filter((entry) => entry.is_active).map((entry) => <option key={entry.id} value={entry.id}>{entry.name}</option>)}</select></label><label>Room<select value={String(assignedRoom?.building_id) === String(buildingId) ? assignment?.room_id || '' : ''} disabled={!buildingId} onChange={(e) => assign(item, e.target.value, item.is_active)}><option value="">Select room</option>{activeRooms(rooms, buildingId, devices, item.id).map((entry) => <option key={entry.id} value={entry.id}>{entry.code}</option>)}</select></label><div className="compact-actions"><button className={item.is_active ? 'rd-state-action' : 'rd-enable-action'} onClick={() => toggleTerminal(item, assignment)}><Power size={15} />{item.is_active ? 'Disable' : 'Enable'}</button><button className="button-danger" onClick={() => remove(`/settings/devices/${item.id}`, 'this terminal')}><Trash2 size={15} />Delete</button></div></article>;
                }) : <p className="rd-empty">No classroom terminals added yet.</p>}</div>
            </section>
        </div>
        {editing && <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setEditing(null); }}><form className="confirm-dialog rd-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="room-device-edit-title" onSubmit={saveEdit}><div className="dialog-copy"><h2 id="room-device-edit-title">Edit {editing.type === 'building' ? 'building' : 'room'}</h2><p>Update the {editing.type === 'building' ? 'building name' : 'room'}.</p></div><label>{editing.type === 'building' ? 'Building name' : 'Room'}<input autoFocus value={editing.value} onChange={(event) => setEditing({ ...editing, value: event.target.value })} required /></label><div className="dialog-actions"><button className="dialog-cancel" type="button" onClick={() => setEditing(null)}>Cancel</button><button className="dialog-confirm" type="submit">Save changes</button></div></form></div>}
        {copiedTerminalId && <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setCopiedTerminalId(null); }}><section className="confirm-dialog rd-copy-dialog" role="dialog" aria-modal="true" aria-labelledby="terminal-id-title"><div className="dialog-copy"><h2 id="terminal-id-title">Terminal ID copied</h2><p>Paste this into <code>DEVICE_CODE</code> in <code>device_config.h</code>, then upload the sketch to that physical terminal.</p><code className="rd-dialog-code">{copiedTerminalId}</code></div><div className="dialog-actions"><button className="dialog-confirm" type="button" onClick={() => setCopiedTerminalId(null)}>Done</button></div></section></div>}
        {dialog}
    </AppLayout>;
}
