"use client";

import { useEffect, useState, type FormEvent } from "react";
import { crm, when } from "@/lib/crm65/client";

import {
    formText,
    fromMinor,
    integer,
    optionalMinor,
    toMinor,
    type Market,
    type Page,
    type Policies,
    type PriceList,
    type Rule,
    type ScopeOption,
    type ScopeType,
    type SubmitCommand,
} from "./types";

const scopeLabels: Record<ScopeType, string> = {
    all: "Все товары",
    brand: "Бренд",
    category: "Категория",
    product: "Товар",
    variant: "SKU",
};

type EditableRule = Rule & {
    localId: number;
};

function RuleEditor({
                        rule,
                        locked,
                        change,
                        remove,
                    }: {
    rule: EditableRule;
    locked: boolean;
    change: (value: EditableRule) => void;
    remove: () => void;
}) {
    const [query, setQuery] = useState("");
    const [options, setOptions] = useState<ScopeOption[]>([]);
    const [cursor, setCursor] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function search(next: number | null = null) {
        if (rule.scope_type === "all" || loading) return;

        setLoading(true);
        setError(null);

        try {
            const params = new URLSearchParams({
                scope_type: rule.scope_type,
                q: query.trim(),
                limit: "30",
            });

            if (next !== null) {
                params.set("before_id", String(next));
            }

            const result = await crm<Page<ScopeOption>>(
                `pricing/scopes?${params}`
            );

            setOptions(result.items);
            setCursor(result.next_before_id);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message : "Ошибка поиска"
            );
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="pricing73-rule">
            <div className="pricing73-grid">
                <label>
                    Область действия
                    <select
                        value={rule.scope_type}
                        disabled={locked || loading}
                        onChange={(event) => {
                            setOptions([]);
                            setCursor(null);
                            setQuery("");

                            change({
                                ...rule,
                                scope_type: event.target.value as ScopeType,
                                scope_id: null,
                            });
                        }}
                    >
                        {Object.entries(scopeLabels).map(([key, label]) => (
                            <option key={key} value={key}>{label}</option>
                        ))}
                    </select>
                </label>

                <label>
                    Действие
                    <select
                        value={rule.decision}
                        disabled={locked}
                        onChange={(event) => change({
                            ...rule,
                            decision: event.target.value as Rule["decision"],
                        })}
                    >
                        <option value="allow">Разрешить списание</option>
                        <option value="deny">Запретить списание</option>
                    </select>
                </label>

                <label>
                    Приоритет
                    <input
                        type="number"
                        min={-1000000}
                        max={1000000}
                        required
                        value={Number.isNaN(rule.priority) ? "" : rule.priority}
                        disabled={locked}
                        onChange={(event) => change({
                            ...rule,
                            priority: event.target.value === ""
                                ? NaN : Number(event.target.value),
                        })}
                    />
                </label>
            </div>

            {rule.scope_type !== "all" && (
                <>
                    <div className="pricing73-actions">
                        <input
                            value={query}
                            maxLength={64}
                            disabled={locked}
                            placeholder={`Найти: ${scopeLabels[rule.scope_type]}`}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === "Enter") {
                                    event.preventDefault();
                                    void search();
                                }
                            }}
                        />

                        <button
                            type="button"
                            className="crm65-secondary"
                            disabled={locked || loading}
                            onClick={() => void search()}
                        >
                            Найти
                        </button>

                        {cursor !== null && (
                            <button
                                type="button"
                                disabled={locked || loading}
                                onClick={() => void search(cursor)}
                            >
                                Далее
                            </button>
                        )}
                    </div>

                    <label>
                        Объект правила
                        <select
                            required
                            value={rule.scope_id ?? ""}
                            disabled={locked}
                            onChange={(event) => change({
                                ...rule,
                                scope_id: event.target.value
                                    ? Number(event.target.value) : null,
                            })}
                        >
                            <option value="">Выбрать</option>

                            {rule.scope_id !== null
                                && !options.some(
                                    (item) => item.id === rule.scope_id
                                ) && (
                                    <option value={rule.scope_id}>
                                        Выбранный объект #{rule.scope_id}
                                    </option>
                                )}

                            {options.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name ?? "Без названия"} · #{item.id}
                                </option>
                            ))}
                        </select>
                    </label>
                </>
            )}

            {error && <p className="crm65-error">{error}</p>}

            <button
                type="button"
                className="pricing73-danger"
                disabled={locked}
                onClick={remove}
            >
                Убрать правило
            </button>
        </div>
    );
}

