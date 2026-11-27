"use client";

import { useEffect, useState, type FormEvent } from "react";
import { crm } from "@/lib/crm65/client";
import LiveSearch from "@/components/crm65/LiveSearch";

import {
    fromMinor,
    toMinor,
    type Page,
    type PriceList,
    type PriceRow,
    type SubmitCommand,
} from "./types";

type Draft = {
    row: PriceRow;
    price: string;
    compare: string;
};

export default function Prices73({
                                     lists,
                                     canManage,
                                     locked,
                                     submit,
                                     onDirty,
                                 }: {
    lists: PriceList[];
    canManage: boolean;
    locked: boolean;
    submit: SubmitCommand;
    onDirty: (value: boolean) => void;
}) {
    const [listId, setListId] = useState(lists[0]?.id ?? 0);

    const list = lists.find((item) => item.id === listId);
    const digits = list?.minor_units ?? 2;

    const [query, setQuery] = useState("");
    const [search, setSearch] = useState("");
    const [missing, setMissing] = useState(false);

    const [cursor, setCursor] = useState<number | null>(null);
    const [data, setData] = useState<Page<PriceRow> | null>(null);

    const [drafts, setDrafts] = useState<Record<number, Draft>>({});
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [refresh, setRefresh] = useState(0);

    const selected = Object.values(drafts);

    useEffect(() => {
        onDirty(Object.keys(drafts).length > 0);
    }, [drafts, onDirty]);

    useEffect(() => {
        if (!listId) return;

        let live = true;

        setLoading(true);
        setError(null);
        setData(null);

        const params = new URLSearchParams({
            price_list_id: String(listId),
            price_status: missing ? "missing" : "all",
            limit: "30",
        });

        if (search) params.set("q", search);

        if (cursor !== null) {
            params.set("before_id", String(cursor));
        }

        crm<Page<PriceRow>>(`pricing/prices?${params}`)
            .then((response) => {
                if (live) setData(response);
            })
            .catch((caught) => {
                if (live) setError(
                    caught instanceof Error
                        ? caught.message : "Ошибка загрузки цен"
                );
            })
            .finally(() => {
                if (live) setLoading(false);
            });

        return () => {
            live = false;
        };
    }, [listId, search, missing, cursor, refresh]);

    function add(row: PriceRow) {
        if (selected.length >= 100) {
            setError("За один раз можно изменить до 100 SKU.");
            return;
        }

        setDrafts((old) => {
            if (old[row.id]) return old;

            return {
                ...old,
                [row.id]: {
                    row,
                    price: fromMinor(row.price_minor, digits),
                    compare: fromMinor(row.compare_at_minor, digits),
                },
            };
        });
    }

    function edit(
        id: number,
        field: "price" | "compare",
        value: string
    ) {
        setDrafts((old) => ({
            ...old,
            [id]: {
                ...old[id],
                [field]: value,
            },
        }));
    }

    function remove(id: number) {
        setDrafts((old) => {
            const next = { ...old };
            delete next[id];
            return next;
        });
    }

    function changeList(next: number) {
        if (selected.length && !window.confirm(
            "При смене прайс-листа несохранённые цены будут очищены."
        )) return;

        setDrafts({});
        setListId(next);
        setCursor(null);
    }

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);

        if (locked || !canManage) return;

        try {
            const lines = selected.map((draft) => {
                const price = toMinor(draft.price, digits);

                const compare = draft.compare.trim() === ""
                    ? null : toMinor(draft.compare, digits);

                if (compare !== null && compare <= price) {
                    throw new Error(
                        `${draft.row.sku}: старая цена должна быть выше новой.`
                    );
                }

                return {
                    variant_id: draft.row.id,
                    price_minor: price,
                    compare_at_minor: compare,
                    expected_revision: draft.row.revision,
                };
            }).filter((line) => {
                const old = drafts[line.variant_id].row;

                return old.price_minor === null
                    || Number(old.price_minor) !== line.price_minor
                    || (
                        old.compare_at_minor === null
                            ? null : Number(old.compare_at_minor)
                    ) !== line.compare_at_minor;
            });

            if (!lines.length) {
                setError("Нет изменённых цен.");
                return;
            }

            if (!window.confirm(
                `Сохранить цены для ${lines.length} SKU?`
            )) return;

            submit({
                action: "prices_save",
                price_list_id: listId,
                lines,
            });

        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message : "Ошибка цены"
            );
        }
    }

    return (
        <div className="pricing73-stack">
            <section className="crm65-panel pricing73-panel">
                <h2>Розничные цены</h2>

                <form
                    className="pricing73-grid"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setSearch(query.trim());
                        setCursor(null);
                        setRefresh((value) => value + 1);
                    }}
                >
                    <label>
                        Прайс-лист
                        <select
                            value={listId}
                            disabled={locked}
                            onChange={(event) =>
                                changeList(Number(event.target.value))
                            }
                        >
                            {lists.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name} · {item.currency_code}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label>
                        Поиск
                        <LiveSearch
                            value={query}
                            applied={search}
                            onChange={setQuery}
                            disabled={locked}
                            placeholder="Название, SKU, штрихкод"
                            onSearch={(value) => {
                                setSearch(value);
                                setCursor(null);
                            }}
                        />
                    </label>

                    <label className="pricing73-check">
                        <input
                            type="checkbox"
                            checked={missing}
                            onChange={(event) => {
                                setMissing(event.target.checked);
                                setCursor(null);
                            }}
                        />
                        Только без цены
                    </label>
                </form>

                {loading && <p>Загрузка...</p>}

                {!loading && data?.items.length === 0 && (
                    <p className="crm65-muted">Товары не найдены.</p>
                )}

                <div className="pricing73-table-scroll">
                    <table className="pricing73-table">
                        <thead>
                        <tr>
                            <th>Товар / SKU</th>
                            <th>Цена</th>
                            <th>Старая цена</th>
                            {canManage && <th></th>}
                        </tr>
                        </thead>

                        <tbody>
                        {data?.items.map((row) => (
                            <tr key={row.id}>
                                <td>
                                    <strong>
                                        {row.product_name ?? `Товар #${row.product_id}`}
                                    </strong>
                                    <small>{row.sku}</small>
                                </td>

                                <td className={
                                    row.compare_at_minor !== null
                                        ? "pricing73-current-price"
                                        : ""
                                }>
                                    {row.price_minor === null
                                        ? "Не назначена"
                                        : fromMinor(row.price_minor, digits)}
                                </td>

                                <td>
                                    {row.compare_at_minor !== null ? (
                                        <span className="pricing73-old-price">
                                            {fromMinor(row.compare_at_minor, digits)}
                                        </span>
                                    ) : "—"}
                                </td>

                                {canManage && (
                                    <td>
                                        <button
                                            type="button"
                                            className="crm65-secondary"
                                            disabled={locked || Boolean(drafts[row.id])}
                                            onClick={() => add(row)}
                                        >
                                            {drafts[row.id] ? "Выбран" : "Изменить"}
                                        </button>
                                    </td>
                                )}
                            </tr>
                        ))}
                        </tbody>
                    </table>
                </div>

                <div className="pricing73-actions">
                    <button
                        type="button"
                        disabled={loading || cursor === null}
                        onClick={() => setCursor(null)}
                    >
                        В начало
                    </button>

                    <button
                        type="button"
                        disabled={loading || !data?.next_before_id}
                        onClick={() =>
                            setCursor(data?.next_before_id ?? null)
                        }
                    >
                        Далее
                    </button>
                </div>
            </section>

            {canManage && selected.length > 0 && (
                <form className="pricing73-stack" onSubmit={save}>
                    <section className="crm65-panel pricing73-panel">
                        <h2>
                            Изменение цен · {selected.length}/100
                        </h2>

                        <p className="crm65-muted">
                            {list?.currency_code}. Выбранные товары сохраняются
                            при поиске и переходе между страницами.
                        </p>

                        {selected.map((draft) => (
                            <div
                                className="pricing73-price-edit"
                                key={draft.row.id}
                            >
                                <div>
                                    <strong>{draft.row.sku}</strong>
                                    <small>
                                        {draft.row.product_name ?? "Товар"}
                                    </small>
                                </div>

                                <label>
                                    Новая цена
                                    <input
                                        required
                                        inputMode="decimal"
                                        value={draft.price}
                                        disabled={locked}
                                        onChange={(event) => edit(
                                            draft.row.id, "price", event.target.value
                                        )}
                                    />
                                </label>

                                <label>
                                    Старая цена
                                    <input
                                        inputMode="decimal"
                                        value={draft.compare}
                                        disabled={locked}
                                        placeholder="Без скидки"
                                        onChange={(event) => edit(
                                            draft.row.id, "compare", event.target.value
                                        )}
                                    />
                                </label>

                                <button
                                    type="button"
                                    className="pricing73-danger"
                                    disabled={locked}
                                    onClick={() => remove(draft.row.id)}
                                >
                                    Убрать
                                </button>
                            </div>
                        ))}
                    </section>

                    <div className="pricing73-savebar">
                        <strong>{selected.length} выбранных SKU</strong>

                        <button
                            type="button"
                            className="crm65-secondary"
                            disabled={locked}
                            onClick={() => {
                                if (window.confirm("Отменить изменения цен?")) {
                                    setDrafts({});
                                }
                            }}
                        >
                            Отменить
                        </button>

                        <button className="crm65-primary" disabled={locked}>
                            Сохранить все цены
                        </button>
                    </div>
                </form>
            )}

            {error && <p className="crm65-error" role="alert">{error}</p>}
        </div>
    );
}