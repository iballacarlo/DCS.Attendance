import { useId } from "react";
import { Link2Off } from "lucide-react";
import FacultyCombobox from "../../components/FacultyCombobox";
import { Link, useForm } from "@inertiajs/react";
import AppLayout from "../../Layouts/AppLayout";
import SettingsTabs from "../../components/SettingsTabs";

export default function UsersForm(props) {
    return <AccountForm key={props.user.id || "new-account"} {...props} />;
}

function AccountForm({ user, employees }) {
    const editing = Boolean(user.id);
    const id = useId();
    const form = useForm({
        name: editing ? user.name || "" : "",
        email: editing ? user.email || "" : "",
        reset_password: false,
        role:
            editing && ["admin", "faculty", "guard"].includes(user.role)
                ? user.role
                : "",
        employee_id: editing ? user.employee_id || "" : "",
    });
    const facultyRole = form.data.role === "faculty";
    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => {
            const payload = {
                ...data,
                employee_id: data.role === "faculty" ? data.employee_id : null,
            };
            if (data.role === "faculty") {
                delete payload.name;
                delete payload.email;
            }
            if (!editing) delete payload.reset_password;
            return payload;
        });
        editing
            ? form.put(`/settings/accounts/${user.id}`)
            : form.post("/settings/accounts");
    };
    const error = (field) =>
        form.errors[field] && (
            <small className="field-error" role="alert">
                {form.errors[field]}
            </small>
        );
    return (
        <AppLayout title={editing ? "Edit Account" : "Add Account"}>
            <section className="account-form-page">
                <SettingsTabs />
                <form
                    className="panel form-grid"
                    name="manage-account"
                    autoComplete="off"
                    onSubmit={submit}
                >
                    <label>
                        Role
                        <select
                            value={form.data.role}
                            required
                            onChange={(e) =>
                                form.setData({
                                    ...form.data,
                                    role: e.target.value,
                                    employee_id:
                                        e.target.value === "faculty"
                                            ? form.data.employee_id
                                            : "",
                                })
                            }
                        >
                            <option value="" disabled>
                                Select role
                            </option>
                            <option value="admin">Admin</option>
                            {editing && (
                                <option value="faculty">Faculty</option>
                            )}
                            <option value="guard">Guard</option>
                        </select>
                        {error("role")}
                    </label>
                    {facultyRole ? (
                        <div className="linked-faculty-account-field">
                            <div className="linked-faculty-control-row">
                                <FacultyCombobox
                                    label="Linked Faculty"
                                    employees={employees}
                                    selectedId={form.data.employee_id}
                                    onSelect={(value) =>
                                        form.setData("employee_id", value)
                                    }
                                />
                                {editing && form.data.employee_id && (
                                    <button
                                        className="ui-control ui-action ui-danger account-unlink-faculty"
                                        type="button"
                                        title="Unlink this account from the selected faculty"
                                        onClick={() =>
                                            form.setData("employee_id", "")
                                        }
                                    >
                                        <Link2Off size={16} />
                                        <span>Unlink</span>
                                    </button>
                                )}
                            </div>
                            {error("employee_id")}
                        </div>
                    ) : (
                        <label htmlFor={`${id}-name`}>
                            Name
                            <input
                                id={`${id}-name`}
                                name="managed_account_name"
                                autoComplete="off"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData("name", e.target.value)
                                }
                                required
                            />
                            {error("name")}
                        </label>
                    )}
                    {!facultyRole && (
                        <label htmlFor={`${id}-email`}>
                            Email
                            <input
                                id={`${id}-email`}
                                name="managed_account_email"
                                type="email"
                                autoComplete="off"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData("email", e.target.value)
                                }
                                required
                            />
                            {error("email")}
                        </label>
                    )}
                    {editing ? (
                        <div
                            className={`account-security-actions${facultyRole ? " is-faculty" : ""}`}
                        >
                            {user.must_change_password ? (
                                <label className="account-reset-password">
                                    <input
                                        type="checkbox"
                                        checked={form.data.reset_password}
                                        onChange={(event) =>
                                            form.setData(
                                                "reset_password",
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    <span>
                                        <strong>
                                            Resend a temporary password
                                        </strong>
                                        <small>
                                            This account has not created a
                                            private password yet. The system can
                                            issue and email a replacement
                                            temporary password.
                                        </small>
                                    </span>
                                </label>
                            ) : (
                                <div className="account-password-note">
                                    <strong>
                                        Private password already set
                                    </strong>
                                    <span>
                                        The administrator cannot replace it. The
                                        account owner must use Forgot Password
                                        from the login page.
                                    </span>
                                    {error("reset_password")}
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="account-password-note">
                            <strong>Temporary password</strong>
                            <span>
                                The system will generate it and send it directly
                                to the account owner’s email. It will not be
                                shown to the administrator.
                            </span>
                        </div>
                    )}
                    <div className="form-actions">
                        <Link
                            className="ui-control ui-action ui-secondary"
                            href="/settings/accounts"
                        >
                            Cancel
                        </Link>
                        <button
                            className="ui-control ui-action ui-primary"
                            type="submit"
                            disabled={form.processing}
                        >
                            {form.processing ? "Saving..." : "Save Account"}
                        </button>
                    </div>
                </form>
            </section>
        </AppLayout>
    );
}
