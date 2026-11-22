"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { crm, CrmError, when } from "@/lib/crm65/client";
import type { StaffIdentity } from "@/lib/crm65/session";

type Scope = "mine" | "unassigned" | "all";
type Status = "all" | "open" | "in_progress" | "resolved";
type Priority = "all" | "low" | "normal" | "high" | "critical";

type InboxTask = {
  task_id: number;
  order_key: string;
  title: string;
  priority: Exclude<Priority, "all">;
  status: Exclude<Status, "all">;
  assigned_to: number | null;
  assignee_name: string | null;
  created_at: string;
  age_minutes: number;
  version: number;
};

type InboxResponse = {
  items: InboxTask[];
  next_before_id: number | null;
  summary: {
    total: number;
    open: number;
    in_progress: number;
    resolved: number;
    unassigned: number;
    important_active: number;
  };
  scope: Scope;
  read_only: boolean;
};

const statuses: Record<Exclude<Status, "all">, string> = {
  open: "Открыта",
  in_progress: "В работе",
  resolved: "Завершена",
};
const priorities: Record<Exclude<Priority, "all">, string> = {
  low: "Низкий",
  normal: "Обычный",
  high: "Высокий",
  critical: "Критический",
};

function ageLabel(minutes: number): string {
  if (minutes < 60) return `${minutes} мин`;
  if (minutes < 1440) return `${Math.floor(minutes / 60)} ч`;
  return `${Math.floor(minutes / 1440)} д`;
}

