"use client";

import { useEffect, useState, type FormEvent } from "react";
import { crm } from "@/lib/crm65/client";
import LiveSearch from "@/components/crm65/LiveSearch";

import {
    documentLabels,
    type DocumentBody,
    type DocumentKind,
    type Page,
    type Variant,
    type Warehouse,
} from "./types";

type Line = {
    variant: Variant;
    quantity: string;
};

export default function DocumentEditor72({
                                             warehouses,
                                             disabled,
                                             onSubmit,
                                             onDirty,
                                         }: {
    warehouses: Warehouse[];
    disabled: boolean;
    onSubmit: (body: DocumentBody) => void;
    onDirty: (value: boolean) => void;
}) {
    const owned = warehouses.filter(
        (item) => item.kind === "owned" && item.is_active
    );

    const [kind, setKind] = useState<DocumentKind>("receipt");

    const [warehouseId, setWarehouseId] = useState(
        String(owned[0]?.id ?? "")
    );

    const [targetId, setTargetId] = useState("");
    const [reference, setReference] = useState("");
    const [note, setNote] = useState("");
    const [reason, setReason] = useState("damage");

    const [query, setQuery] = useState("");
    const [search, setSearch] = useState("");

    const [cursor, setCursor] = useState<number | null>(null);

    const [results, setResults] = useState<Page<Variant> | null>(null);

    const [lines, setLines] = useState<Line[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        onDirty(
            lines.length > 0
            || reference.trim() !== ""
            || note.trim() !== ""
        );
    }, [lines.length, reference, note, onDirty]);

    useEffect(() => {
        if (!warehouseId) return;

        let live = true;

        setLoading(true);
        setResults(null);

        const params = new URLSearchParams({
            warehouse_id: warehouseId,
            limit: "20",
        });

        if (search) params.set("q", search);
        if (cursor !== null) params.set("before_id", String(cursor));

        crm<Page<Variant>>(`warehouse/variants?${params}`)
            .then((response) => {
                if (live) setResults(response);
            })
            .catch((caught: unknown) => {
                if (live) {
                    setError(
                        caught instanceof Error
                            ? caught.message
                            : "Ошибка поиска SKU"
                    );
                }
            })
            .finally(() => {
                if (live) setLoading(false);
            });

        return () => {
            live = false;
        };
    }, [warehouseId, search, cursor]);

    function changeWarehouse(next: string) {
        if (lines.length > 0 && !window.confirm(
            "При смене склада выбранные строки будут очищены. Продолжить?"
        )) {
            return;
        }

        setWarehouseId(next);
        setTargetId("");
        setLines([]);
        setCursor(null);
        setError(null);
    }

    function changeKind(next: DocumentKind) {
        if (lines.length > 0 && !window.confirm(
            "При смене типа документа выбранные строки будут очищены. Продолжить?"
        )) {
            return;
        }

        setKind(next);
        setLines([]);
        setError(null);
    }

    function addVariant(variant: Variant) {
        if (lines.length >= 50) {
            setError("В одном документе допускается до 50 SKU.");
            return;
        }

        if (lines.some((line) => line.variant.id === variant.id)) {
            return;
        }

        setError(null);

        setLines((old) => [
            ...old,
            {
                variant,
                quantity: kind === "stocktake"
                    ? String(variant.on_hand_qty)
                    : "1",
            },
        ]);
    }

    function updateQuantity(id: number, quantity: string) {
        setLines((old) => old.map(
            (line) => line.variant.id === id
                ? { ...line, quantity }
                : line
        ));
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);

        if (disabled) return;

        if (!warehouseId || lines.length === 0) {
            setError("Выберите склад и добавьте хотя бы один SKU.");
            return;
        }

        if (kind === "transfer"
            && (!targetId || targetId === warehouseId)) {
            setError("Выберите склад назначения.");
            return;
        }

        if (kind === "writeoff" && note.trim().length < 3) {
            setError("Укажите причину списания в комментарии.");
            return;
        }

        for (const line of lines) {
            const quantity = Number(line.quantity);

            if (
                line.quantity.trim() === ""
                || !Number.isSafeInteger(quantity)
                || quantity < (kind === "stocktake" ? 0 : 1)
                || quantity > 1000000
            ) {
                setError(`Некорректное количество: ${line.variant.sku}`);
                return;
            }

            if (
                (kind === "transfer" || kind === "writeoff")
                && quantity > Number(line.variant.available_qty)
            ) {
                setError(
                    `Недостаточно доступного товара: ${line.variant.sku}`
                );
                return;
            }

            if (
                kind === "stocktake"
                && quantity < Number(line.variant.reserved_qty)
            ) {
                setError(
                    `Количество меньше резерва: ${line.variant.sku}`
                );
                return;
            }
        }

        if (!window.confirm(
            `${documentLabels[kind]}: ${lines.length} SKU. Провести документ?`
        )) {
            return;
        }

        onSubmit({
            kind,
            warehouse_id: Number(warehouseId),

            // A blank reference is generated by the parent
            // together with the operation UUID.
            reference: reference.trim(),

            note: note.trim() || null,

            ...(kind === "transfer" ? {
                to_warehouse_id: Number(targetId),
            } : {}),

            ...(kind === "writeoff" ? { reason } : {}),

            lines: lines.map((line) => ({
                variant_id: line.variant.id,
                quantity: Number(line.quantity),

                ...(
                    kind === "stocktake" || kind === "writeoff"
                        ? {
                            expected_revision: Number(
                                line.variant.revision
                            ),
                        }
                        : {}
                ),
            })),
        });
    }

    if (owned.length === 0) {
        return (
            <section className="crm65-panel warehouse72-panel">
                <h2>Нет активных собственных складов</h2>
                <p className="crm65-muted">
                    Сначала создайте склад на вкладке «Склады».
                </p>
            </section>
        );
    }

    return (
        <form
            className="warehouse72-editor"
            onSubmit={submit}
        >
            <fieldset
                className="warehouse72-fieldset"
                disabled={disabled}
            >
                <section className="crm65-panel warehouse72-panel">
                    <h2>Новый складской документ</h2>

                    <div className="warehouse72-kind-buttons">
                        {([
                            "receipt",
                            "transfer",
                            "stocktake",
                            "writeoff",
                        ] as DocumentKind[]).map((item) => (
                            <button
                                type="button"
                                key={item}
                                className={kind === item ? "active" : ""}
                                onClick={() => changeKind(item)}
                            >
                                {documentLabels[item]}
                            </button>
                        ))}
                    </div>

                    <div className="warehouse72-grid">
                        <label>
                            Склад
                            <select
                                required
                                value={warehouseId}
                                onChange={(event) =>
                                    changeWarehouse(event.target.value)
                                }
                            >
                                {owned.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        {kind === "transfer" && (
                            <label>
                                Склад назначения
                                <select
                                    required
                                    value={targetId}
                                    onChange={(event) =>
                                        setTargetId(event.target.value)
                                    }
                                >
                                    <option value="">Выбрать склад</option>

                                    {owned.filter(
                                        (item) => String(item.id) !== warehouseId
                                    ).map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}

                        <label>
                            Номер документа
                            <input
                                value={reference}
                                maxLength={150}
                                placeholder="Автоматически, если не заполнен"
                                onChange={(event) =>
                                    setReference(event.target.value)
                                }
                            />
                        </label>

                        {kind === "writeoff" && (
                            <label>
                                Причина списания
                                <select
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                >
                                    <option value="damage">Повреждение</option>
                                    <option value="loss">Потеря</option>
                                    <option value="correction">Корректировка</option>
                                </select>
                            </label>
                        )}
                    </div>

                    <label>
                        Комментарий
                        <textarea
                            value={note}
                            rows={3}
                            maxLength={1000}
                            onChange={(event) => setNote(event.target.value)}
                            placeholder="Накладная, причина операции..."
                        />
                    </label>
                </section>

                <section className="crm65-panel warehouse72-panel">
                    <h2>Добавить товары</h2>

                    <div className="warehouse72-search">
                        <LiveSearch
                            value={query}
                            applied={search}
                            onChange={setQuery}
                            disabled={disabled}
                            placeholder="Название, SKU или штрихкод"
                            onSearch={(value) => {
                                setSearch(value);
                                setCursor(null);
                                setError(null);
                            }}
                        />
                    </div>

                    {loading && (
                        <p className="crm65-muted">Поиск товаров...</p>
                    )}

                    {!loading && results?.items.length === 0 && (
                        <p className="crm65-muted">Товары не найдены.</p>
                    )}

                    <div className="warehouse72-lookup">
                        {results?.items.map((item) => (
                            <div
                                className="warehouse72-lookup-row"
                                key={item.id}
                            >
                                <div>
                                    <strong>
                                        {item.product_name ?? `Товар #${item.product_id}`}
                                    </strong>

                                    <small>{item.sku}</small>

                                    <small>
                                        Физически: {item.on_hand_qty}
                                        {" · "}Резерв: {item.reserved_qty}
                                        {" · "}Доступно: {item.available_qty}
                                    </small>

                                    {!item.is_active && (
                                        <small>Неактивный SKU</small>
                                    )}
                                </div>

                                <button
                                    type="button"
                                    className="crm65-secondary"
                                    disabled={
                                        lines.some((line) =>
                                            line.variant.id === item.id
                                        )
                                    }
                                    onClick={() => addVariant(item)}
                                >
                                    {lines.some((line) =>
                                        line.variant.id === item.id
                                    ) ? "Добавлен" : "+ Добавить"}
                                </button>
                            </div>
                        ))}
                    </div>

                    <div className="warehouse72-pagination">
                        <button
                            type="button"
                            disabled={loading || cursor === null}
                            onClick={() => setCursor(null)}
                        >
                            В начало
                        </button>

                        <button
                            type="button"
                            disabled={loading || !results?.next_before_id}
                            onClick={() => setCursor(
                                results?.next_before_id ?? null
                            )}
                        >
                            Далее
                        </button>
                    </div>
                </section>

                <section className="crm65-panel warehouse72-panel">
                    <h2>Строки документа · {lines.length}/50</h2>

                    {lines.length === 0 && (
                        <p className="crm65-muted">
                            Добавьте товары через поиск выше.
                        </p>
                    )}

                    {lines.length > 0 && (
                        <div className="warehouse72-table-scroll">
                            <table className="warehouse72-table">
                                <thead>
                                <tr>
                                    <th>Товар / SKU</th>
                                    <th>Физически</th>
                                    <th>Резерв</th>
                                    <th>Доступно</th>
                                    <th>
                                        {kind === "stocktake"
                                            ? "Подсчитано"
                                            : "Количество"}
                                    </th>
                                    <th></th>
                                </tr>
                                </thead>

                                <tbody>
                                {lines.map((line) => (
                                    <tr key={line.variant.id}>
                                        <td>
                                            <strong>
                                                {line.variant.product_name ?? "Товар"}
                                            </strong>
                                            <small>{line.variant.sku}</small>
                                        </td>

                                        <td>{line.variant.on_hand_qty}</td>

                                        <td className="warehouse72-reserved">
                                            {line.variant.reserved_qty}
                                        </td>

                                        <td>
                                            {line.variant.available_qty}
                                        </td>

                                        <td>
                                            <input
                                                className="warehouse72-quantity"
                                                type="number"
                                                required
                                                min={kind === "stocktake" ? 0 : 1}
                                                max={1000000}
                                                value={line.quantity}
                                                onChange={(event) =>
                                                    updateQuantity(
                                                        line.variant.id,
                                                        event.target.value
                                                    )
                                                }
                                            />
                                        </td>

                                        <td>
                                            <button
                                                type="button"
                                                className="warehouse72-danger"
                                                onClick={() => setLines((old) =>
                                                    old.filter((item) =>
                                                        item.variant.id !== line.variant.id
                                                    )
                                                )}
                                            >
                                                Убрать
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {kind === "stocktake" && (
                        <p className="crm65-muted">
                            Вводите полное фактическое количество,
                            включая зарезервированные единицы.
                        </p>
                    )}

                    {kind === "writeoff" && (
                        <p className="crm65-muted">
                            Вводите количество списываемого товара,
                            а не новый итоговый остаток.
                        </p>
                    )}
                </section>
            </fieldset>

            {error && <p className="crm65-error" role="alert">{error}</p>}

            <div className="warehouse72-savebar">
                <div>
                    <strong>{documentLabels[kind]}</strong>
                    <small>
                        {lines.length} SKU · Все строки проводятся одновременно
                    </small>
                </div>

                <button
                    className="crm65-primary"
                    disabled={disabled || lines.length === 0}
                >
                    {disabled ? "Документ заблокирован" : "Провести документ"}
                </button>
            </div>
        </form>
    );
}