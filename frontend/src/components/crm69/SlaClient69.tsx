"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { crm, CrmError, when } from "@/lib/crm65/client";
import type { StaffIdentity } from "@/lib/crm65/session";

type Filter = "all" | "mine" | "overdue" | "soon" | "unplanned";
type Task = {
    task_id: number;
    order_key: string;
    title: string;
    status: "open" | "in_progress" | "resolved";
    priority: "low" | "normal" | "high" | "critical";
    version: number;
    assigned_to: number | null;
    assignee_name: string | null;
    due_at: string | null;
    sla_revision: number;
    is_overdue: boolean;
};
type Overview = {
    items: Task[];
    next_before_id: number | null;
    summary: { active: number; overdue: number; soon: number; unplanned: number };
    workload: Array<{ user_id: number; name: string; active_tasks: number }>;
};
type Member = { user_id: number; name: string; role: string };
type History = {
    deadlines: Array<{ operation_key: string; actor_id: number; from_due_at: string | null; to_due_at: string | null; revision: number; created_at: string }>;
    comments: Array<{ operation_key: string; actor_id: number; body: string; created_at: string }>;
};

const filterNames: Record<Filter, string> = {
    all: "Все", mine: "Мои", overdue: "Просрочены", soon: "Скоро срок", unplanned: "Без срока",
};
const priorityNames: Record<Task["priority"], string> = {
    low: "Низкий", normal: "Обычный", high: "Высокий", critical: "Критический",
};
const statusNames: Record<Task["status"], string> = {
    open: "Открыта", in_progress: "В работе", resolved: "Завершена",
};

