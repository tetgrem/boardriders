"use client";

import { useState, type FormEvent } from "react";
import { crm } from "@/lib/crm65/client";

import CampaignScope731 from "./CampaignScope731";

import {
    formText,
    fromMinor,
    optionalInteger,
    optionalMinor,
    toMinor,
    utcDate,
    type PriceList,
    type ScopeOption,
    type ScopeType,
    type SubmitCommand,
} from "./types";

type Terms = {
    price_list_id: number;
    name: string;
    badge: string;
    discount_type: "percent" | "fixed";
    discount_value: number;
    max_discount_minor: number | null;
    priority: number;
    allow_promo: boolean;
    allow_loyalty: boolean;
    scope_type: ScopeType;
    scope_ids: number[];
    excluded_variant_ids: number[];
    max_stock: number | null;
    unsold_days: number | null;
    starts_at: string;
    ends_at: string;
};

type Preview = {
    count: number;
    currency_code: string;
    minor_units: number;
    preview_token: string;
    sample: Array<{
        id: number;
        sku: string;
        product_name: string | null;
        brand_name: string | null;
        available_qty: number;
        base_price_minor: number;
        campaign_price_minor: number;
    }>;
};

function localDate(date: Date): string {
    const shifted = new Date(
        date.getTime() - date.getTimezoneOffset() * 60000
    );

    return shifted.toISOString().slice(0, 16);
}

