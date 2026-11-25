"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { crm, CrmError, when } from "@/lib/crm65/client";

type Category =
    | "orders"
    | "payments"
    | "returns"
    | "delivery"
    | "fiscal"
    | "sla";

type Severity = "info" | "warning" | "critical";

type NotificationItem = {
    notification_key: string;
    category: Category;
    severity: Severity;
    order_key: string;
    source_id: number;
    occurred_at: string;
    read_at: string | null;
    is_read: boolean;
};

type Preferences = Partial<Record<Category, boolean>>;

type Feed = {
    items: NotificationItem[];
    summary: {
        total: number;
        unread: number;
        critical: number;
        warning: number;
    };
    preferences: Preferences;
    page: number;
    has_more: boolean;
};

const categories: Record<Category, string> = {
    orders: "Заказы",
    payments: "Оплата",
    returns: "Возвраты",
    delivery: "Доставка",
    fiscal: "Фискализация",
    sla: "SLA",
};

const titles: Record<Category, string> = {
    orders: "Новый заказ",
    payments: "Оплата требует проверки",
    returns: "Возврат ожидает решения",
    delivery: "Доставка требует внимания",
    fiscal: "Открытый фискальный инцидент",
    sla: "Просрочена задача",
};

const descriptions: Record<Category, string> = {
    orders: "Проверьте новый заказ и необходимые действия.",
    payments: "Необходимо проверить состояние оплаты.",
    returns: "По возврату ещё не принято решение.",
    delivery: "Последнее состояние ТТН требует проверки.",
    fiscal: "Инцидент фискализации ещё не закрыт.",
    sla: "Установленный срок выполнения задачи истёк.",
};

const icons: Record<Category, string> = {
    orders: "▤",
    payments: "₴",
    returns: "↩",
    delivery: "⇄",
    fiscal: "▧",
    sla: "◷",
};

