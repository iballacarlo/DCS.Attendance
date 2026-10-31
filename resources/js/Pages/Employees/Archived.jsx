import FacultyCombobox from "../../components/FacultyCombobox";
import { Link, router } from "@inertiajs/react";
import { ArrowLeft, RotateCcw, Trash2, X } from "lucide-react";
import { useState } from "react";
import AppLayout from "../../Layouts/AppLayout";
import Pagination from "../../components/Pagination";
import useConfirmDialog from "../../hooks/useConfirmDialog";
import useDebouncedFilters from "../../hooks/useDebouncedFilters";
import { formatDate, fullName } from "../../lib/format";

export default function ArchivedFaculty({
    employees,
    filters,
    archivedFacultyChoices,
}) {
    const [selection, setSelection] = useState({
        search: filters.search || "",
        employee_id: filters.employee_id || "",
    });
    const search = selection.search || selection.employee_id;
    const [restoring, setRestoring] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const { ask, dialog } = useConfirmDialog();
    useDebouncedFilters("/archived-faculty", selection);
    const restore = async (employee) => {
        if (
            !(await ask({
                title: "Restore faculty?",
                message: `Restore ${fullName(employee)} with their saved schedules and history? Their previous status (${employee.status}) will be retained, and their account will be available again.`,
                confirmLabel: "Restore Faculty",
            }))
        )
            return;
        setRestoring(employee.id);
        router.post(
            `/archived-faculty/${employee.id}/restore`,
            {},
            { preserveScroll: true, onFinish: () => setRestoring(null) },
        );
    };
    const remove = async (employee) => {
        if (
            !(await ask({
                title: "Permanently delete faculty?",
                message: `Permanently delete ${fullName(employee)}? Their account, schedules, attendance records, DTR history, and all other linked records will be removed and cannot be restored.`,
                confirmLabel: "Delete Permanently",
                tone: "danger",
            }))
        )
            return;
        setDeleting(employee.id);
        router.delete(`/archived-faculty/${employee.id}`, {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });
    };
    return (
        <AppLayout
            title="Archived Faculty"
            subtitle="Restore faculty with their saved schedules and records."
        >
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <span className="bento-eyebrow">Faculty archive</span>
                        <h2>
                            Archived Faculty{" "}
                            <span className="ui-record-count">
                                {employees.total}
                            </span>
                        </h2>
                    </div>
                    <Link
                        className="ui-control ui-action ui-secondary"
                        href="/employees"
                    >
                        <ArrowLeft size={16} />
                        Back to Faculty
                    </Link>
                </div>
                <div className="list-filters archive-filters">
                    <FacultyCombobox
                        label="Search archived faculty"
                        employees={archivedFacultyChoices}
                        selectedId={selection.employee_id}
                        allowClear
                        onSelect={(id) =>
                            setSelection({ employee_id: id, search: "" })
                        }
                    />
                    {search && (
                        <button
                            className="ui-control ui-action ui-secondary"
                            type="button"
                            onClick={() =>
                                setSelection({ employee_id: "", search: "" })
                            }
                        >
                            <X size={15} />
                            Clear
                        </button>
                    )}
                </div>
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Employee No.</th>
                                <th>Faculty</th>
                                <th>Archived on</th>
                                <th>Saved schedule blocks</th>
                                <th>Previous status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {employees.data.length ? (
                                employees.data.map((employee) => (
                                    <tr key={employee.id}>
                                        <td>{employee.employee_no}</td>
                                        <td>
                                            <strong>
                                                {fullName(employee)}
                                            </strong>
                                            <small>{employee.email}</small>
                                        </td>
                                        <td>
                                            {formatDate(
                                                new Date(employee.archived_at),
                                            )}
                                        </td>
                                        <td>
                                            {employee.schedules_count +
                                                employee.schedule_breaks_count}
                                        </td>
                                        <td>
                                            <span
                                                className="badge"
                                                data-status={employee.status}
                                            >
                                                {employee.status}
                                            </span>
                                        </td>
                                        <td>
                                            <div className="table-actions">
                                                <button
                                                    className="ui-control ui-action ui-primary"
                                                    type="button"
                                                    disabled={
                                                        restoring !== null ||
                                                        deleting !== null
                                                    }
                                                    onClick={() =>
                                                        restore(employee)
                                                    }
                                                >
                                                    <RotateCcw size={16} />
                                                    {restoring === employee.id
                                                        ? "Restoring…"
                                                        : "Restore"}
                                                </button>
                                                <button
                                                    className="ui-control ui-action ui-danger"
                                                    type="button"
                                                    disabled={
                                                        restoring !== null ||
                                                        deleting !== null
                                                    }
                                                    onClick={() =>
                                                        remove(employee)
                                                    }
                                                >
                                                    <Trash2 size={15} />
                                                    {deleting === employee.id
                                                        ? "Deleting..."
                                                        : "Delete"}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={6} className="empty-row">
                                        {search
                                            ? "No archived faculty match your search."
                                            : "No archived faculty. Archive a faculty member from the Faculty page."}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <Pagination links={employees.links} />
            </section>
            {dialog}
        </AppLayout>
    );
}
