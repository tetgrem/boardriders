"use client";

import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { CrmError, crm, when } from "@/lib/crm65/client";
import type { StaffIdentity } from "@/lib/crm65/session";

type Role = "owner" | "manager" | "support" | "finance" | "fiscal" | "viewer";
type Member = {
    user_id: number;
    name: string;
    email: string;
    role: Role;
    active: boolean;
    version: number;
    updated_at: string;
};
type Page = { items: Member[]; next_before_id: number | null };
type EventRow = {
    operation_key: string;
    target_user_id: number;
    actor_id: number;
    action: string;
    from_role: Role | null;
    to_role: Role;
    from_active: boolean | null;
    to_active: boolean;
    version: number;
    created_at: string;
};

const roles: Array<[Role, string]> = [
    ["owner", "Владелец"],
    ["manager", "Менеджер"],
    ["support", "Поддержка"],
    ["finance", "Финансы"],
    ["fiscal", "Фискализация"],
    ["viewer", "Просмотр"],
];
const roleName = (role: string) => roles.find(([key]) => key === role)?.[1] ?? role;
const eventName: Record<string, string> = {
    granted: "Предоставлен доступ",
    role_changed: "Изменена роль",
    deactivated: "Доступ заблокирован",
    reactivated: "Доступ восстановлен",
};