function PolicyEditor({
                          market,
                          data,
                          canManage,
                          locked,
                          submit,
                          onDirty,
                      }: {
    market: Market;
    data: Policies;
    canManage: boolean;
    locked: boolean;
    submit: SubmitCommand;
    onDirty: (value: boolean) => void;
}) {
    const latest = data.latest;
    const digits = market.minor_units;

    const [error, setError] = useState<string | null>(null);

    const [rules, setRules] = useState<EditableRule[]>(
        data.rules.map((rule, index) => ({
            localId: index + 1,
            scope_type: rule.scope_type,
            scope_id: rule.brand_id
                ?? rule.category_id
                ?? rule.product_id
                ?? rule.variant_id
                ?? null,
            decision: rule.decision,
            priority: rule.priority,
        }))
    );

    const [nextId, setNextId] = useState(data.rules.length + 1);

    const pending = latest !== null
        && Date.parse(latest.effective_from) > Date.now();

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);

        if (locked || !canManage || pending) return;

        const form = new FormData(event.currentTarget);

        try {
            const enabled = form.has("enabled");

            if (rules.some((rule) =>
                !Number.isInteger(rule.priority)
                || rule.priority < -1000000
                || rule.priority > 1000000
                || (rule.scope_type !== "all" && rule.scope_id === null)
            )) {
                throw new Error("Заполните все параметры правил.");
            }

            if (enabled && !rules.some((rule) => rule.decision === "allow")) {
                throw new Error(
                    "Добавьте хотя бы одно разрешающее правило."
                );
            }

            const share = toMinor(formText(form, "share"), 2);

            if (share > 10000) {
                throw new Error("Доля оплаты бонусами не может превышать 100%.");
            }

            const money = toMinor(formText(form, "money_step"), digits);

            if (money < 1) {
                throw new Error("Стоимость бонусного шага должна быть положительной.");
            }

            if (!window.confirm(
                "Создать новую версию правил лояльности? Она вступит в силу через две минуты."
            )) return;

            submit({
                action: "policy_create",
                market_id: market.id,
                expected_policy_id: latest?.id ?? null,

                program_code: formText(form, "program"),
                is_enabled: enabled,

                bonus_units_per_step:
                    integer(formText(form, "bonus_step"), 1),

                money_minor_per_step: money,
                max_goods_share_bps: share,

                max_discount_minor:
                    optionalMinor(form, "maximum", digits),

                minimum_cash_minor:
                    toMinor(formText(form, "minimum_cash"), digits),

                rules: rules.map((rule) => ({
                    scope_type: rule.scope_type,
                    scope_id: rule.scope_id,
                    decision: rule.decision,
                    priority: rule.priority,
                })),
            });

        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message : "Ошибка правил лояльности"
            );
        }
    }

    return (
        <div className="pricing73-stack">
            <section className="crm65-panel pricing73-panel">
                <h2>Версии политики · {market.code}</h2>

                {data.items.length === 0 && (
                    <p className="crm65-muted">
                        Программа списания бонусов ещё не настроена.
                    </p>
                )}

                {data.items.map((policy) => (
                    <div className="pricing73-rule" key={policy.id}>
                        <strong>{policy.rule_version}</strong>

                        <small>
                            {policy.is_enabled
                                ? "Списание разрешено" : "Списание отключено"}
                            {" · "}{fromMinor(policy.max_goods_share_bps, 2)}%
                            {" · "}{when(policy.effective_from)}
                        </small>

                        <small>
                            {data.active_policy_id === policy.id
                                ? "Действующая версия"
                                : Date.parse(policy.effective_from) > Date.now()
                                    ? "Запланированная версия"
                                    : "Предыдущая версия"}
                        </small>
                    </div>
                ))}
            </section>

            {pending && (
                <p className="pricing73-info">
                    Уже запланирована новая политика. После её активации
                    нажмите «Обновить».
                </p>
            )}

            {canManage && (
                <form
                    className="pricing73-stack"
                    onSubmit={save}
                    onChange={() => onDirty(true)}
                >
                    <fieldset
                        className="pricing73-fieldset"
                        disabled={locked || pending}
                    >
                        <section className="crm65-panel pricing73-panel">
                            <h2>Новая версия программы</h2>

                            <label className="pricing73-check">
                                <input
                                    type="checkbox"
                                    name="enabled"
                                    defaultChecked={latest?.is_enabled ?? false}
                                />
                                Разрешить списание бонусов
                            </label>

                            <div className="pricing73-grid">
                                <label>
                                    Код программы
                                    <input
                                        name="program"
                                        required
                                        maxLength={64}
                                        defaultValue={
                                            latest?.program_code ?? "BOARDRIDERS"
                                        }
                                    />
                                </label>

                                <label>
                                    Бонусов в одном шаге
                                    <input
                                        name="bonus_step"
                                        type="number"
                                        min={1}
                                        required
                                        defaultValue={latest?.bonus_units_per_step ?? 1}
                                    />
                                </label>

                                <label>
                                    Стоимость шага, {market.currency_code}
                                    <input
                                        name="money_step"
                                        inputMode="decimal"
                                        required
                                        defaultValue={latest
                                            ? fromMinor(latest.money_minor_per_step, digits)
                                            : "1"}
                                    />
                                </label>

                                <label>
                                    Максимальная оплата бонусами, %
                                    <input
                                        name="share"
                                        required
                                        inputMode="decimal"
                                        defaultValue={latest
                                            ? fromMinor(latest.max_goods_share_bps, 2)
                                            : "30"}
                                    />
                                </label>

                                <label>
                                    Максимальная скидка бонусами
                                    <input
                                        name="maximum"
                                        inputMode="decimal"
                                        defaultValue={fromMinor(
                                            latest?.max_discount_minor,
                                            digits
                                        )}
                                    />
                                </label>

                                <label>
                                    Минимальная денежная оплата
                                    <input
                                        name="minimum_cash"
                                        required
                                        inputMode="decimal"
                                        defaultValue={fromMinor(
                                            latest?.minimum_cash_minor ?? 0,
                                            digits
                                        )}
                                    />
                                </label>
                            </div>

                            <p className="crm65-muted">
                                Лимит применяется к стоимости товаров после
                                скидок, без стоимости доставки.
                            </p>
                        </section>

                        <section className="crm65-panel pricing73-panel">
                            <h2>Правила списания</h2>

                            <p className="crm65-muted">
                                Больший приоритет выигрывает. При одинаковом
                                приоритете запрет сильнее разрешения.
                                Если ни одно разрешение не подходит,
                                бонусы списывать нельзя.
                            </p>

                            {rules.map((rule) => (
                                <RuleEditor
                                    key={rule.localId}
                                    rule={rule}
                                    locked={locked || pending}
                                    change={(updated) => {
                                        setRules((old) => old.map((item) =>
                                            item.localId === updated.localId
                                                ? updated : item
                                        ));
                                        onDirty(true);
                                    }}
                                    remove={() => {
                                        setRules((old) => old.filter(
                                            (item) => item.localId !== rule.localId
                                        ));
                                        onDirty(true);
                                    }}
                                />
                            ))}

                            <button
                                type="button"
                                className="crm65-secondary"
                                disabled={rules.length >= 50}
                                onClick={() => {
                                    setRules((old) => [
                                        ...old,
                                        {
                                            localId: nextId,
                                            scope_type: "all",
                                            scope_id: null,
                                            decision: "deny",
                                            priority: 0,
                                        },
                                    ]);
                                    setNextId((value) => value + 1);
                                    onDirty(true);
                                }}
                            >
                                + Добавить правило
                            </button>
                        </section>
                    </fieldset>

                    <button
                        className="crm65-primary"
                        disabled={locked || pending}
                    >
                        Сохранить новую версию правил
                    </button>
                </form>
            )}

            {error && <p className="crm65-error" role="alert">{error}</p>}
        </div>
    );
}

