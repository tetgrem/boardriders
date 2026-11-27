"use client";

import { useEffect, useState, type FormEvent } from "react";
import { crm, when } from "@/lib/crm65/client";
import LiveSearch from "@/components/crm65/LiveSearch";

import {
    formText,
    fromMinor,
    optionalInteger,
    optionalMinor,
    toMinor,
    utcDate,
    type Page,
    type PriceList,
    type Promotion,
    type PromotionDetail,
    type SubmitCommand,
} from "./types";

export default function Promotions73({
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
    const [query, setQuery] = useState("");
    const [search, setSearch] = useState("");
    const [cursor, setCursor] = useState<number | null>(null);

    const [data, setData] = useState<Page<Promotion> | null>(null);
    const [detail, setDetail] = useState<PromotionDetail | null>(null);
    const [creating, setCreating] = useState(false);
    const [dirty, setDirty] = useState(false);

    const [listId, setListId] = useState(lists[0]?.id ?? 0);
    const [type, setType] = useState<"percent" | "fixed">("percent");

    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const list = lists.find((item) => item.id === listId);

    useEffect(() => {
        onDirty(dirty);
    }, [dirty, onDirty]);

    useEffect(() => {
        let live = true;

        setLoading(true);

        const params = new URLSearchParams({ limit: "30" });

        if (search) params.set("q", search);
        if (cursor !== null) params.set("before_id", String(cursor));

        crm<Page<Promotion>>(`pricing/promotions?${params}`)
            .then((response) => {
                if (live) setData(response);
            })
            .catch((caught) => {
                if (live) setError(
                    caught instanceof Error
                        ? caught.message : "Ошибка акций"
                );
            })
            .finally(() => {
                if (live) setLoading(false);
            });

        return () => {
            live = false;
        };
    }, [search, cursor]);

    function discardAllowed(): boolean {
        return !dirty || window.confirm(
            "Отменить несохранённые данные акции?"
        );
    }

    async function open(id: number) {
        if (locked || !discardAllowed()) return;

        setDirty(false);
        setError(null);
        setLoading(true);

        try {
            setDetail(await crm<PromotionDetail>(
                `pricing/promotions/${id}`
            ));
            setCreating(false);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message : "Ошибка акции"
            );
        } finally {
            setLoading(false);
        }
    }

    function create(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);

        if (!canManage || locked || !list) return;

        const form = new FormData(event.currentTarget);

        try {
            const value = toMinor(
                formText(form, "value"),
                type === "percent" ? 2 : list.minor_units
            );

            if (value < 1 || (type === "percent" && value > 10000)) {
                throw new Error("Некорректный размер скидки.");
            }

            const starts = utcDate(formText(form, "starts"));
            const ends = utcDate(formText(form, "ends"));

            if (starts && ends && Date.parse(starts) >= Date.parse(ends)) {
                throw new Error("Дата окончания должна быть позже начала.");
            }

            submit({
                action: "promotion_create",
                price_list_id: list.id,
                name: formText(form, "name"),
                type,
                value,

                min_subtotal_minor:
                    optionalMinor(form, "minimum", list.minor_units) ?? 0,

                max_discount_minor:
                    optionalMinor(form, "maximum", list.minor_units),

                usage_limit: optionalInteger(form, "usage_limit"),
                per_customer_limit: optionalInteger(form, "customer_limit"),
                code_usage_limit: optionalInteger(form, "code_limit"),

                code: formText(form, "code").toUpperCase() || null,
                starts_at: starts,
                ends_at: ends,
            });

        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message : "Некорректные параметры акции"
            );
        }
    }

    function addCode(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!detail || !canManage || locked) return;

        const form = new FormData(event.currentTarget);

        try {
            submit({
                action: "code_create",
                promotion_id: detail.promotion.id,
                expected_revision: detail.promotion.admin_revision,
                code: formText(form, "code").toUpperCase() || null,
                usage_limit: optionalInteger(form, "limit"),
            });
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message : "Ошибка промокода"
            );
        }
    }

    const promotion = detail?.promotion;

    return (
        <div className="pricing73-stack">
            <section className="crm65-panel pricing73-panel">
                <div className="pricing73-row-head">
                    <h2>Акции и промокоды</h2>

                    {canManage && (
                        <button
                            type="button"
                            className="crm65-primary"
                            disabled={locked}
                            onClick={() => {
                                if (!discardAllowed()) return;
                                setCreating(true);
                                setDetail(null);
                                setDirty(false);
                            }}
                        >
                            + Создать акцию
                        </button>
                    )}
                </div>

                <form
                    className="pricing73-actions"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setSearch(query.trim());
                        setCursor(null);
                    }}
                >
                    <LiveSearch
                        value={query}
                        applied={search}
                        onChange={setQuery}
                        disabled={locked}
                        placeholder="Название акции или промокод"
                        onSearch={(value) => {
                            setSearch(value);
                            setCursor(null);
                        }}
                    />
                </form>

                {loading && <p>Загрузка...</p>}

                <div className="pricing73-table-scroll">
                    <table className="pricing73-table">
                        <thead>
                        <tr>
                            <th>Акция</th>
                            <th>Скидка</th>
                            <th>Использовано</th>
                            <th>Статус</th>
                            <th></th>
                        </tr>
                        </thead>

                        <tbody>
                        {data?.items.map((item) => (
                            <tr key={item.id}>
                                <td>
                                    <strong>{item.name}</strong>
                                    <small>{item.price_list_code}</small>
                                </td>

                                <td>
                                    {item.type === "percent"
                                        ? `${fromMinor(item.value, 2)}%`
                                        : `${
                                            fromMinor(
                                                item.value,
                                                lists.find(
                                                    (entry) =>
                                                        entry.id === item.price_list_id
                                                )?.minor_units ?? 2
                                            )
                                        } ${item.currency_code}`}
                                </td>

                                <td>
                                    {item.used_count ?? 0}
                                    {" / "}{item.usage_limit ?? "∞"}
                                </td>

                                <td>
                                    {item.is_active ? "Включена" : "Выключена"}
                                </td>

                                <td>
                                    <button
                                        type="button"
                                        className="crm65-secondary"
                                        disabled={locked || loading}
                                        onClick={() => void open(item.id)}
                                    >
                                        Открыть
                                    </button>
                                </td>
                            </tr>
                        ))}
                        </tbody>
                    </table>
                </div>

                <div className="pricing73-actions">
                    <button
                        type="button"
                        disabled={cursor === null || loading}
                        onClick={() => setCursor(null)}
                    >
                        В начало
                    </button>

                    <button
                        type="button"
                        disabled={!data?.next_before_id || loading}
                        onClick={() => setCursor(data?.next_before_id ?? null)}
                    >
                        Далее
                    </button>
                </div>
            </section>

            {creating && canManage && (
                <section className="crm65-panel pricing73-panel">
                    <h2>Новая акция</h2>

                    <form
                        className="pricing73-stack"
                        onSubmit={create}
                        onChange={() => setDirty(true)}
                    >
                        <fieldset className="pricing73-fieldset" disabled={locked}>
                            <div className="pricing73-grid">
                                <label>
                                    Название
                                    <input name="name" required maxLength={160}/>
                                </label>

                                <label>
                                    Прайс-лист
                                    <select
                                        value={listId}
                                        onChange={(event) =>
                                            setListId(Number(event.target.value))
                                        }
                                    >
                                        {lists.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <label>
                                    Тип скидки
                                    <select
                                        value={type}
                                        onChange={(event) =>
                                            setType(event.target.value as typeof type)
                                        }
                                    >
                                        <option value="percent">Процент</option>
                                        <option value="fixed">Фиксированная сумма</option>
                                    </select>
                                </label>

                                <label>
                                    {type === "percent"
                                        ? "Скидка, %"
                                        : `Скидка, ${list?.currency_code ?? ""}`}
                                    <input name="value" required inputMode="decimal"/>
                                </label>

                                <label>
                                    Минимальная сумма корзины
                                    <input name="minimum" inputMode="decimal"/>
                                </label>

                                <label>
                                    Максимальный размер скидки
                                    <input name="maximum" inputMode="decimal"/>
                                </label>

                                <label>
                                    Общий лимит использований
                                    <input name="usage_limit" type="number" min={1}/>
                                </label>

                                <label>
                                    Лимит на покупателя
                                    <input name="customer_limit" type="number" min={1}/>
                                </label>

                                <label>
                                    Лимит первого промокода
                                    <input name="code_limit" type="number" min={1}/>
                                </label>

                                <label>
                                    Промокод
                                    <input
                                        name="code"
                                        maxLength={40}
                                        placeholder="Автоматически, если пусто"
                                    />
                                </label>

                                <label>
                                    Начало
                                    <input name="starts" type="datetime-local"/>
                                </label>

                                <label>
                                    Окончание
                                    <input name="ends" type="datetime-local"/>
                                </label>
                            </div>

                            <p className="crm65-muted">
                                Пустой лимит означает отсутствие ограничения.
                                Даты вводятся в локальном часовом поясе браузера.
                                Акция создаётся выключенной.
                            </p>
                        </fieldset>

                        <button className="crm65-primary" disabled={locked}>
                            Создать акцию и промокод
                        </button>
                    </form>
                </section>
            )}

            {promotion && detail && (
                <section className="crm65-panel pricing73-panel">
                    <div className="pricing73-row-head">
                        <h2>{promotion.name}</h2>

                        {canManage && (
                            <button
                                type="button"
                                className="crm65-primary"
                                disabled={locked || dirty}
                                onClick={() => {
                                    if (!window.confirm(
                                        promotion.is_active
                                            ? "Отключить акцию?"
                                            : "Включить акцию?"
                                    )) return;

                                    submit({
                                        action: "promotion_toggle",
                                        promotion_id: promotion.id,
                                        expected_revision: promotion.admin_revision,
                                        is_active: !promotion.is_active,
                                    });
                                }}
                            >
                                {promotion.is_active ? "Отключить" : "Включить"}
                            </button>
                        )}
                    </div>

                    <p className="crm65-muted">
                        #{promotion.id}
                        {" · "}Ревизия {promotion.admin_revision}
                        {" · "}Начало: {when(promotion.starts_at)}
                        {" · "}Конец: {when(promotion.ends_at)}
                    </p>

                    <div className="pricing73-table-scroll">
                        <table className="pricing73-table">
                            <thead>
                            <tr>
                                <th>Промокод</th>
                                <th>Использовано</th>
                                <th>В резерве</th>
                                <th>Статус</th>
                                {canManage && <th></th>}
                            </tr>
                            </thead>

                            <tbody>
                            {detail.codes.map((code) => (
                                <tr key={code.id}>
                                    <td><strong>{code.code}</strong></td>

                                    <td>
                                        {code.used_count}
                                        {" / "}{code.usage_limit ?? "∞"}
                                    </td>

                                    <td>{code.pending_count}</td>

                                    <td>
                                        {code.is_active
                                            ? "Включён" : "Отключён"}
                                    </td>

                                    {canManage && (
                                        <td>
                                            <button
                                                type="button"
                                                className="crm65-secondary"
                                                disabled={locked || dirty}
                                                onClick={() => {
                                                    if (!window.confirm(
                                                        "Изменить статус промокода?"
                                                    )) return;

                                                    submit({
                                                        action: "code_toggle",
                                                        promotion_id: promotion.id,
                                                        expected_revision:
                                                        promotion.admin_revision,
                                                        code_id: code.id,
                                                        is_active: !code.is_active,
                                                    });
                                                }}
                                            >
                                                {code.is_active
                                                    ? "Отключить" : "Включить"}
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                            </tbody>
                        </table>
                    </div>

                    {canManage && (
                        <form
                            className="pricing73-grid"
                            onSubmit={addCode}
                            onChange={() => setDirty(true)}
                        >
                            <label>
                                Новый промокод
                                <input
                                    name="code"
                                    maxLength={40}
                                    placeholder="Автоматическая генерация"
                                    disabled={locked}
                                />
                            </label>

                            <label>
                                Лимит использований
                                <input
                                    name="limit"
                                    type="number"
                                    min={1}
                                    disabled={locked}
                                />
                            </label>

                            <button className="crm65-primary" disabled={locked}>
                                Добавить промокод
                            </button>

                            <button
                                type="reset"
                                className="crm65-secondary"
                                disabled={locked}
                                onClick={() => setDirty(false)}
                            >
                                Очистить
                            </button>
                        </form>
                    )}
                </section>
            )}

            {error && <p className="crm65-error" role="alert">{error}</p>}
        </div>
    );
}