export default function InboxClient67({ staff }: { staff: StaffIdentity }) {
  const router = useRouter();
  const [scope, setScope] = useState<Scope>("mine");
  const [status, setStatus] = useState<Status>("all");
  const [priority, setPriority] = useState<Priority>("all");
  const [cursor, setCursor] = useState<number | null>(null);
  const [previous, setPrevious] = useState<Array<number | null>>([]);
  const [revision, setRevision] = useState(0);
  const [data, setData] = useState<InboxResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchPage = useCallback(async (beforeId: number | null) => {
    const params = new URLSearchParams({ scope, status, priority, limit: "25" });
    if (beforeId !== null) params.set("before_id", String(beforeId));
    return crm<InboxResponse>(`inbox?${params.toString()}`);
  }, [scope, status, priority]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);

    fetchPage(cursor)
      .then((result) => {
        if (!cancelled) setData(result);
      })
      .catch((caught: unknown) => {
        if (cancelled) return;
        if (caught instanceof CrmError && caught.status === 401) {
          router.replace("/admin/login");
          return;
        }
        setData(null);
        setError(caught instanceof Error ? caught.message : "Не удалось загрузить очередь");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => { cancelled = true; };
  }, [cursor, fetchPage, revision, router]);

  function changeScope(next: Scope) {
    setScope(next);
    setCursor(null);
    setPrevious([]);
    setData(null);
  }

  function changeStatus(next: Status) {
    setStatus(next);
    setCursor(null);
    setPrevious([]);
    setData(null);
  }

  function changePriority(next: Priority) {
    setPriority(next);
    setCursor(null);
    setPrevious([]);
    setData(null);
  }

  function nextPage() {
    if (!data?.next_before_id || loading) return;
    setPrevious((old) => [...old, cursor]);
    setCursor(data.next_before_id);
  }

  function previousPage() {
    if (!previous.length || loading) return;
    setCursor(previous[previous.length - 1]);
    setPrevious((old) => old.slice(0, -1));
  }

  return (
    <div className="crm67-page">
      <div className="crm65-pagehead">
        <div>
          <p className="crm65-overline">CRM / РАБОЧАЯ ОЧЕРЕДЬ</p>
          <h1>Задачи команды</h1>
          <p>Единая очередь задач из всех заказов. Сотрудник: {staff.name}.</p>
        </div>
        <button type="button" className="crm65-secondary" disabled={loading}
          onClick={() => setRevision((value) => value + 1)}>
          ↻ Обновить
        </button>
      </div>

      <div className="crm67-scopes" role="group" aria-label="Область очереди">
        {([
          ["mine", "Мои задачи"],
          ["unassigned", "Свободные"],
          ["all", "Все задачи"],
        ] as Array<[Scope, string]>).map(([key, text]) => (
          <button type="button" key={key} aria-pressed={scope === key}
            className={scope === key ? "active" : ""} onClick={() => changeScope(key)}>
            {text}
          </button>
        ))}
      </div>

      <div className="crm67-filters">
        <label>Статус
          <select value={status} onChange={(e) => changeStatus(e.target.value as Status)}>
            <option value="all">Все статусы</option>
            {Object.entries(statuses).map(([value, label]) => (
              <option value={value} key={value}>{label}</option>
            ))}
          </select>
        </label>
        <label>Приоритет
          <select value={priority} onChange={(e) => changePriority(e.target.value as Priority)}>
            <option value="all">Все приоритеты</option>
            {Object.entries(priorities).map(([value, label]) => (
              <option value={value} key={value}>{label}</option>
            ))}
          </select>
        </label>
        <span className="crm65-muted">Сначала новые задачи. Для сортировки по срочности используйте фильтр приоритета.</span>
      </div>

      {error && <p className="crm65-error" role="alert">{error}</p>}
      {loading && <p className="crm65-muted" role="status">Загружаем очередь...</p>}

      {data && (
        <>
          <div className="crm67-summary" aria-label="Сводка по выбранной области">
            {([
              ["Всего", data.summary.total],
              ["Открытые", data.summary.open],
              ["В работе", data.summary.in_progress],
              ["Завершены", data.summary.resolved],
              ["Без исполнителя", data.summary.unassigned],
              ["Высокий / критический", data.summary.important_active],
            ] as Array<[string, number]>).map(([label, count]) => (
              <div className="crm67-metric" key={label}>
                <small>{label}</small>
                <strong>{count}</strong>
              </div>
            ))}
          </div>

          <div className="crm65-panel crm67-list">
            <div className="crm65-panel-head">
              <h2>Список задач</h2>
              <span className="crm65-muted">Страница {previous.length + 1} · {data.items.length} записей</span>
            </div>
            {data.items.length === 0 ? (
              <p className="crm65-empty">По выбранным фильтрам задач нет.</p>
            ) : data.items.map((task) => (
              <article className="crm67-row" key={task.task_id}>
                <div className="crm67-main">
                  <div className="crm67-titleline">
                    <strong>#{task.task_id} · {task.title}</strong>
                    <span className={`crm65-pill ${task.priority === "critical" ? "is-warning" : ""}`}>
                      {priorities[task.priority]}
                    </span>
                  </div>
                  <p>
                    {statuses[task.status]} · {task.assignee_name ?? "Без исполнителя"}
                    {task.assigned_to === staff.id ? " (вы)" : ""}
                    {" · "}Возраст: {ageLabel(task.age_minutes)}
                  </p>
                  <small>Создана: {when(task.created_at)} · версия {task.version}</small>
                </div>
                <Link className="crm65-secondary crm67-open" href={`/admin/orders/${task.order_key}`}>
                  Заказ {task.order_key.slice(0, 8)} →
                </Link>
              </article>
            ))}
            <div className="crm65-table-footer">
              <span className="crm65-muted">Операции выполняются внутри карточки заказа.</span>
              <div className="crm65-pagination">
                <button type="button" disabled={loading || previous.length === 0} onClick={previousPage}>
                  Назад
                </button>
                <button type="button" disabled={loading || data.next_before_id === null} onClick={nextPage}>
                  Далее
                </button>
              </div>
            </div>
          </div>
          <p className="crm65-muted crm67-note">
            Возраст задачи — время с момента создания, не срок SLA. Сводные показатели строятся по текущему состоянию задач.
          </p>
        </>
      )}
    </div>
  );
}
