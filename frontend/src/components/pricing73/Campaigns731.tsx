"use client";

import { useEffect, useState } from "react";
import { crm, when } from "@/lib/crm65/client";

import CampaignEditor731 from "./CampaignEditor731";

import {
    fromMinor,
    type Page,
    type PriceList,
    type SubmitCommand,
} from "./types";

type Campaign = {
    id: number;
    name: string;
    badge: string;
    price_list_id: number;
    currency_code: string;

    discount_type: "percent" | "fixed";
    discount_value: number;

    priority: number;
    revision: number;

    is_active: boolean;
    allow_promo: boolean;
    allow_loyalty: boolean;

    starts_at: string;
    ends_at: string;

    variant_count: number;

    state: "running" | "scheduled" | "disabled" | "expired";
};

type Target = {
    id: number;
    sku: string;
    product_name: string | null;
    is_active: boolean;
    base_price_minor: number | null;
    price_minor: number | null;
    winning_campaign_id: number | null;
};

const stateLabels: Record<Campaign["state"], string> = {
    running: "Действует",
    scheduled: "Запланирована",
    disabled: "Выключена",
    expired: "Завершена",
};

export default function Campaigns731({
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
    const [creating, setCreating] = useState(false);
    const [dirty, setDirty] = useState(false);

    const [data, setData] = useState<Page<Campaign> | null>(null);
    const [cursor, setCursor] = useState<number | null>(null);

    const [selected, setSelected] = useState<Campaign | null>(null);
    const [targets, setTargets] = useState<Page<Target> | null>(null);
    const [targetCursor, setTargetCursor] = useState<number | null>(null);

    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    function markDirty(value: boolean) {
        setDirty(value);
        onDirty(value);
    }

    useEffect(() => {
        let live = true;

        setLoading(true);

        const params = cursor === null
            ? ""
            : `?before_id=${cursor}`;

        crm<Page<Campaign>>(`pricing/campaigns${params}`)
            .then((result) => {
                if (live) setData(result);
            })
            .catch((caught) => {
                if (live) setError(
                    caught instanceof Error
                        ? caught.message : "Ошибка загрузки акций"
                );
            })
            .finally(() => {
                if (live) setLoading(false);
            });

        return () => { live = false; };
    }, [cursor]);

    const selectedId = selected?.id ?? null;

    useEffect(() => {
        if (selectedId === null) return;

        let live = true;

        setTargets(null);

        const params = targetCursor === null
            ? ""
            : `?before_id=${targetCursor}`;

        crm<Page<Target>>(
            `pricing/campaigns/${selectedId}/variants${params}`
        )
            .then((result) => {
                if (live) setTargets(result);
            })
            .catch((caught) => {
                if (live) setError(
                    caught instanceof Error
                        ? caught.message : "Ошибка состава акции"
                );
            });

        return () => { live = false; };
    }, [selectedId, targetCursor]);

    function closeEditor() {
        if (dirty && !window.confirm(
            "Отменить несохранённые параметры акции?"
        )) return;

        setCreating(false);
        markDirty(false);
    }

    function openCampaign(campaign: Campaign) {
        setTargetCursor(null);
        setSelected(campaign);
        setError(null);
    }

    function toggle(campaign: Campaign) {
        if (locked || dirty || !canManage) return;

        if (!window.confirm(
            campaign.is_active
                ? `Отключить акцию «${campaign.name}»?`
                : `Включить акцию «${campaign.name}»?`
        )) return;

        submit({
            action: "campaign_toggle",
            campaign_id: campaign.id,
            expected_revision: campaign.revision,
            is_active: !campaign.is_active,
        });
    }

    const digits = selected
        ? lists.find(
        (item) => item.id === selected.price_list_id
    )?.minor_units ?? 2
        : 2;

    return (
        <div className="pricing73-stack">
            <section className="crm65-panel pricing73-panel">
                <div className="pricing73-row-head">
                    <h2>Автоматические акции</h2>

                    {canManage && (
                        <button
                            type="button"
                            className="crm65-primary"
                            disabled={locked || creating || !lists.length}
                            onClick={() => setCreating(true)}
                        >
                            + Новая акция
                        </button>
                    )}
                </div>

                <p className="crm65-muted">
                    Скидки применяются автоматически в заданный период.
                    Базовые цены остаются неизменными.
                </p>

                {loading && <p>Загрузка...</p>}

                {!loading && data?.items.length === 0 && (
                    <p className="crm65-muted">
                        Автоматических акций пока нет.
                    </p>
                )}

                <div className="pricing73-table-scroll">
                    <table className="pricing73-table">
                        <thead>
                        <tr>
                            <th>Акция</th>
                            <th>Скидка</th>
                            <th>SKU</th>
                            <th>Приоритет</th>
                            <th>Период</th>
                            <th>Статус</th>
                            <th></th>
                        </tr>
                        </thead>

                        <tbody>
                        {data?.items.map((item) => {
                            const minorUnits = lists.find(
                                (list) => list.id === item.price_list_id
                            )?.minor_units ?? 2;

                            return (
                                <tr key={item.id}>
                                    <td>
                                        <strong>{item.name}</strong>
                                        <small>{item.badge}</small>
                                    </td>

                                    <td>
                                        {item.discount_type === "percent"
                                            ? `${fromMinor(
                                                item.discount_value,
                                                2
                                            )}%`
                                            : `${fromMinor(
                                                item.discount_value,
                                                minorUnits
                                            )} ${item.currency_code}`}
                                    </td>

                                    <td>{item.variant_count}</td>
                                    <td>{item.priority}</td>

                                    <td>
                                        <small>
                                            {when(item.starts_at)}
                                        </small>
                                        <small>
                                            {when(item.ends_at)}
                                        </small>
                                    </td>

                                    <td>
                                            <span
                                                className={
                                                    `campaign731-state ${item.state}`
                                                }
                                            >
                                                {stateLabels[item.state]}
                                            </span>
                                    </td>

                                    <td>
                                        <div className="pricing73-actions">
                                            <button
                                                type="button"
                                                disabled={locked}
                                                onClick={() =>
                                                    openCampaign(item)
                                                }
                                            >
                                                Товары
                                            </button>

                                            {canManage && (
                                                <button
                                                    type="button"
                                                    disabled={
                                                        locked
                                                        || dirty
                                                        || (
                                                            item.state === "expired"
                                                            && !item.is_active
                                                        )
                                                    }
                                                    onClick={() => toggle(item)}
                                                >
                                                    {item.is_active
                                                        ? "Отключить"
                                                        : "Включить"}
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
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

            {creating && canManage && (
                <CampaignEditor731
                    lists={lists}
                    locked={locked}
                    submit={submit}
                    onDirty={markDirty}
                    onClose={closeEditor}
                />
            )}

            {selected && (
                <section className="crm65-panel pricing73-panel">
                    <div className="pricing73-row-head">
                        <h2>Товары акции: {selected.name}</h2>

                        <button
                            type="button"
                            className="crm65-secondary"
                            onClick={() => setSelected(null)}
                        >
                            Закрыть
                        </button>
                    </div>

                    <p className="crm65-muted">
                        Дополнительные промокоды:{" "}
                        {selected.allow_promo ? "разрешены" : "запрещены"}
                        {" · "}
                        Бонусы:{" "}
                        {selected.allow_loyalty ? "разрешены" : "запрещены"}
                    </p>

                    <div className="pricing73-table-scroll">
                        <table className="pricing73-table">
                            <thead>
                            <tr>
                                <th>Товар / SKU</th>
                                <th>Базовая цена</th>
                                <th>Текущая цена</th>
                                <th>Действующая акция</th>
                            </tr>
                            </thead>

                            <tbody>
                            {targets?.items.map((item) => (
                                <tr key={item.id}>
                                    <td>
                                        <strong>
                                            {item.product_name ?? "Товар"}
                                        </strong>
                                        <small>{item.sku}</small>
                                        {!item.is_active && (
                                            <small>Неактивный SKU</small>
                                        )}
                                    </td>

                                    <td>
                                        {fromMinor(
                                            item.base_price_minor,
                                            digits
                                        ) || "—"}
                                    </td>

                                    <td>
                                        {fromMinor(
                                            item.price_minor,
                                            digits
                                        ) || "—"}
                                    </td>

                                    <td>
                                        {item.winning_campaign_id === selected.id
                                            ? "Эта акция"
                                            : item.winning_campaign_id !== null
                                                ? `Другая акция #${item.winning_campaign_id}`
                                                : "Без автоматической акции"}
                                    </td>
                                </tr>
                            ))}
                            </tbody>
                        </table>
                    </div>

                    {!targets && (
                        <p className="crm65-muted">
                            Загружаем товары...
                        </p>
                    )}

                    <div className="pricing73-actions">
                        <button
                            type="button"
                            disabled={targetCursor === null}
                            onClick={() => setTargetCursor(null)}
                        >
                            В начало
                        </button>

                        <button
                            type="button"
                            disabled={!targets?.next_before_id}
                            onClick={() =>
                                setTargetCursor(
                                    targets?.next_before_id ?? null
                                )
                            }
                        >
                            Далее
                        </button>
                    </div>
                </section>
            )}

            {error && <p className="crm65-error" role="alert">{error}</p>}
        </div>
    );
}