export default function Loyalty73({
                                      markets,
                                      lists,
                                      canManage,
                                      locked,
                                      submit,
                                      onDirty,
                                  }: {
    markets: Market[];
    lists: PriceList[];
    canManage: boolean;
    locked: boolean;
    submit: SubmitCommand;
    onDirty: (value: boolean) => void;
}) {
    const [marketId, setMarketId] = useState(markets[0]?.id ?? 0);

    const [data, setData] = useState<Policies | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [marketForm, setMarketForm] = useState(false);

    const [dirty, setDirty] = useState(false);

    const market = markets.find((item) => item.id === marketId);

    function markDirty(value: boolean) {
        setDirty(value);
        onDirty(value);
    }

    useEffect(() => {
        if (!marketId) return;

        let live = true;

        setData(null);

        crm<Policies>(`pricing/policies/${marketId}`)
            .then((response) => {
                if (live) setData(response);
            })
            .catch((caught) => {
                if (live) setError(
                    caught instanceof Error
                        ? caught.message : "Ошибка загрузки политики"
                );
            });

        return () => {
            live = false;
        };
    }, [marketId]);

    return (
        <div className="pricing73-stack">
            <section className="crm65-panel pricing73-panel">
                <div className="pricing73-row-head">
                    <h2>Рынки программы лояльности</h2>

                    {canManage && (
                        <button
                            type="button"
                            className="crm65-secondary"
                            disabled={locked || dirty}
                            onClick={() => setMarketForm((value) => !value)}
                        >
                            + Новый рынок
                        </button>
                    )}
                </div>

                <label>
                    Рынок
                    <select
                        value={marketId}
                        disabled={locked || marketForm}
                        onChange={(event) => {
                            if (dirty && !window.confirm(
                                "Отменить несохранённые правила?"
                            )) return;

                            markDirty(false);
                            setMarketId(Number(event.target.value));
                        }}
                    >
                        {markets.length === 0 && (
                            <option value={0}>Нет рынков</option>
                        )}

                        {markets.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name} · {item.currency_code}
                            </option>
                        ))}
                    </select>
                </label>

                {marketForm && canManage && (
                    <form
                        className="pricing73-grid"
                        onChange={() => markDirty(true)}
                        onSubmit={(event) => {
                            event.preventDefault();

                            const form = new FormData(event.currentTarget);

                            submit({
                                action: "market_create",
                                price_list_id: Number(
                                    formText(form, "price_list")
                                ),
                                name: formText(form, "name"),
                                code: formText(form, "code").toUpperCase(),
                            });
                        }}
                    >
                        <label>
                            Название
                            <input
                                name="name"
                                required
                                maxLength={120}
                                disabled={locked}
                            />
                        </label>

                        <label>
                            Код рынка
                            <input
                                name="code"
                                required
                                maxLength={16}
                                placeholder="UA"
                                disabled={locked}
                            />
                        </label>

                        <label>
                            Прайс-лист для валюты рынка
                            <select name="price_list" required disabled={locked}>
                                {lists.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <button className="crm65-primary" disabled={locked}>
                            Создать рынок
                        </button>
                    </form>
                )}
            </section>

            {!marketForm && market && data && (
                <PolicyEditor
                    key={`${market.id}-${data.latest?.id ?? 0}`}
                    market={market}
                    data={data}
                    canManage={canManage}
                    locked={locked}
                    submit={submit}
                    onDirty={markDirty}
                />
            )}

            {error && <p className="crm65-error">{error}</p>}
        </div>
    );
}   