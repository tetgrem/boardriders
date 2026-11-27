"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { crm, CrmError, statusLabel, when } from "@/lib/crm65/client";

import {
    ContactForm74,
    InteractionForm74,
} from "./CustomerForms74";

import {
    channelLabels,
    eventLabels,
    formatMoney,
    sectionLabels,
    segmentLabels,
    signedUnits,

    type CustomerEvent,
    type LedgerEntry,
    type Order,
    type Page,
    type Profile,
    type ReturnCase,
    type Wallet,
    type WriteCommand,
    type WriteResult,
} from "./types";

type Tab =
    | "profile"
    | "orders"
    | "returns"
    | "wallets"
    | "history"
    | "access";

type ReadRow =
    | Order
    | ReturnCase
    | Wallet
    | LedgerEntry
    | CustomerEvent;

type Pending = WriteCommand & {
    operation_key: string;
};

export default function CustomerClient74({
                                             customerId,
                                         }: {
    customerId: string;
}) {
    const base = `customers/${customerId}`;

    const [profile, setProfile] = useState<Profile | null>(null);
    const [tab, setTab] = useState<Tab>("profile");

    const [page, setPage] = useState<Page<ReadRow> | null>(null);
    const [cursor, setCursor] = useState<number | null>(null);
    const [previous, setPrevious] = useState<Array<number | null>>([]);

    const [paymentStatus, setPaymentStatus] = useState("all");

    const [wallet, setWallet] = useState<Wallet | null>(null);

    const [reload, setReload] = useState(0);
    const [editorVersion, setEditorVersion] = useState(0);

    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [dirty, setDirty] = useState(false);

    const [pending, setPending] = useState<Pending | null>(null);

    const pendingRef = useRef<Pending | null>(null);
    const busyRef = useRef(false);

    const [error, setError] = useState<string | null>(null);
    const [sectionError, setSectionError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    useEffect(() => {
        let live = true;

        crm<Profile>(base)
            .then((result) => {
                if (live) setProfile(result);
            })
            .catch((caught) => {
                if (live) {
                    setProfile(null);
                    setError(
                        caught instanceof Error
                            ? caught.message
                            : "Ошибка карточки покупателя"
                    );
                }
            });

        return () => { live = false; };
    }, [base, reload]);

    const walletId = wallet?.id ?? null;

    useEffect(() => {
        if (tab === "profile") {
            setLoading(false);
            return;
        }

        let live = true;

        setPage(null);
        setLoading(true);
        setSectionError(null);

        const endpoint = tab === "wallets" && walletId !== null
            ? `${base}/wallets/${walletId}/ledger`
            : `${base}/${tab}`;

        const params = new URLSearchParams({
            limit: "25",
        });

        if (cursor !== null) {
            params.set("before_id", String(cursor));
        }

        if (tab === "orders" && paymentStatus !== "all") {
            params.set("payment_status", paymentStatus);
        }

        crm<Page<ReadRow>>(`${endpoint}?${params}`)
            .then((result) => {
                if (live) setPage(result);
            })
            .catch((caught) => {
                if (live) setSectionError(
                    caught instanceof Error
                        ? caught.message
                        : "Ошибка загрузки раздела"
                );
            })
            .finally(() => {
                if (live) setLoading(false);
            });

        return () => { live = false; };
    }, [
        base,
        tab,
        walletId,
        cursor,
        paymentStatus,
        reload,
    ]);

    useEffect(() => {
        if (!dirty && !pending) return;

        function beforeUnload(event: BeforeUnloadEvent) {
            event.preventDefault();
            event.returnValue = "";
        }

        function handleLink(event: MouseEvent) {
            if (!(event.target instanceof Element)) return;
            if (!event.target.closest("a[href]")) return;

            if (!window.confirm(
                "Есть несохранённые данные. Покинуть карточку?"
            )) {
                event.preventDefault();
                event.stopPropagation();
            }
        }

        window.addEventListener("beforeunload", beforeUnload);
        document.addEventListener("click", handleLink, true);

        return () => {
            window.removeEventListener("beforeunload", beforeUnload);
            document.removeEventListener("click", handleLink, true);
        };
    }, [dirty, pending]);

    function resetPagination() {
        setPage(null);
        setCursor(null);
        setPrevious([]);
    }

    function chooseTab(next: Tab) {
        if (busyRef.current || pendingRef.current || next === tab) return;

        if (dirty && !window.confirm(
            "Отменить несохранённые изменения?"
        )) return;

        setDirty(false);
        setTab(next);
        setWallet(null);
        setPaymentStatus("all");
        setSectionError(null);
        resetPagination();

        setEditorVersion((value) => value + 1);
    }

    function refresh() {
        setDirty(false);
        setWallet(null);
        resetPagination();
        setEditorVersion((value) => value + 1);
        setReload((value) => value + 1);
    }

    async function send(body: Pending) {
        if (busyRef.current) return;

        busyRef.current = true;
        setBusy(true);
        setError(null);
        setNotice(null);

        try {
            const result = await crm<WriteResult>(
                `${base}/write`,
                {
                    method: "POST",
                    body,
                }
            );

            pendingRef.current = null;
            setPending(null);

            setNotice(
                result.replayed
                    ? "Запись уже была сохранена."
                    : "Изменения успешно сохранены."
            );

            refresh();
        } catch (caught) {
            if (
                caught instanceof CrmError
                && [400, 401, 403, 404, 409, 422].includes(caught.status)
            ) {
                pendingRef.current = null;
                setPending(null);
            }

            setError(
                caught instanceof Error
                    ? caught.message
                    : "Не удалось сохранить данные"
            );
        } finally {
            busyRef.current = false;
            setBusy(false);
        }
    }

    function submit(command: WriteCommand) {
        if (
            !profile?.can_manage
            || busyRef.current
            || pendingRef.current
        ) return;

        const body: Pending = {
            ...command,
            operation_key: crypto.randomUUID(),
        };

        pendingRef.current = body;
        setPending(body);

        void send(body);
    }

    const locked = busy || pending !== null;
    const items = page?.items ?? [];

    if (!profile) {
        return (
            <div className="crm74-page">
                <Link href="/admin/customers">
                    ← Все покупатели
                </Link>

                <p className={error ? "crm65-error" : "crm65-muted"}>
                    {error ?? "Загружаем карточку покупателя..."}
                </p>
            </div>
        );
    }

    const customer = profile.customer;

    const tabs: Array<[Tab, string, boolean]> = [
        ["profile", "Профиль", true],
        ["orders", "Заказы", true],
        ["returns", "Возвраты", profile.can_view_returns],
        ["wallets", "Бонусы", true],
        ["history", "Взаимодействия", true],
        ["access", "Журнал доступа", profile.can_view_access],
    ];

    return (
        <div className="crm74-page">

            <div className="crm74-row-head">
                <Link
                    href="/admin/customers"
                    className="crm65-secondary"
                >
                    ← Все покупатели
                </Link>

                <button
                    type="button"
                    className="crm65-secondary"
                    disabled={locked}
                    onClick={() => {
                        if (dirty && !window.confirm(
                            "Отменить несохранённые изменения?"
                        )) return;

                        setError(null);
                        refresh();
                    }}
                >
                    ↻ Обновить
                </button>
            </div>

            <section className="crm65-panel crm74-panel">
                <div className="crm74-profile-head">
                    <div className="crm74-avatar large">
                        {customer.name.trim().charAt(0).toUpperCase() || "?"}
                    </div>

                    <div>
                        <h1>{customer.name}</h1>

                        <p className="crm65-muted">
                            Покупатель #{customer.id} · {customer.email}
                        </p>

                        <p className="crm65-muted">
                            Зарегистрирован: {when(customer.created_at)}
                        </p>
                    </div>

                    <span className={`crm74-segment ${customer.segment}`}>
                        {segmentLabels[customer.segment]}
                    </span>
                </div>

                {!profile.can_manage && (
                    <p className="crm65-muted">
                        Доступ только для просмотра.
                    </p>
                )}
            </section>

            <div className="crm74-metrics">
                <div className="crm74-metric">
                    <small>Всего заказов</small>
                    <strong>{customer.orders_count}</strong>
                </div>

                <div className="crm74-metric">
                    <small>Оплачено заказов</small>
                    <strong>{customer.paid_count}</strong>
                </div>

                <div className="crm74-metric">
                    <small>Последний оплаченный заказ</small>
                    <strong className="crm74-date-metric">
                        {when(customer.last_paid_order_at)}
                    </strong>
                </div>
            </div>

            {profile.statistics.currencies.map((item) => (
                <section
                    className="crm65-panel crm74-panel"
                    key={item.currency_code}
                >
                    <h2>Статистика покупок · {item.currency_code}</h2>

                    <div className="crm74-form-grid">
                        <div className="crm74-metric">
                            <small>Стоимость оплаченных товаров</small>
                            <strong>
                                {formatMoney(
                                    item.goods_total_minor,
                                    item.currency_code,
                                    item.minor_units
                                )}
                            </strong>
                        </div>

                        <div className="crm74-metric">
                            <small>Средний чек товаров</small>
                            <strong>
                                {formatMoney(
                                    item.average_goods_minor,
                                    item.currency_code,
                                    item.minor_units
                                )}
                            </strong>
                        </div>

                        <div className="crm74-metric">
                            <small>Оплаченных заказов с финансовым снимком</small>
                            <strong>{item.paid_orders}</strong>
                        </div>
                    </div>

                    <p className="crm65-muted">
                        Без доставки и без вычета возвратов. Не является чистой выручкой.
                    </p>
                </section>
            ))}

            {error && (
                <p className="crm65-error" role="alert">{error}</p>
            )}

            {notice && (
                <p className="crm74-success" role="status">{notice}</p>
            )}

            {pending && (
                <section className="crm65-panel crm74-panel">
                    <h2>
                        {busy
                            ? "Сохранение..."
                            : "Результат сохранения не подтверждён"}
                    </h2>

                    <p className="crm65-muted">
                        Повтор использует тот же UUID операции.
                    </p>

                    {!busy && (
                        <div className="crm74-actions">
                            <button
                                type="button"
                                className="crm65-primary"
                                onClick={() => void send(pending)}
                            >
                                Повторить сохранение
                            </button>

                            <button
                                type="button"
                                className="crm65-secondary"
                                onClick={() => {
                                    if (!window.confirm(
                                        "Запись могла сохраниться. Обновить данные и очистить редактор?"
                                    )) return;

                                    pendingRef.current = null;
                                    setPending(null);
                                    refresh();
                                }}
                            >
                                Проверить сохранение
                            </button>
                        </div>
                    )}
                </section>
            )}

            <div className="crm74-tabs">
                {tabs.filter(([, , visible]) => visible).map(([id, name]) => (
                    <button
                        type="button"
                        key={id}
                        className={tab === id ? "active" : ""}
                        disabled={locked}
                        onClick={() => chooseTab(id)}
                    >
                        {name}
                    </button>
                ))}
            </div>

            {tab === "profile" && (
                <>
                    <section className="crm65-panel crm74-panel">
                        <h2>Учётная запись</h2>

                        <div className="crm74-form-grid">
                            <div className="crm74-data">
                                <small>Имя пользователя</small>
                                <strong>{customer.name}</strong>
                            </div>

                            <div className="crm74-data">
                                <small>Email</small>
                                <strong>{customer.email}</strong>
                            </div>

                            <div className="crm74-data">
                                <small>Подтверждение email</small>
                                <strong>
                                    {customer.email_verified
                                        ? "Подтверждён"
                                        : "Не подтверждён"}
                                </strong>
                            </div>
                        </div>

                        <p className="crm65-muted">
                            CRM не изменяет пароль, email и данные входа покупателя.
                        </p>
                    </section>

                    <section className="crm65-panel crm74-panel">
                        <h2>Контактный профиль</h2>

                        <p className="crm65-muted">
                            Последнее изменение: {when(profile.contact.updated_at)}
                        </p>

                        {profile.can_manage ? (
                            <ContactForm74
                                key={`${editorVersion}-${profile.contact.contact_id ?? 0}`}
                                contact={profile.contact}
                                locked={locked}
                                submit={submit}
                                onDirty={setDirty}
                            />
                        ) : (
                            <div className="crm74-form-grid">
                                <div className="crm74-data">
                                    <small>Телефон</small>
                                    <strong>{profile.contact.phone ?? "—"}</strong>
                                </div>

                                <div className="crm74-data">
                                    <small>Город</small>
                                    <strong>{profile.contact.city ?? "—"}</strong>
                                </div>

                                <div className="crm74-data">
                                    <small>Адрес</small>
                                    <strong>{profile.contact.address ?? "—"}</strong>
                                </div>

                                <div className="crm74-data">
                                    <small>Способ связи</small>
                                    <strong>
                                        {channelLabels[profile.contact.preferred_channel] ?? "—"}
                                    </strong>
                                </div>
                            </div>
                        )}
                    </section>
                </>
            )}

            {tab === "history" && profile.can_manage && (
                <section className="crm65-panel crm74-panel">
                    <h2>Новое взаимодействие</h2>

                    <InteractionForm74
                        key={editorVersion}
                        locked={locked}
                        submit={submit}
                        onDirty={setDirty}
                    />
                </section>
            )}

            {tab !== "profile" && (
                <section className="crm65-panel crm74-panel">
                    <div className="crm74-row-head">
                        <h2>
                            {wallet && tab === "wallets"
                                ? `История бонусов · ${wallet.market_code}`
                                : tabs.find(([id]) => id === tab)?.[1]}
                        </h2>

                        {wallet && tab === "wallets" && (
                            <button
                                type="button"
                                className="crm65-secondary"
                                onClick={() => {
                                    setWallet(null);
                                    resetPagination();
                                }}
                            >
                                ← Кошельки
                            </button>
                        )}
                    </div>

                    {tab === "orders" && (
                        <label>
                            Статус оплаты
                            <select
                                value={paymentStatus}
                                onChange={(event) => {
                                    setPaymentStatus(event.target.value);
                                    resetPagination();
                                }}
                            >
                                <option value="all">Все заказы</option>
                                <option value="paid">Оплаченные</option>
                                <option value="pending">Ожидают оплаты</option>
                                <option value="review">На проверке</option>
                            </select>
                        </label>
                    )}

                    {sectionError && (
                        <p className="crm65-error">{sectionError}</p>
                    )}

                    {loading && (
                        <p className="crm65-muted">Загрузка...</p>
                    )}

                    {!loading && page?.items.length === 0 && (
                        <p className="crm65-muted">Записей пока нет.</p>
                    )}

                    {tab === "orders" && (
                        <div className="crm74-table-scroll">
                            <table className="crm74-table">
                                <thead>
                                <tr>
                                    <th>Заказ</th>
                                    <th>Дата</th>
                                    <th>Оплата</th>
                                    <th>Стоимость товаров</th>
                                    <th></th>
                                </tr>
                                </thead>

                                <tbody>
                                {(items as Order[]).map((item) => (
                                    <tr key={item.id}>
                                        <td>#{item.id}</td>
                                        <td>{when(item.created_at)}</td>
                                        <td>{statusLabel(item.payment_status)}</td>

                                        <td>
                                            {formatMoney(
                                                item.goods_payable_minor,
                                                item.currency_code,
                                                item.minor_units ?? 2
                                            )}
                                        </td>

                                        <td>
                                            <Link href={`/admin/orders/${item.order_key}`}>
                                                Открыть заказ
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {tab === "returns" && (
                        <div className="crm74-table-scroll">
                            <table className="crm74-table">
                                <thead>
                                <tr>
                                    <th>Возврат</th>
                                    <th>Причина</th>
                                    <th>Дата обращения</th>
                                    <th></th>
                                </tr>
                                </thead>

                                <tbody>
                                {(items as ReturnCase[]).map((item) => (
                                    <tr key={item.id}>
                                        <td>#{item.id}</td>
                                        <td>{statusLabel(item.reason_code)}</td>
                                        <td>{when(item.created_at)}</td>

                                        <td>
                                            <Link href={`/admin/orders/${item.order_key}`}>
                                                Детали возврата
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {tab === "wallets" && wallet === null && (
                        <div className="crm74-wallets">
                            {(items as Wallet[]).map((item) => (
                                <div className="crm74-wallet" key={item.id}>
                                    <div className="crm74-row-head">
                                        <strong>
                                            {item.market_name} · {item.market_code}
                                        </strong>

                                        <small>
                                            {item.status === "active"
                                                ? "Активный"
                                                : item.status === "frozen"
                                                    ? "Заморожен"
                                                    : "Закрыт"}
                                        </small>
                                    </div>

                                    {item.balance ? (
                                        <>
                                            <div className="crm74-balance">
                                                <small>Можно списать</small>
                                                <strong>
                                                    {item.balance.spendable_units}
                                                </strong>
                                            </div>

                                            <div className="crm74-wallet-grid">
                                                <div>
                                                    <small>Ожидают активации</small>
                                                    <strong>{item.balance.pending_units}</strong>
                                                </div>

                                                <div>
                                                    <small>Баланс по журналу</small>
                                                    <strong>{item.balance.available_units}</strong>
                                                </div>

                                                <div>
                                                    <small>В резерве</small>
                                                    <strong>{item.balance.reserved_units}</strong>
                                                </div>

                                                <div>
                                                    <small>Задолженность</small>
                                                    <strong>{item.balance.debt_units}</strong>
                                                </div>
                                            </div>

                                            <small className="crm65-muted">
                                                Расчёт: {when(item.balance.calculated_at)}
                                            </small>
                                        </>
                                    ) : (
                                        <p className="crm65-muted">
                                            Рынок неактивен. Текущий расчёт недоступен.
                                        </p>
                                    )}

                                    <button
                                        type="button"
                                        className="crm65-secondary"
                                        onClick={() => {
                                            setWallet(item);
                                            resetPagination();
                                        }}
                                    >
                                        История бонусов
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}

                    {tab === "wallets" && wallet !== null && (
                        <div className="crm74-table-scroll">
                            <table className="crm74-table">
                                <thead>
                                <tr>
                                    <th>Операция</th>
                                    <th>Ожидание</th>
                                    <th>Доступно</th>
                                    <th>Резерв</th>
                                    <th>Долг</th>
                                    <th>Дата</th>
                                </tr>
                                </thead>

                                <tbody>
                                {(items as LedgerEntry[]).map((item) => (
                                    <tr key={item.id}>
                                        <td>
                                            <strong>{statusLabel(item.entry_type)}</strong>
                                            <small>{item.origin}</small>
                                        </td>

                                        <td>{signedUnits(item.pending_delta)}</td>
                                        <td>{signedUnits(item.available_delta)}</td>
                                        <td>{signedUnits(item.reserved_delta)}</td>
                                        <td>{signedUnits(item.debt_delta)}</td>
                                        <td>{when(item.occurred_at)}</td>
                                    </tr>
                                ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {(tab === "history" || tab === "access") && (
                        <div className="crm74-timeline">
                            {(items as CustomerEvent[]).map((item) => (
                                <article className="crm74-event" key={item.id}>
                                    <div className="crm74-row-head">
                                        <strong>
                                            {eventLabels[item.kind] ?? item.kind}
                                        </strong>

                                        <small>{when(item.created_at)}</small>
                                    </div>

                                    <small className="crm65-muted">
                                        Сотрудник:{" "}
                                        {item.actor_name ?? `#${item.actor_id}`}
                                    </small>

                                    {item.payload.body && (
                                        <p className="crm74-event-body">
                                            {item.payload.body}
                                        </p>
                                    )}

                                    {item.payload.section && (
                                        <p className="crm65-muted">
                                            Раздел:{" "}
                                            {sectionLabels[item.payload.section]
                                                ?? item.payload.section}
                                        </p>
                                    )}

                                    {item.payload.follow_up_at && (
                                        <p className="crm74-followup">
                                            Следующий контакт:{" "}
                                            {when(item.payload.follow_up_at)}
                                        </p>
                                    )}
                                </article>
                            ))}
                        </div>
                    )}

                    <div className="crm74-pagination">
                        <button
                            type="button"
                            disabled={loading || previous.length === 0}
                            onClick={() => {
                                setCursor(previous[previous.length - 1]);
                                setPrevious((old) => old.slice(0, -1));
                            }}
                        >
                            Назад
                        </button>

                        <button
                            type="button"
                            disabled={loading || !page?.next_before_id}
                            onClick={() => {
                                if (!page?.next_before_id) return;

                                setPrevious((old) => [...old, cursor]);
                                setCursor(page.next_before_id);
                            }}
                        >
                            Далее
                        </button>
                    </div>
                </section>
            )}
        </div>
    );
}