export default function StaffClient68({ staff }: { staff: StaffIdentity }) {
    const router = useRouter();
    const [items, setItems] = useState<Member[]>([]);
    const [cursor, setCursor] = useState<number | null>(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [email, setEmail] = useState("");
    const [role, setRole] = useState<Role>("support");
    const [rolesDraft, setRolesDraft] = useState<Record<number, Role>>({});
    const [historyFor, setHistoryFor] = useState<number | null>(null);
    const [history, setHistory] = useState<EventRow[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const grantKey = useRef<string | null>(null);
    const actionKeys = useRef<Record<string, string>>({});

    const fail = useCallback((caught: unknown) => {
        if (caught instanceof CrmError && caught.status === 401) {
            router.replace("/admin/login");
            return;
        }
        setError(caught instanceof Error ? caught.message : "Ошибка управления сотрудниками");
    }, [router]);

    const refresh = useCallback(async () => {
        const response = await crm<Page>("staff");
        setItems(response.items);
        setCursor(response.next_before_id);
    }, []);

    useEffect(() => {
        let current = true;
        crm<Page>("staff")
            .then((response) => {
                if (!current) return;
                setItems(response.items);
                setCursor(response.next_before_id);
            })
            .catch((caught: unknown) => { if (current) fail(caught); })
            .finally(() => { if (current) setLoading(false); });
        return () => { current = false; };
    }, [fail]);

    async function addEmployee(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (busy) return;
        setBusy(true);
        setError(null);
        setNotice(null);
        grantKey.current ??= crypto.randomUUID();
        try {
            await crm("staff", {
                method: "POST",
                body: { email: email.trim().toLowerCase(), role, operation_key: grantKey.current },
            });
            grantKey.current = null;
            setEmail("");
            setRole("support");
            setNotice("Доступ сотруднику добавлен.");
            await refresh();
        } catch (caught) {
            fail(caught);
        } finally {
            setBusy(false);
        }
    }

    async function change(member: Member, action: "role_changed" | "deactivated" | "reactivated") {
        if (busy || member.user_id === staff.id) return;
        const nextRole = action === "role_changed" ? (rolesDraft[member.user_id] ?? member.role) : null;
        if (action === "role_changed" && nextRole === member.role) return;
        const key = `${member.user_id}:${member.version}:${action}:${nextRole ?? ""}`;
        actionKeys.current[key] ??= crypto.randomUUID();
        setBusy(true);
        setError(null);
        setNotice(null);
        try {
            await crm(`staff/${member.user_id}/change`, {
                method: "POST",
                body: {
                    action,
                    role: nextRole,
                    expected_version: member.version,
                    operation_key: actionKeys.current[key],
                },
            });
            delete actionKeys.current[key];
            setNotice(`Доступ сотрудника ${member.name} обновлён.`);
            setHistoryFor(null);
            setHistory(null);
            await refresh();
        } catch (caught) {
            fail(caught);
            if (caught instanceof CrmError && caught.status === 409) {
                try { await refresh(); } catch { /* leave original error visible */ }
            }
        } finally {
            setBusy(false);
        }
    }

    async function more() {
        if (cursor === null || busy) return;
        setBusy(true);
        setError(null);
        try {
            const response = await crm<Page>(`staff?before_id=${cursor}`);
            setItems((previous) => [...previous, ...response.items]);
            setCursor(response.next_before_id);
        } catch (caught) {
            fail(caught);
        } finally {
            setBusy(false);
        }
    }

    async function toggleHistory(id: number) {
        if (historyFor === id) {
            setHistoryFor(null);
            setHistory(null);
            return;
        }
        setHistoryFor(id);
        setHistory(null);
        setError(null);
        try {
            setHistory(await crm<EventRow[]>(`staff/${id}/history`));
        } catch (caught) {
            fail(caught);
        }
    }

    return <div className="crm68-page">
        <div className="crm65-pagehead">
            <div>
                <p className="crm65-overline">CRM / ДОСТУП</p>
                <h1>Сотрудники</h1>
                <p className="crm65-muted">Роли и доступ к рабочему пространству BOARDRIDERS</p>
            </div>
            <button type="button" className="crm65-secondary" disabled={busy}
                    onClick={() => { setLoading(true); refresh().catch(fail).finally(() => setLoading(false)); }}>
                ↻ Обновить
            </button>
        </div>

        <div className="crm65-panel crm68-create-panel">
            <h2>Добавить сотрудника</h2>
            <p className="crm65-muted">У человека уже должен быть подтверждённый аккаунт магазина. Новый пароль в CRM не создаётся и не передаётся.</p>
            <form className="crm68-create" onSubmit={addEmployee}>
                <label>Электронная почта
                    <input type="email" required maxLength={254} autoComplete="off" value={email}
                           placeholder="employee@example.com"
                           onChange={(event) => { setEmail(event.target.value); grantKey.current = null; }}/>
                </label>
                <label>Роль
                    <select value={role} onChange={(event) => { setRole(event.target.value as Role); grantKey.current = null; }}>
                        {roles.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                </label>
                <button type="submit" className="crm65-primary" disabled={busy || !email.trim()}>
                    {busy ? "Сохраняем..." : "Добавить в CRM"}
                </button>
            </form>
        </div>

        {error && <p className="crm65-error" role="alert">{error}</p>}
        {notice && <p className="crm68-success" role="status">{notice}</p>}

        <section className="crm65-panel crm68-list">
            <div className="crm65-panel-head"><h2>Список сотрудников</h2></div>
            {loading ? <p className="crm65-empty">Загружаем сотрудников...</p>
                : items.length === 0 ? <p className="crm65-empty">Сотрудников пока нет.</p>
                    : items.map((member) => <article className="crm68-person" key={member.user_id}>
                        <div className="crm68-person-top">
                            <div className="crm68-person-name">
                                <strong>{member.name}</strong>
                                <small>{member.email} · #{member.user_id} · изменено {when(member.updated_at)}</small>
                            </div>
                            <div className="crm68-badges">
                                <span className="crm65-pill">{roleName(member.role)}</span>
                                <span className={`crm65-pill ${member.active ? "is-ok" : "is-warning"}`}>
                {member.active ? "Активен" : "Заблокирован"}
              </span>
                            </div>
                        </div>
                        <div className="crm68-controls">
                            <label>Роль
                                <select disabled={busy || member.user_id === staff.id}
                                        value={rolesDraft[member.user_id] ?? member.role}
                                        onChange={(event) => setRolesDraft((value) => ({ ...value, [member.user_id]: event.target.value as Role }))}>
                                    {roles.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                </select>
                            </label>
                            <button className="crm65-secondary" type="button" disabled={busy || member.user_id === staff.id ||
                                (rolesDraft[member.user_id] ?? member.role) === member.role}
                                    onClick={() => change(member, "role_changed")}>Сменить роль</button>
                            {member.active
                                ? <button type="button" className="crm68-danger" disabled={busy || member.user_id === staff.id}
                                          onClick={() => { if (window.confirm(`Заблокировать доступ ${member.name}?`)) void change(member, "deactivated"); }}>
                                    Заблокировать
                                </button>
                                : <button type="button" className="crm65-secondary" disabled={busy || member.user_id === staff.id}
                                          onClick={() => change(member, "reactivated")}>Восстановить</button>}
                            <button type="button" className="crm65-ghost"
                                    onClick={() => toggleHistory(member.user_id)}>
                                {historyFor === member.user_id ? "Скрыть историю" : "История изменений"}
                            </button>
                        </div>
                        {member.user_id === staff.id && <p className="crm65-muted crm68-footnote">Собственный доступ изменять нельзя.</p>}
                        {historyFor === member.user_id && <div className="crm68-history">
                            {history === null ? <p>Загружаем историю...</p>
                                : history.length === 0 ? <p>Пока нет событий после подключения блока 68.</p>
                                    : history.map((row) => <div key={row.operation_key}>
                                        <strong>{eventName[row.action] ?? row.action}</strong>
                                        <span>{row.from_role ? `${roleName(row.from_role)} → ` : ""}{roleName(row.to_role)}</span>
                                        <small>Сотрудник #{row.actor_id} · {when(row.created_at)}</small>
                                    </div>)}
                        </div>}
                    </article>)}
            {cursor !== null && <div className="crm68-more">
                <button type="button" className="crm65-secondary" disabled={busy} onClick={more}>Показать ещё</button>
            </div>}
        </section>
    </div>;
}