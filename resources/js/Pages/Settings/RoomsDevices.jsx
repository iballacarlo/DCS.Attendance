import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import SettingsTabs from '../../components/SettingsTabs';

const currentAssignment = (device) => device.assignments?.find((item) => !item.effective_to) || device.assignments?.[0];
const activeRooms = (rooms, buildingId) => rooms.filter((room) => room.is_active && String(room.building_id) === String(buildingId));

export default function RoomsDevices({ buildings = [], rooms = [], devices = [] }) {
    const building = useForm({ code: '', name: '' });
    const room = useForm({ building_id: '', code: '', name: '' });
    const device = useForm({ code: '', name: '', building_id: '', room_id: '' });
    const [deviceBuildings, setDeviceBuildings] = useState({});
    const [roomFilter, setRoomFilter] = useState(buildings.find((item) => item.is_active)?.id || 'unassigned');

    const addBuilding = (event) => { event.preventDefault(); building.post('/settings/buildings', { preserveScroll: true, onSuccess: () => building.reset() }); };
    const addRoom = (event) => { event.preventDefault(); room.post('/settings/rooms', { preserveScroll: true, onSuccess: () => room.reset('code', 'name') }); };
    const addDevice = (event) => { event.preventDefault(); device.post('/settings/devices', { preserveScroll: true, onSuccess: () => device.reset() }); };
    const assign = (item, roomId, active) => router.put(`/settings/devices/${item.id}/assignment`, { room_id: roomId, is_active: active }, { preserveScroll: true });
    const updateRoom = (item, values) => router.put(`/settings/rooms/${item.id}`, { building_id: values.building_id ?? item.building_id, code: item.code, name: item.name || '', is_active: values.is_active ?? item.is_active }, { preserveScroll: true });
    const toggleBuilding = (item) => router.put(`/settings/buildings/${item.id}`, { code: item.code, name: item.name, is_active: !item.is_active }, { preserveScroll: true });

    return <AppLayout title="Rooms & Devices" subtitle="Organize rooms by building and assign each classroom terminal to its current room.">
        <SettingsTabs />
        <section className="panel">
            <div className="panel-heading"><div><h2>Buildings</h2><p>Add the campus buildings that contain monitored classrooms.</p></div></div>
            <form className="compact-form-row" onSubmit={addBuilding}>
                <label>Building code<input value={building.data.code} onChange={(e) => building.setData('code', e.target.value)} placeholder="DCS" required /></label>
                <label>Building name<input value={building.data.name} onChange={(e) => building.setData('name', e.target.value)} placeholder="Department of Computer Studies Building" required /></label>
                <button disabled={building.processing}>Add building</button>
            </form>
            {Object.values(building.errors).map((message) => <p className="field-error" key={message}>{message}</p>)}
            <div className="building-chip-list">{buildings.map((item) => <article key={item.id}><div><strong>{item.code}</strong><span>{item.name}</span></div><button className="button-secondary" onClick={() => toggleBuilding(item)}>{item.is_active ? 'Set inactive' : 'Activate'}</button></article>)}</div>
        </section>
        <div className="room-settings-grid">
            <section className="panel"><div className="panel-heading"><div><h2>Rooms</h2><p>Select a building before adding or viewing its rooms.</p></div></div>
                <label>View rooms in<select value={roomFilter} onChange={(e) => setRoomFilter(e.target.value)}><option value="unassigned">Needs building assignment</option>{buildings.map((item) => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}</select></label>
                <form className="compact-form-row" onSubmit={addRoom}>
                    <label>Building<select value={room.data.building_id} onChange={(e) => room.setData('building_id', e.target.value)} required><option value="">Select building</option>{buildings.filter((item) => item.is_active).map((item) => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}</select></label>
                    <label>Room code<input value={room.data.code} onChange={(e) => room.setData('code', e.target.value)} placeholder="CL 3" required /></label>
                    <label>Room name <small>(optional)</small><input value={room.data.name} onChange={(e) => room.setData('name', e.target.value)} placeholder="Computer Laboratory 3" /></label>
                    <button disabled={room.processing || !room.data.building_id}>Add room</button>
                </form>
                {Object.values(room.errors).map((message) => <p className="field-error" key={message}>{message}</p>)}
                <div className="room-card-list">{rooms.filter((item) => roomFilter === 'unassigned' ? !item.building_id : String(item.building_id) === String(roomFilter)).map((item) => <article key={item.id}><div><strong>{item.code}</strong><span>{item.name || 'No room name'}</span><small>{item.building ? `${item.building.code} — ${item.building.name}` : 'Building not assigned'}</small></div><label>Building<select value={item.building_id || ''} onChange={(e) => updateRoom(item, { building_id: e.target.value })}><option value="">Assign building</option>{buildings.filter((entry) => entry.is_active).map((entry) => <option key={entry.id} value={entry.id}>{entry.code}</option>)}</select></label><button className="button-secondary" disabled={!item.building_id} onClick={() => updateRoom(item, { is_active: !item.is_active })}>{item.is_active ? 'Set inactive' : 'Activate'}</button></article>)}</div>
            </section>
            <section className="panel"><div className="panel-heading"><div><h2>Attendance devices</h2><p>Building selection limits the assigned-room list.</p></div></div>
                <form className="compact-form-row" onSubmit={addDevice}>
                    <label>Device code<input value={device.data.code} onChange={(e) => device.setData('code', e.target.value)} placeholder="TFT-ROOM-01" required /></label>
                    <label>Device name<input value={device.data.name} onChange={(e) => device.setData('name', e.target.value)} placeholder="Main TFT terminal" required /></label>
                    <label>Building<select value={device.data.building_id} onChange={(e) => device.setData({ ...device.data, building_id: e.target.value, room_id: '' })} required><option value="">Select building</option>{buildings.filter((item) => item.is_active).map((item) => <option key={item.id} value={item.id}>{item.code}</option>)}</select></label>
                    <label>Assigned room<select disabled={!device.data.building_id} value={device.data.room_id} onChange={(e) => device.setData('room_id', e.target.value)} required><option value="">Select room</option>{activeRooms(rooms, device.data.building_id).map((item) => <option key={item.id} value={item.id}>{item.code}</option>)}</select></label>
                    <button disabled={device.processing || !device.data.room_id}>Add device</button>
                </form>
                {Object.values(device.errors).map((message) => <p className="field-error" key={message}>{message}</p>)}
                <div className="device-card-list">{devices.map((item) => {
                    const assignment = currentAssignment(item);
                    const assignedRoom = rooms.find((entry) => Number(entry.id) === Number(assignment?.room_id));
                    const buildingId = deviceBuildings[item.id] ?? assignedRoom?.building_id ?? '';
                    return <article key={item.id}><div><strong>{item.name}</strong><code>{item.code}</code><small>Put this code in the TFT device configuration.</small></div><label>Building<select value={buildingId} onChange={(e) => setDeviceBuildings((current) => ({ ...current, [item.id]: e.target.value }))}><option value="">Select building</option>{buildings.filter((entry) => entry.is_active).map((entry) => <option key={entry.id} value={entry.id}>{entry.code}</option>)}</select></label><label>Room<select value={String(assignedRoom?.building_id) === String(buildingId) ? assignment?.room_id || '' : ''} disabled={!buildingId} onChange={(e) => assign(item, e.target.value, item.is_active)}><option value="">Select room</option>{activeRooms(rooms, buildingId).map((entry) => <option key={entry.id} value={entry.id}>{entry.code}</option>)}</select></label><div className="device-state"><span>{item.last_seen_at ? `Last seen: ${new Date(item.last_seen_at).toLocaleString()}` : 'Not connected yet'}</span><button className="button-secondary" onClick={() => assign(item, assignment?.room_id, !item.is_active)}>{item.is_active ? 'Disable device' : 'Enable device'}</button></div></article>;
                })}</div>
            </section>
        </div>
    </AppLayout>;
}