export default function SlaClient69({ staff }: { staff: StaffIdentity }) {
    const router = useRouter();
    const supervisor = staff.abilities.includes("crm69.sla.manage");
    const [filter, setFilter] = useState<Filter>("all");
    const [cursor, setCursor] = useState<number | null>(null);
    const [pages, setPages] = useState<Array<number | null>>([]);
    const [revision, setRevision] = useState(0);
    const [data, setData] = useState<Overview | null>(null);
    const [people, setPeople] = useState<Member[]>([]);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [dates, setDates] = useState<Record<number, string>>({});
    const [assignees, setAssignees] = useState<Record<number, string>>({});
    const [comments, setComments] = useState<Record<number, string>>({});
    const [expanded, setExpanded] = useState<number | null>(null);
    const [history, setHistory] = useState<History | null>(null);
    const keys = useRef<Record<string, string>>({});

    const fail = useCallback((caught: unknown) => {
        if (caught instanceof CrmError && caught.status === 401) {
            router.replace("/admin/login");
            return;
        }
        setError(caught instanceof Error ? caught.message : "Не удалось выполнить действие CRM");
    }, [router]);

    useEffect(() => {
        let live = true;
        crm<Member[]>("sla/assignees")
            .then((list) => { if (live) setPeople(list); })
            .catch((caught) => { if (live) fail(caught); });
        return () => { live = false; };
    }, [fail]);

    useEffect(() => {
        let live = true;
        setLoading(true);
        setError(null);
        const params = new URLSearchParams({ filter, limit: "20" });
        if (cursor !== null) params.set("before_id", String(cursor));
        crm<Overview>(`sla?${params}`)
            .then((result) => { if (live) setData(result); })
            .catch((caught) => { if (live) { setData(null); fail(caught); } })
            .finally(() => { if (live) setLoading(false); });
        return () => { live = false; };
    }, [filter, cursor, revision, fail]);

    function selectFilter(value: Filter) {
        setFilter(value);
        setCursor(null);
        setPages([]);
    }

    function reload() { setRevision((value) => value + 1); }

    async function perform(taskId: number, endpoint: string, payload: Record<string, unknown>, slot: string) {
        if (busy !== null) return;
        keys.current[slot] ??= crypto.randomUUID();
        setBusy(taskId);
        setError(null);
        try {
            await crm(`sla/tasks/${taskId}/${endpoint}`, {
                method: "POST", body: { ...payload, operation_key: keys.current[slot] },
            });
            delete keys.current[slot];
            if (endpoint === "comment") setComments((old) => ({ ...old, [taskId]: "" }));
            setExpanded(null);
            setHistory(null);
            reload();
        } catch (caught) {
            fail(caught);
            if (caught instanceof CrmError && caught.status === 409) reload();
        } finally {
            setBusy(null);
        }
    }

    function deadline(task: Task, clear = false) {
        const draft = dates[task.task_id] ?? "";
        const parsed = clear ? null : new Date(draft);
        if (!clear && (!draft || parsed === null || !Number.isFinite(parsed.getTime()))) {
            setError("Выбери корректную дату и время");
            return;
        }
        const due = parsed ? parsed.toISOString().replace(/\.\d{3}Z$/, "Z") : null;
        void perform(task.task_id, "deadline", {
            expected_revision: task.sla_revision, due_at: due,
        }, `deadline:${task.task_id}:${task.sla_revision}:${due ?? "clear"}`);
    }

    function assign(task: Task) {
        const value = assignees[task.task_id] ?? String(task.assigned_to ?? "");
        const target = value === "" ? null : Number(value);
        if (target === task.assigned_to || (target !== null && (!Number.isSafeInteger(target) || target < 1))) return;
        void perform(task.task_id, "assign", {
            expected_version: task.version, assignee_user_id: target,
        }, `assign:${task.task_id}:${task.version}:${value}`);
    }

    function comment(task: Task) {
        const body = (comments[task.task_id] ?? "").trim();
        if (body.length < 2 || body.length > 500) return;
        void perform(task.task_id, "comment", { body }, `comment:${task.task_id}:${body}`);
    }

    async function showHistory(taskId: number) {
        if (expanded === taskId) { setExpanded(null); setHistory(null); return; }
        setExpanded(taskId);
        setHistory(null);
        try { setHistory(await crm<History>(`sla/tasks/${taskId}/history`)); }
        catch (caught) { fail(caught); }
    }

    return <div className="crm69-page">
        <div className="crm65-pagehead">
            <div>
                <p className="crm65-overline">CRM / SLA</p>
                <h1>Сроки и нагрузка команды</h1>
                <p className="crm65-muted">Контроль внутренних задач. Срок SLA не является обещанной датой доставки.</p>
            </div>
            <button type="button" className="crm65-secondary" disabled={loading || busy !== null} onClick={reload}>↻ Обновить</button>
        </div>
        {error && <p className="crm65-error" role="alert">{error}</p>}
        <div className="crm69-filters" role="group" aria-label="Фильтр задач SLA">
            {(Object.keys(filterNames) as Filter[]).map((value) =>
                <button key={value} type="button" className={filter === value ? "active" : ""}
                        aria-pressed={filter === value} onClick={() => selectFilter(value)}>{filterNames[value]}</button>
            )}
        </div>
        {loading && <p className="crm65-muted">Загружаем данные SLA...</p>}
        {data && <>
            <div className="crm69-summary">
                {([
                    ["Активных", data.summary.active], ["Просрочены", data.summary.overdue],
                    ["Истекают за 24 часа", data.summary.soon], ["Без срока", data.summary.unplanned],
                ] as Array<[string, number]>).map(([title, value]) =>
                    <div className="crm69-metric" key={title}><small>{title}</small><strong>{value}</strong></div>
                )}
            </div>
            <section className="crm65-panel crm69-workload">
                <h2>Нагрузка сотрудников</h2>
                <div className="crm69-workers">
                    {data.workload.length === 0 ? <p className="crm65-muted">Нет сотрудников</p> :
                        data.workload.map((worker) => <div key={worker.user_id}>
                            <span>{worker.name}</span><strong>{worker.active_tasks}</strong>
                        </div>)}
                </div>
            </section>
            <section className="crm69-list">
                {data.items.length === 0 ? <p className="crm65-empty">По выбранному фильтру задач нет.</p> :
                    data.items.map((task) => <article key={task.task_id}
                                                      className={`crm65-panel crm69-task ${task.is_overdue ? "crm69-overdue" : ""}`}>
                        <div className="crm69-task-header">
                            <div>
                                <strong>#{task.task_id} · {task.title}</strong>
                                <p className="crm65-muted">{statusNames[task.status]} · {priorityNames[task.priority]} · {task.assignee_name ?? "Не назначена"}</p>
                            </div>
                            <Link href={`/admin/orders/${task.order_key}`} className="crm65-secondary">Открыть заказ</Link>
                        </div>
                        <div className="crm69-due">
                            <span>Срок: <strong>{task.due_at ? when(task.due_at) : "Не установлен"}</strong></span>
                            {task.is_overdue && <span className="crm69-warning">Просрочено</span>}
                        </div>
                        {supervisor && task.status !== "resolved" && <div className="crm69-controls">
                            <label>Установить срок (местное время)
                                <input type="datetime-local" value={dates[task.task_id] ?? ""}
                                       onChange={(e) => setDates((old) => ({ ...old, [task.task_id]: e.target.value }))}/>
                            </label>
                            <button disabled={busy !== null || !dates[task.task_id]} type="button" onClick={() => deadline(task)}>Сохранить срок</button>
                            <button disabled={busy !== null || !task.due_at} type="button" onClick={() => deadline(task, true)}>Без срока</button>
                            <label>Ответственный
                                <select value={assignees[task.task_id] ?? String(task.assigned_to ?? "")}
                                        onChange={(e) => setAssignees((old) => ({ ...old, [task.task_id]: e.target.value }))}>
                                    <option value="">Без исполнителя</option>
                                    {people.map((person) => <option key={person.user_id} value={person.user_id}>{person.name} · {person.role}</option>)}
                                </select>
                            </label>
                            <button type="button" disabled={busy !== null} onClick={() => assign(task)}>Назначить</button>
                        </div>}
                        <div className="crm69-note-compose">
                            <label>Внутренний комментарий
                                <textarea rows={2} maxLength={500} value={comments[task.task_id] ?? ""}
                                          onChange={(e) => setComments((old) => ({ ...old, [task.task_id]: e.target.value }))}
                                          placeholder="Что сделано или нужно уточнить"/>
                            </label>
                            <button type="button" disabled={busy !== null || (comments[task.task_id] ?? "").trim().length < 2}
                                    onClick={() => comment(task)}>Добавить комментарий</button>
                        </div>
                        <button className="crm65-ghost" type="button" onClick={() => void showHistory(task.task_id)}>
                            {expanded === task.task_id ? "Скрыть историю" : "История SLA и комментариев"}
                        </button>
                        {expanded === task.task_id && <div className="crm69-history">
                            {history === null ? <p>Загружаем историю...</p> : <>
                                <h3>Комментарии</h3>
                                {history.comments.length === 0 && <p>Комментариев пока нет.</p>}
                                {history.comments.map((item) => <div key={item.operation_key}>
                                    <span>{item.body}</span><small>#{item.actor_id} · {when(item.created_at)}</small>
                                </div>)}
                                <h3>Сроки</h3>
                                {history.deadlines.length === 0 && <p>Срок пока не менялся.</p>}
                                {history.deadlines.map((item) => <div key={item.operation_key}>
                                    <span>{item.from_due_at ? when(item.from_due_at) : "Без срока"} → {item.to_due_at ? when(item.to_due_at) : "Без срока"}</span>
                                    <small>#{item.actor_id} · {when(item.created_at)}</small>
                                </div>)}
                            </>}
                        </div>}
                    </article>)}
            </section>
            <div className="crm69-pagination">
                <span className="crm65-muted">Страница {pages.length + 1}</span>
                <button type="button" disabled={loading || busy !== null || pages.length === 0}
                        onClick={() => { setCursor(pages[pages.length - 1]); setPages((old) => old.slice(0, -1)); }}>Назад</button>
                <button type="button" disabled={loading || busy !== null || data.next_before_id === null}
                        onClick={() => { setPages((old) => [...old, cursor]); setCursor(data.next_before_id); }}>Далее</button>
            </div>
        </>}
    </div>;
}