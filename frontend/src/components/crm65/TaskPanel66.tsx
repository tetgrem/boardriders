"use client";

import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { crm, CrmError, when } from "@/lib/crm65/client";
import type { StaffIdentity } from "@/lib/crm65/session";

type Task = {
    task_id: number;
    title: string;
    priority: "low" | "normal" | "high" | "critical";
    status: "open" | "in_progress" | "resolved";
    assigned_to: number | null;
    created_by: number;
    version: number;
    action: string;
    actor_id: number;
    replayed: boolean;
};
type TaskEvent = Task & { created_at: string };
type Action = "claim" | "release" | "start" | "resolve" | "reopen";

const priorityLabels: Record<Task["priority"], string> = {
    low: "Низкий", normal: "Обычный", high: "Высокий", critical: "Критический",
};
const statusLabels: Record<Task["status"], string> = {
    open: "Открыта", in_progress: "В работе", resolved: "Завершена",
};
const eventLabels: Record<string, string> = {
    created: "Создана", claimed: "Назначена", released: "Освобождена",
    started: "Начата", resolved: "Завершена", reopened: "Открыта снова",
};

export default function TaskPanel66({ orderKey, staff }: {
    orderKey: string;
    staff: StaffIdentity;
}) {
    const router = useRouter();
    const canManage = staff.abilities.includes("crm66.tasks.manage");
    const [tasks, setTasks] = useState<Task[]>([]);
    const [total, setTotal] = useState(0);
    const [truncated, setTruncated] = useState(false);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [title, setTitle] = useState("");
    const [priority, setPriority] = useState<Task["priority"]>("normal");
    const [error, setError] = useState<string | null>(null);
    const [openHistory, setOpenHistory] = useState<number | null>(null);
    const [history, setHistory] = useState<TaskEvent[] | null>(null);
    const createKey = useRef<string | null>(null);
    const actionKeys = useRef<Record<string, string>>({});

    const onError = useCallback((caught: unknown) => {
        if (caught instanceof CrmError && caught.status === 401) {
            router.replace("/admin/login");
            return;
        }
        setError(caught instanceof Error ? caught.message : "Ошибка работы с задачами");
    }, [router]);

    const reload = useCallback(async () => {
        const data = await crm<{ items: Task[]; total: number; truncated: boolean }>(
            `orders/${orderKey}/tasks`
        );
        setTasks(data.items);
        setTotal(data.total);
        setTruncated(data.truncated);
    }, [orderKey]);

    useEffect(() => {
        let cancelled = false;
        crm<{ items: Task[]; total: number; truncated: boolean }>(`orders/${orderKey}/tasks`)
            .then((data) => {
                if (cancelled) return;
                setTasks(data.items);
                setTotal(data.total);
                setTruncated(data.truncated);
            })
            .catch((caught: unknown) => { if (!cancelled) onError(caught); })
            .finally(() => { if (!cancelled) setLoading(false); });
        return () => { cancelled = true; };
    }, [orderKey, onError]);

    async function createTask(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (busy || title.trim().length < 4) return;
        setBusy(true);
        setError(null);
        if (!createKey.current) createKey.current = crypto.randomUUID();
        try {
            await crm(`orders/${orderKey}/tasks`, {
                method: "POST",
                body: { operation_key: createKey.current, title: title.trim(), priority },
            });
            createKey.current = null;
            setTitle("");
            setPriority("normal");
            await reload();
        } catch (caught) {
            onError(caught);
        } finally {
            setBusy(false);
        }
    }

    async function act(task: Task, action: Action) {
        if (busy) return;
        const idempotencySlot = `${task.task_id}:${task.version}:${action}`;
        actionKeys.current[idempotencySlot] ??= crypto.randomUUID();
        setBusy(true);
        setError(null);
        try {
            await crm(`orders/${orderKey}/tasks/${task.task_id}/action`, {
                method: "POST",
                body: {
                    operation_key: actionKeys.current[idempotencySlot],
                    action, expected_version: task.version,
                },
            });
            delete actionKeys.current[idempotencySlot];
            setOpenHistory(null);
            setHistory(null);
            await reload();
        } catch (caught) {
            onError(caught);
            // A conflict may mean somebody else completed the action. Show fresh state.
            if (caught instanceof CrmError && caught.status === 409) {
                try { await reload(); } catch { /* keep original error */ }
            }
        } finally {
            setBusy(false);
        }
    }

    async function toggleHistory(taskId: number) {
        if (openHistory === taskId) {
            setOpenHistory(null);
            setHistory(null);
            return;
        }
        setOpenHistory(taskId);
        setHistory(null);
        setError(null);
        try {
            const data = await crm<TaskEvent[]>(`orders/${orderKey}/tasks/${taskId}/history`);
            setHistory(data);
        } catch (caught) { onError(caught); }
    }

    return (
        <div className="crm66-panel">
            <div className="crm66-heading">
                <div>
                    <h2>Задачи по заказу</h2>
                    <p className="crm65-muted">Внутренние задания сотрудников. Они не меняют оплату, возвраты и доставку.</p>
                </div>
                <button type="button" className="crm65-secondary" disabled={busy}
                        onClick={() => { setLoading(true); reload().catch(onError).finally(() => setLoading(false)); }}>
                    ↻ Обновить
                </button>
            </div>

            {canManage && <form className="crm66-create" onSubmit={createTask}>
                <label>Новая задача
                    <input value={title} minLength={4} maxLength={160} required
                           onChange={(e) => { setTitle(e.target.value); createKey.current = null; }}
                           placeholder="Например, уточнить адрес получателя" />
                </label>
                <label>Приоритет
                    <select value={priority} onChange={(e) => {
                        setPriority(e.target.value as Task["priority"]);
                        createKey.current = null;
                    }}>
                        {Object.entries(priorityLabels).map(([value, label]) =>
                            <option key={value} value={value}>{label}</option>
                        )}
                    </select>
                </label>
                <button type="submit" disabled={busy || title.trim().length < 4} className="crm65-primary">
                    {busy ? "Выполняется..." : "Создать задачу"}
                </button>
            </form>}

            {error && <p role="alert" className="crm65-error">{error}</p>}
            {loading ? <p className="crm65-muted">Загружаем задачи...</p> : tasks.length === 0
                ? <p className="crm65-empty">Задач по этому заказу пока нет.</p>
                : <>
                    <p className="crm65-muted">Всего задач: {total}{truncated ? " · показаны последние 100" : ""}</p>
                    <div className="crm66-tasks">
                        {tasks.map((task) => {
                            const mine = task.assigned_to === staff.id;
                            return <article className="crm66-task" key={task.task_id}>
                                <div className="crm66-task-top">
                                    <strong>#{task.task_id} · {task.title}</strong>
                                    <span className="crm65-pill">{priorityLabels[task.priority]}</span>
                                </div>
                                <p className="crm65-muted">
                                    {statusLabels[task.status]} · {task.assigned_to
                                    ? `Исполнитель #${task.assigned_to}${mine ? " (вы)" : ""}`
                                    : "Без исполнителя"} · версия {task.version}
                                </p>
                                <div className="crm66-actions">
                                    {canManage && task.status === "open" && task.assigned_to === null &&
                                        <button disabled={busy} onClick={() => act(task, "claim")}>Взять себе</button>}
                                    {canManage && task.status === "open" && mine && <>
                                        <button disabled={busy} onClick={() => act(task, "start")}>Начать</button>
                                        <button disabled={busy} onClick={() => act(task, "release")}>Освободить</button>
                                    </>}
                                    {canManage && task.status === "in_progress" && mine &&
                                        <button disabled={busy} onClick={() => act(task, "resolve")}>Завершить</button>}
                                    {canManage && task.status === "resolved" &&
                                        <button disabled={busy} onClick={() => act(task, "reopen")}>Открыть снова</button>}
                                    <button onClick={() => toggleHistory(task.task_id)}>
                                        {openHistory === task.task_id ? "Скрыть историю" : "История"}
                                    </button>
                                </div>
                                {openHistory === task.task_id && <div className="crm66-history">
                                    {history === null ? "Загрузка истории..." : history.map((record) =>
                                        <div key={record.version}>
                                            <span>{eventLabels[record.action] ?? record.action} · версия {record.version}</span>
                                            <small>Сотрудник #{record.actor_id} · {when(record.created_at)}</small>
                                        </div>
                                    )}
                                </div>}
                            </article>;
                        })}
                    </div>
                </>}
        </div>
    );
}