export default function CampaignEditor731({
                                              lists,
                                              locked,
                                              submit,
                                              onDirty,
                                              onClose,
                                          }: {
    lists: PriceList[];
    locked: boolean;
    submit: SubmitCommand;
    onDirty: (value: boolean) => void;
    onClose: () => void;
}) {
    const [listId, setListId] = useState(lists[0]?.id ?? 0);

    const [scope, setScope] = useState<ScopeType>("all");
    const [selected, setSelected] = useState<ScopeOption[]>([]);
    const [excluded, setExcluded] = useState<ScopeOption[]>([]);

    const [type, setType] = useState<"percent" | "fixed">("percent");

    const [stockFilter, setStockFilter] = useState(false);
    const [oldFilter, setOldFilter] = useState(false);

    const [preview, setPreview] = useState<{
        terms: Terms;
        result: Preview;
    } | null>(null);

    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [initialStart] = useState(() => localDate(new Date()));

    const [initialEnd] = useState(() => localDate(
        new Date(Date.now() + 7 * 86400000)
    ));

    const list = lists.find((item) => item.id === listId);
    const disabled = locked || loading;

    function invalidate() {
        setPreview(null);
        setError(null);
        onDirty(true);
    }

    async function calculate(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);

        if (disabled || !list) return;

        const form = new FormData(event.currentTarget);

        try {
            const starts = utcDate(formText(form, "starts"));
            const ends = utcDate(formText(form, "ends"));

            if (!starts || !ends
                || Date.parse(ends) <= Date.parse(starts)) {
                throw new Error(
                    "Укажите корректные даты акции."
                );
            }

            const value = toMinor(
                formText(form, "value"),
                type === "percent" ? 2 : list.minor_units
            );

            if (value <= 0
                || (type === "percent" && value > 10000)) {
                throw new Error("Недопустимый размер скидки.");
            }

            if (scope !== "all" && !selected.length) {
                throw new Error("Выберите объекты акции.");
            }

            const priority = Number(formText(form, "priority"));

            if (!Number.isInteger(priority)
                || priority < -1000000
                || priority > 1000000) {
                throw new Error("Некорректный приоритет.");
            }

            const terms: Terms = {
                price_list_id: listId,
                name: formText(form, "name"),
                badge: formText(form, "badge"),

                discount_type: type,
                discount_value: value,

                max_discount_minor: optionalMinor(
                    form,
                    "max_discount",
                    list.minor_units
                ),

                priority,
                allow_promo: form.has("allow_promo"),
                allow_loyalty: form.has("allow_loyalty"),

                scope_type: scope,

                scope_ids: scope === "all"
                    ? [] : selected.map((item) => item.id),

                excluded_variant_ids: excluded.map(
                    (item) => item.id
                ),

                max_stock: stockFilter
                    ? optionalInteger(form, "max_stock")
                    : null,

                unsold_days: oldFilter
                    ? optionalInteger(form, "unsold_days")
                    : null,

                starts_at: starts,
                ends_at: ends,
            };

            setLoading(true);
            setPreview(null);

            const result = await crm<Preview>(
                "pricing/campaigns/preview",
                {
                    method: "POST",
                    body: terms,
                }
            );

            setPreview({ terms, result });

        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message : "Ошибка расчёта акции"
            );
        } finally {
            setLoading(false);
        }
    }

    return (
        <form
            className="pricing73-stack"
            onSubmit={(event) => void calculate(event)}
            onChange={invalidate}
        >
            <fieldset
                className="pricing73-fieldset"
                disabled={disabled}
            >
                <section className="crm65-panel pricing73-panel">
                    <h2>Новая автоматическая акция</h2>

                    <div className="pricing73-grid">
                        <label>
                            Название акции
                            <input
                                name="name"
                                required
                                maxLength={160}
                                placeholder="ADIDAS WEEK"
                            />
                        </label>

                        <label>
                            Метка на витрине
                            <input
                                name="badge"
                                required
                                maxLength={32}
                                defaultValue="SALE"
                                placeholder="OUTLET / LAST SIZE"
                            />
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
                                        {item.name} · {item.currency_code}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label>
                            Вид скидки
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
                            <input
                                name="value"
                                inputMode="decimal"
                                required
                                placeholder="10"
                            />
                        </label>

                        <label>
                            Максимальная скидка на единицу
                            <input
                                name="max_discount"
                                inputMode="decimal"
                                placeholder="Без ограничения"
                            />
                        </label>

                        <label>
                            Начало акции
                            <input
                                type="datetime-local"
                                name="starts"
                                defaultValue={initialStart}
                                required
                            />
                        </label>

                        <label>
                            Окончание акции
                            <input
                                type="datetime-local"
                                name="ends"
                                defaultValue={initialEnd}
                                required
                            />
                        </label>

                        <label>
                            Приоритет
                            <input
                                name="priority"
                                type="number"
                                min={-1000000}
                                max={1000000}
                                defaultValue={0}
                                required
                            />
                        </label>
                    </div>

                    <p className="crm65-muted">
                        Даты вводятся в локальном часовом поясе браузера.
                        По умолчанию акция рассчитана на неделю.
                        Акции не суммируются друг с другом.
                    </p>
                </section>

                <section className="crm65-panel pricing73-panel">
                    <h2>Товары, участвующие в акции</h2>

                    <label>
                        Область действия
                        <select
                            value={scope}
                            onChange={(event) => {
                                setScope(event.target.value as ScopeType);
                                setSelected([]);
                                invalidate();
                            }}
                        >
                            <option value="all">Весь каталог</option>
                            <option value="brand">Выбранные бренды</option>
                            <option value="category">Выбранные категории</option>
                            <option value="product">Выбранные товары</option>
                            <option value="variant">Отдельные SKU</option>
                        </select>
                    </label>

                    {scope !== "all" && (
                        <CampaignScope731
                            key={scope}
                            scope={scope}
                            selected={selected}
                            disabled={disabled}
                            onChange={(items) => {
                                setSelected(items);
                                invalidate();
                            }}
                        />
                    )}

                    <label className="pricing73-check">
                        <input
                            type="checkbox"
                            checked={stockFilter}
                            onChange={(event) =>
                                setStockFilter(event.target.checked)
                            }
                        />
                        Только товары с небольшим остатком
                    </label>

                    {stockFilter && (
                        <label>
                            Максимальный доступный остаток SKU
                            <input
                                name="max_stock"
                                type="number"
                                min={1}
                                max={1000000}
                                required
                                defaultValue={3}
                            />
                        </label>
                    )}

                    <label className="pricing73-check">
                        <input
                            type="checkbox"
                            checked={oldFilter}
                            onChange={(event) =>
                                setOldFilter(event.target.checked)
                            }
                        />
                        Только давно не продававшиеся товары
                    </label>

                    {oldFilter && (
                        <label>
                            Без продаж, дней
                            <input
                                name="unsold_days"
                                type="number"
                                min={1}
                                max={3650}
                                required
                                defaultValue={90}
                            />
                        </label>
                    )}

                    <p className="crm65-muted">
                        Подбор выполняется по активным SKU с назначенной
                        ценой. Перечень фиксируется при создании акции.
                    </p>
                </section>

                <section className="crm65-panel pricing73-panel">
                    <h2>Исключения</h2>

                    <p className="crm65-muted">
                        Выбранные здесь SKU не получат скидку.
                    </p>

                    <CampaignScope731
                        scope="variant"
                        selected={excluded}
                        disabled={disabled}
                        onChange={(items) => {
                            setExcluded(items);
                            invalidate();
                        }}
                    />
                </section>

                <section className="crm65-panel pricing73-panel">
                    <h2>Совместимость скидок</h2>

                    <label className="pricing73-check">
                        <input
                            name="allow_promo"
                            type="checkbox"
                            defaultChecked={false}
                        />
                        Разрешить дополнительный промокод
                    </label>

                    <label className="pricing73-check">
                        <input
                            name="allow_loyalty"
                            type="checkbox"
                            defaultChecked
                        />
                        Разрешить оплату бонусами
                    </label>

                    <p className="crm65-muted">
                        Бонусы дополнительно проверяются по действующей
                        политике лояльности. При запрете промокода
                        купон не применяется ко всей смешанной корзине.
                    </p>
                </section>

                <button className="crm65-secondary">
                    {loading
                        ? "Подбираем товары..."
                        : "Предварительный просмотр"}
                </button>
            </fieldset>

            {error && <p className="crm65-error" role="alert">{error}</p>}

            {preview && (
                <section className="crm65-panel pricing73-panel">
                    <h2>
                        Подобрано SKU: {preview.result.count}
                    </h2>

                    <p className="crm65-muted">
                        Показаны первые {preview.result.sample.length} SKU.
                        Цены рассчитаны для этой акции отдельно,
                        без учёта её пересечения с другими акциями.
                    </p>

                    <div className="pricing73-table-scroll">
                        <table className="pricing73-table">
                            <thead>
                            <tr>
                                <th>Товар / SKU</th>
                                <th>Остаток</th>
                                <th>Базовая цена</th>
                                <th>Цена по акции</th>
                            </tr>
                            </thead>

                            <tbody>
                            {preview.result.sample.map((item) => (
                                <tr key={item.id}>
                                    <td>
                                        <strong>
                                            {item.product_name ?? "Товар"}
                                        </strong>
                                        <small>{item.sku}</small>
                                    </td>

                                    <td>{item.available_qty}</td>

                                    <td>
                                            <span className="pricing73-old-price">
                                                {fromMinor(
                                                    item.base_price_minor,
                                                    preview.result.minor_units
                                                )}
                                            </span>
                                    </td>

                                    <td className="pricing73-current-price">
                                        {fromMinor(
                                            item.campaign_price_minor,
                                            preview.result.minor_units
                                        )}
                                    </td>
                                </tr>
                            ))}
                            </tbody>
                        </table>
                    </div>

                    {preview.result.count === 0 && (
                        <p className="crm65-error">
                            Подходящих товаров нет. Измените условия.
                        </p>
                    )}

                    <div className="pricing73-savebar">
                        <strong>
                            {preview.result.count} SKU ·{" "}
                            {preview.result.currency_code}
                        </strong>

                        <button
                            type="button"
                            className="crm65-primary"
                            disabled={disabled || preview.result.count === 0}
                            onClick={() => {
                                if (!window.confirm(
                                    `Создать акцию для ${preview.result.count} SKU?`
                                )) return;

                                submit({
                                    action: "campaign_create",
                                    ...preview.terms,
                                    preview_token:
                                    preview.result.preview_token,
                                });
                            }}
                        >
                            Создать акцию
                        </button>
                    </div>
                </section>
            )}

            <button
                type="button"
                className="crm65-secondary"
                disabled={disabled}
                onClick={onClose}
            >
                Закрыть редактор
            </button>
        </form>
    );
}