export default function NotificationsClient70() {
    const router = useRouter();

    const [data, setData] = useState<Feed | null>(null);
    const [category, setCategory] =
        useState<Category | "all">("all");

    const [unreadOnly, setUnreadOnly] = useState(false);
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);

    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);

    const [showSettings, setShowSettings] = useState(false);

    const [loadError, setLoadError] =
        useState<string | null>(null);

    const [actionError, setActionError] =
        useState<string | null>(null);

    const [updatedAt, setUpdatedAt] =
        useState<string | null>(null);

    const reload = useCallback(() => {
        setRevision((value) => value + 1);
    }, []);

    const message = useCallback(
        (caught: unknown): string => {
            if (
                caught instanceof CrmError
                && caught.status === 401
            ) {
                router.replace("/admin/login");
            }

            return caught instanceof Error
                ? caught.message
                : "Не удалось выполнить запрос CRM";
        },
        [router]
    );

    useEffect(() => {
        let active = true;

        setLoading(true);
        setLoadError(null);

        const params = new URLSearchParams({
            category,
            unread: unreadOnly ? "1" : "0",
            page: String(page),
            limit: "25",
        });

        crm<Feed>(`notifications?${params.toString()}`)
            .then((response) => {
                if (!active) return;

                setData(response);
                setUpdatedAt(new Date().toISOString());
            })
            .catch((caught: unknown) => {
                if (!active) return;

                setLoadError(message(caught));
                setData(null);
            })
            .finally(() => {
                if (active) setLoading(false);
            });

        return () => {
            active = false;
        };
    }, [
        category,
        unreadOnly,
        page,
        revision,
        message,
    ]);

    useEffect(() => {
        if (busy) return;

        const timer = window.setInterval(() => {
            if (document.visibilityState === "visible") {
                reload();
            }
        }, 30000);

        return () => window.clearInterval(timer);
    }, [busy, reload]);

    function chooseCategory(next: Category | "all") {
        if (next === category && page === 1) return;

        setCategory(next);
        setPage(1);
        setData(null);
    }

    function chooseUnread(next: boolean) {
        if (next === unreadOnly && page === 1) return;

        setUnreadOnly(next);
        setPage(1);
        setData(null);
    }
    async function changeReadState(
        item: NotificationItem
    ) {
        if (busy) return;

        setBusy(true);
        setActionError(null);

        try {
            await crm(
                item.is_read
                    ? "notifications/unread"
                    : "notifications/read",
                {
                    method: "POST",
                    body: {
                        notification_key:
                        item.notification_key,
                    },
                }
            );

            if (unreadOnly) {
                setPage(1);
            }

            reload();

            window.dispatchEvent(
                new Event("crm70:changed")
            );
        } catch (caught) {
            setActionError(message(caught));

            if (
                caught instanceof CrmError
                && caught.status === 404
            ) {
                reload();
            }
        } finally {
            setBusy(false);
        }
    }

    async function changePreference(
        key: Category,
        enabled: boolean
    ) {
        if (busy) return;

        setBusy(true);
        setActionError(null);

        try {
            await crm("notifications/preference", {
                method: "POST",
                body: {
                    category: key,
                    enabled,
                },
            });

            if (!enabled && category === key) {
                setCategory("all");
            }

            setPage(1);
            reload();

            window.dispatchEvent(
                new Event("crm70:changed")
            );
        } catch (caught) {
            setActionError(message(caught));
        } finally {
            setBusy(false);
        }
    }

    const availableCategories = (
        Object.keys(categories) as Category[]
    ).filter(
        (key) =>
            data?.preferences[key] !== undefined
    );

    return (
        <div className="crm70-page">
            <div className="crm65-pagehead">
                <div>
                    <p className="crm65-overline">
                        CRM / УВЕДОМЛЕНИЯ
                    </p>

                    <h1>Центр уведомлений</h1>

                    <p className="crm65-muted">
                        Актуальные события и задачи,
                        требующие внимания.
                    </p>
                </div>

                <div className="crm70-header-actions">
                    <button
                        type="button"
                        className="crm65-secondary"
                        disabled={loading || busy}
                        onClick={reload}
                    >
                        ↻ Обновить
                    </button>

                    <button
                        type="button"
                        className="crm65-secondary"
                        disabled={busy}
                        aria-expanded={showSettings}
                        onClick={() =>
                            setShowSettings(
                                (value) => !value
                            )
                        }
                    >
                        ⚙ Настройки
                    </button>
                </div>
            </div>

            {loadError && (
                <p className="crm65-error" role="alert">
                    {loadError}
                </p>
            )}

            {actionError && (
                <p className="crm65-error" role="alert">
                    {actionError}
                </p>
            )}

            {loading && (
                <p className="crm65-muted" role="status">
                    Обновляем уведомления...
                </p>
            )}

            {data && (
                <>
                    <div className="crm70-summary">
                        <div className="crm70-metric">
                            <small>Всего актуальных</small>
                            <strong>{data.summary.total}</strong>
                        </div>

                        <div className="crm70-metric">
                            <small>Непрочитанные</small>
                            <strong>{data.summary.unread}</strong>
                        </div>

                        <div className="crm70-metric crm70-metric-critical">
                            <small>Критические непрочитанные</small>
                            <strong>{data.summary.critical}</strong>
                        </div>

                        <div className="crm70-metric crm70-metric-warning">
                            <small>Требуют внимания</small>
                            <strong>{data.summary.warning}</strong>
                        </div>
                    </div>

                    {showSettings && (
                        <section className="crm65-panel crm70-settings">
                            <h2>Мои уведомления</h2>

                            <p className="crm65-muted">
                                Настройки действуют только
                                для вашей учётной записи.
                            </p>

                            <div className="crm70-settings-grid">
                                {availableCategories.map(
                                    (key) => (
                                        <label
                                            className="crm70-setting"
                                            key={key}
                                        >
                                            <span>
                                                {categories[key]}
                                            </span>

                                            <input
                                                type="checkbox"
                                                checked={
                                                    data.preferences[key]
                                                    === true
                                                }
                                                disabled={busy}
                                                onChange={(event) =>
                                                    void changePreference(
                                                        key,
                                                        event.target.checked
                                                    )
                                                }
                                            />
                                        </label>
                                    )
                                )}
                            </div>
                        </section>
                    )}

                    <div
                        className="crm70-filters"
                        role="group"
                        aria-label="Категории уведомлений"
                    >
                        <button
                            type="button"
                            className={
                                category === "all"
                                    ? "active"
                                    : ""
                            }
                            aria-pressed={category === "all"}
                            disabled={busy}
                            onClick={() => chooseCategory("all")}
                        >
                            Все
                        </button>

                        {availableCategories
                            .filter(
                                (key) =>
                                    data.preferences[key] === true
                            )
                            .map((key) => (
                                <button
                                    type="button"
                                    key={key}
                                    className={
                                        category === key
                                            ? "active"
                                            : ""
                                    }
                                    aria-pressed={category === key}
                                    disabled={busy}
                                    onClick={() =>
                                        chooseCategory(key)
                                    }
                                >
                                    {categories[key]}
                                </button>
                            ))}

                        <label className="crm70-unread-filter">
                            <input
                                type="checkbox"
                                checked={unreadOnly}
                                disabled={busy}
                                onChange={(event) =>
                                    chooseUnread(
                                        event.target.checked
                                    )
                                }
                            />
                            Только непрочитанные
                        </label>
                    </div>

                    <section className="crm65-panel crm70-list">
                        <div className="crm65-panel-head">
                            <h2>События</h2>

                            <span className="crm65-muted">
                                Страница {page}
                            </span>
                        </div>

                        {data.items.length === 0 && (
                            <div className="crm70-empty">
                                <span>✓</span>
                                <strong>
                                    Уведомлений нет
                                </strong>
                                <p>
                                    По выбранному фильтру
                                    нет актуальных событий.
                                </p>
                            </div>
                        )}

                        {data.items.map((item) => (
                            <article
                                key={item.notification_key}
                                className={[
                                    "crm70-item",
                                    `crm70-${item.severity}`,
                                    item.is_read
                                        ? "crm70-is-read"
                                        : "crm70-is-unread",
                                ].join(" ")}
                            >
                                <div
                                    className="crm70-item-icon"
                                    aria-hidden="true"
                                >
                                    {icons[item.category]}
                                </div>

                                <div className="crm70-item-main">
                                    <div className="crm70-item-title">
                                        {!item.is_read && (
                                            <span
                                                className="crm70-unread-dot"
                                                aria-label="Не прочитано"
                                            />
                                        )}

                                        <strong>
                                            {titles[item.category]}
                                        </strong>

                                        {item.severity === "critical"
                                            && (
                                                <span className="crm70-severity">
                                                    Критическое
                                                </span>
                                            )}
                                    </div>

                                    <p>
                                        {descriptions[item.category]}
                                    </p>

                                    <small>
                                        #{item.source_id}
                                        {" · "}
                                        {when(item.occurred_at)}
                                    </small>

                                    {item.is_read && item.read_at && (
                                        <small>
                                            Прочитано:{" "}
                                            {when(item.read_at)}
                                        </small>
                                    )}
                                </div>

                                <div className="crm70-item-actions">
                                    <Link
                                        className="crm65-secondary"
                                        href={
                                            `/admin/orders/${item.order_key}`
                                        }
                                    >
                                        Открыть заказ
                                    </Link>

                                    <button
                                        type="button"
                                        disabled={busy || loading}
                                        onClick={() =>
                                            void changeReadState(item)
                                        }
                                    >
                                        {item.is_read
                                            ? "Сделать непрочитанным"
                                            : "Отметить прочитанным"}
                                    </button>
                                </div>
                            </article>
                        ))}
                    </section>

                    <div className="crm70-pagination">
                        <button
                            type="button"
                            disabled={
                                page === 1 || loading || busy
                            }
                            onClick={() =>
                                setPage((value) =>
                                    Math.max(1, value - 1)
                                )
                            }
                        >
                            Назад
                        </button>

                        <span>Страница {page}</span>

                        <button
                            type="button"
                            disabled={
                                !data.has_more || loading || busy
                            }
                            onClick={() =>
                                setPage((value) => value + 1)
                            }
                        >
                            Далее
                        </button>
                    </div>
                </>
            )}

            <p className="crm65-muted crm70-footer">
                Автообновление каждые 30 секунд
                при открытой вкладке.
                {updatedAt
                    ? ` Последнее обновление: ${when(updatedAt)}.`
                    : ""}
            </p>
        </div>
    );
}