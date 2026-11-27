"use client";

import Link from "next/link";
import LiveSearch from "@/components/crm65/LiveSearch";
import { useEffect, useState } from "react";
import { crm, when } from "@/lib/crm65/client";

import {
    segmentLabels,
    type Customer,
    type Overview,
    type Page,
    type Segment,
} from "./types";

export default function CustomersClient74() {
    const [overview, setOverview] = useState<Overview | null>(null);

    const [query, setQuery] = useState("");
    const [search, setSearch] = useState("");

    const [segment, setSegment] = useState<Segment | "all">("all");

    const [cursor, setCursor] = useState<number | null>(null);
    const [previous, setPrevious] = useState<Array<number | null>>([]);

    const [data, setData] = useState<Page<Customer> | null>(null);

    const [reload, setReload] = useState(0);
    const [loading, setLoading] = useState(true);

    const [error, setError] = useState<string | null>(null);
    const [overviewError, setOverviewError] = useState<string | null>(null);

    useEffect(() => {
        let live = true;

        setOverviewError(null);

        crm<Overview>("customers/overview")
            .then((result) => {
                if (live) setOverview(result);
            })
            .catch((caught) => {
                if (live) {
                    setOverviewError(
                        caught instanceof Error
                            ? caught.message
                            : "Ошибка статистики покупателей"
                    );
                }
            });

        return () => { live = false; };
    }, [reload]);

    useEffect(() => {
        let live = true;

        setLoading(true);
        setData(null);
        setError(null);

        const params = new URLSearchParams({
            limit: "25",
            segment,
        });

        if (search) params.set("q", search);

        if (cursor !== null) {
            params.set("before_id", String(cursor));
        }

        crm<Page<Customer>>(`customers?${params}`)
            .then((result) => {
                if (live) setData(result);
            })
            .catch((caught) => {
                if (live) {
                    setError(
                        caught instanceof Error
                            ? caught.message
                            : "Ошибка загрузки покупателей"
                    );
                }
            })
            .finally(() => {
                if (live) setLoading(false);
            });

        return () => { live = false; };
    }, [search, segment, cursor, reload]);

    function chooseSegment(next: Segment | "all") {
        if (next === segment && cursor === null) return;

        setSegment(next);
        setCursor(null);
        setPrevious([]);
    }

    return (
        <div className="crm74-page">
            <div className="crm65-pagehead">
                <div>
                    <p className="crm65-overline">
                        CRM / CUSTOMERS
                    </p>

                    <h1>Покупатели</h1>

                    <p className="crm65-muted">
                        Клиентская база, история покупок и взаимодействий.
                    </p>
                </div>

                <button
                    type="button"
                    className="crm65-secondary"
                    onClick={() => setReload((value) => value + 1)}
                >
                    ↻ Обновить
                </button>
            </div>

            {overviewError && (
                <p className="crm65-error">{overviewError}</p>
            )}

            {overview && (
                <>
                    <div className="crm74-metrics">
                        <button
                            type="button"
                            className={
                                `crm74-metric ${segment === "all" ? "active" : ""}`
                            }
                            onClick={() => chooseSegment("all")}
                        >
                            <small>Всего покупателей</small>
                            <strong>{overview.customers_total}</strong>
                        </button>

                        {(Object.keys(segmentLabels) as Segment[]).map((item) => (
                            <button
                                type="button"
                                key={item}
                                className={
                                    `crm74-metric ${segment === item ? "active" : ""}`
                                }
                                onClick={() => chooseSegment(item)}
                            >
                                <small>{segmentLabels[item]}</small>
                                <strong>{overview.segments[item]}</strong>
                            </button>
                        ))}
                    </div>

                    <p className="crm65-muted">
                        VIP — от {overview.vip_paid_orders} оплаченных заказов.
                        Неактивные — без новых оплаченных заказов более{" "}
                        {overview.inactive_days} дней.
                    </p>

                    <div className="crm74-info">
                        Гостевых заказов без привязки к аккаунту:{" "}
                        <strong>{overview.guest_orders_count}</strong>.
                        Они не включены в клиентские профили.
                    </div>
                </>
            )}

            <section className="crm65-panel crm74-panel">
                <form
                    className="crm74-filters"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setSearch(query.trim());
                        setCursor(null);
                        setPrevious([]);
                        setReload((value) => value + 1);
                    }}
                >
                    <label>
                        Поиск покупателя
                        <LiveSearch
                            value={query}
                            applied={search}
                            onChange={setQuery}
                            placeholder="Имя, email или ID"
                            onSearch={(value) => {
                                setSearch(value);
                                setCursor(null);
                                setPrevious([]);
                            }}
                        />
                    </label>

                    <label>
                        Сегмент
                        <select
                            value={segment}
                            onChange={(event) => chooseSegment(
                                event.target.value as Segment | "all"
                            )}
                        >
                            <option value="all">Все покупатели</option>

                            {Object.entries(segmentLabels).map(([id, label]) => (
                                <option key={id} value={id}>{label}</option>
                            ))}
                        </select>
                    </label>
                    
                </form>

                {error && (
                    <p className="crm65-error" role="alert">{error}</p>
                )}

                {loading && (
                    <p className="crm65-muted">Загрузка покупателей...</p>
                )}

                {!loading && data?.items.length === 0 && (
                    <p className="crm65-muted">Покупатели не найдены.</p>
                )}

                <div className="crm74-table-scroll">
                    <table className="crm74-table">
                        <thead>
                        <tr>
                            <th>Покупатель</th>
                            <th>Сегмент</th>
                            <th>Заказов</th>
                            <th>Оплачено</th>
                            <th>Последний оплаченный заказ</th>
                            <th></th>
                        </tr>
                        </thead>

                        <tbody>
                        {data?.items.map((item) => (
                            <tr key={item.id}>
                                <td>
                                    <div className="crm74-person">
                                        <div className="crm74-avatar">
                                            {item.name.trim().charAt(0).toUpperCase() || "?"}
                                        </div>

                                        <div>
                                            <strong>{item.name}</strong>
                                            <small>{item.email}</small>
                                            <small>
                                                #{item.id}
                                                {" · "}
                                                {item.email_verified
                                                    ? "Email подтверждён"
                                                    : "Email не подтверждён"}
                                            </small>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                        <span className={
                                            `crm74-segment ${item.segment}`
                                        }>
                                            {segmentLabels[item.segment]}
                                        </span>
                                </td>

                                <td>{item.orders_count}</td>
                                <td>{item.paid_count}</td>
                                <td>{when(item.last_paid_order_at)}</td>

                                <td>
                                    <Link
                                        className="crm65-secondary"
                                        href={`/admin/customers/${item.id}`}
                                    >
                                        Открыть
                                    </Link>
                                </td>
                            </tr>
                        ))}
                        </tbody>
                    </table>
                </div>

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
                        disabled={loading || !data?.next_before_id}
                        onClick={() => {
                            if (!data?.next_before_id) return;

                            setPrevious((old) => [...old, cursor]);
                            setCursor(data.next_before_id);
                        }}
                    >
                        Далее
                    </button>
                </div>
            </section>
        </div>
    );
}