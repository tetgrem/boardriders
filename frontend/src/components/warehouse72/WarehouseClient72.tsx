"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { crm, CrmError, when } from "@/lib/crm65/client";

import DocumentEditor72 from "./DocumentEditor72";
import WarehouseDirectory72 from "./WarehouseDirectory72";

import {
    documentLabels,
    movementLabels,
    type DocumentBody,
    type DocumentDetail,
    type DocumentRow,
    type Movement,
    type Overview,
    type Page,
    type PostedDocument,
    type Reservation,
    type Stock,
} from "./types";

type ReadTab =
    | "stocks"
    | "movements"
    | "reservations"
    | "documents";

type Tab = ReadTab | "operation" | "warehouses";

type ReadRow = Stock | Movement | Reservation | DocumentRow;

type PendingDocument = DocumentBody & {
    operation_key: string;
};

const tabs: Array<{ id: Tab; name: string }> = [
    { id: "stocks", name: "Остатки" },
    { id: "operation", name: "+ Новый документ" },
    { id: "movements", name: "Движения" },
    { id: "reservations", name: "Резервы" },
    { id: "documents", name: "Документы" },
    { id: "warehouses", name: "Склады" },
];

function isReadTab(tab: Tab): tab is ReadTab {
    return [
        "stocks",
        "movements",
        "reservations",
        "documents",
    ].includes(tab);
}

export default function WarehouseClient72({
                                              canManage,
                                          }: {
    canManage: boolean;
}) {
    const router = useRouter();

    const [tab, setTab] = useState<Tab>("stocks");
    const [overview, setOverview] = useState<Overview | null>(null);

    const [page, setPage] = useState<{
        section: ReadTab;
        data: Page<ReadRow>;
    } | null>(null);

    const [warehouseId, setWarehouseId] = useState("");
    const [query, setQuery] = useState("");
    const [search, setSearch] = useState("");

    const [stockStatus, setStockStatus] = useState("all");

    const [cursor, setCursor] = useState<number | null>(null);
    const [previous, setPrevious] = useState<Array<number | null>>([]);

    const [detail, setDetail] = useState<DocumentDetail | null>(null);

    const [reload, setReload] = useState(0);
    const [editorVersion, setEditorVersion] = useState(0);

    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [dirty, setDirty] = useState(false);

    const [pending, setPending] = useState<PendingDocument | null>(null);
    const pendingRef = useRef<PendingDocument | null>(null);

    const submitting = useRef(false);

    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const handleError = useCallback((caught: unknown) => {
        if (caught instanceof CrmError && caught.status === 401) {
            router.replace("/admin/login");
            return;
        }

        setError(
            caught instanceof Error
                ? caught.message
                : "Не удалось выполнить складскую операцию"
        );
    }, [router]);

    useEffect(() => {
        let live = true;

        crm<Overview>("warehouse/overview")
            .then((response) => {
                if (live) setOverview(response);
            })
            .catch((caught) => {
                if (live) handleError(caught);
            });

        return () => {
            live = false;
        };
    }, [reload, handleError]);

    useEffect(() => {
        if (!isReadTab(tab)) {
            setLoading(false);
            return;
        }

        const section = tab;
        let live = true;

        setLoading(true);

        const params = new URLSearchParams({ limit: "30" });

        if (warehouseId) params.set("warehouse_id", warehouseId);
        if (search) params.set("q", search);

        if (section === "stocks") {
            params.set("stock_status", stockStatus);
        }

        if (cursor !== null) {
            params.set("before_id", String(cursor));
        }

        crm<Page<ReadRow>>(`warehouse/${section}?${params}`)
            .then((response) => {
                if (live) {
                    setPage({ section, data: response });
                }
            })
            .catch((caught) => {
                if (live) {
                    setPage(null);
                    handleError(caught);
                }
            })
            .finally(() => {
                if (live) setLoading(false);
            });

        return () => {
            live = false;
        };
    }, [
        tab,
        warehouseId,
        search,
        stockStatus,
        cursor,
        reload,
        handleError,
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
                "Есть незавершённый складской документ. Покинуть страницу?"
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
        setCursor(null);
        setPrevious([]);
        setPage(null);
        setDetail(null);
    }

    function chooseTab(next: Tab) {
        if (busy || pendingRef.current || next === tab) return;

        if (dirty && !window.confirm(
            "Отменить несохранённые изменения?"
        )) {
            return;
        }

        setDirty(false);
        setError(null);
        setTab(next);
        setQuery("");
        setSearch("");
        setStockStatus("all");
        resetPagination();
    }

    async function send(body: PendingDocument) {
        if (submitting.current) return;

        submitting.current = true;
        setBusy(true);
        setError(null);
        setNotice(null);

        try {
            const response = await crm<PostedDocument>(
                "warehouse/submit",
                {
                    method: "POST",
                    body,
                }
            );

            pendingRef.current = null;
            setPending(null);
            setDirty(false);

            setNotice(
                response.replayed
                    ? `Документ #${response.document_id} уже был проведён. Повторного движения нет.`
                    : `Документ #${response.document_id} успешно проведён.`
            );

            setEditorVersion((value) => value + 1);
            setReload((value) => value + 1);

        } catch (caught) {
            // Keep the exact payload and UUID for a safe retry.
            handleError(caught);
        } finally {
            submitting.current = false;
            setBusy(false);
        }
    }

    function submitDocument(values: DocumentBody) {
        if (!canManage
            || submitting.current
            || pendingRef.current) {
            return;
        }

        const key = crypto.randomUUID();

        const body: PendingDocument = {
            ...values,
            operation_key: key,
        };

        if (
            ["receipt", "transfer", "stocktake", "writeoff"]
                .includes(body.kind)
            && !body.reference
        ) {
            body.reference = `DOC-${key.toUpperCase()}`;
        }

        pendingRef.current = body;
        setPending(body);

        void send(body);
    }

    function discardPending() {
        if (busy) return;

        if (!window.confirm(
            "Сервер мог уже провести документ. Перед новым проведением проверьте историю. Очистить редактор?"
        )) {
            return;
        }

        pendingRef.current = null;
        setPending(null);
        setDirty(false);

        setEditorVersion((value) => value + 1);
        setReload((value) => value + 1);
    }

    async function openDocument(id: number) {
        setError(null);

        try {
            setDetail(await crm<DocumentDetail>(
                `warehouse/documents/${id}`
            ));
        } catch (caught) {
            handleError(caught);
        }
    }

    const activePage = page?.section === tab
        ? page.data : null;

    const items = activePage?.items ?? [];
    const locked = busy || pending !== null;

    return (
        <div className="warehouse72-page">

            <div className="crm65-pagehead">
                <div>
                    <p className="crm65-overline">
                        COMMERCE / INVENTORY
                    </p>

                    <h1>Складское управление</h1>

                    <p className="crm65-muted">
                        Остатки, складские документы и резервы покупателей.
                    </p>
                </div>

                <button
                    type="button"
                    className="crm65-secondary"
                    disabled={locked || dirty}
                    onClick={() => {
                        setError(null);
                        setReload((value) => value + 1);
                    }}
                >
                    ↻ Обновить
                </button>
            </div>

            {error && <p className="crm65-error" role="alert">{error}</p>}

            {notice && (
                <p className="warehouse72-success" role="status">
                    {notice}
                </p>
            )}

            {!canManage && (
                <p className="crm65-muted">
                    У вас доступ только для просмотра складских данных.
                </p>
            )}

            {overview && (
                <div className="warehouse72-summary">
                    {[
                        ["Физический остаток", overview.summary.on_hand],
                        ["Зарезервировано", overview.summary.reserved],
                        ["Доступно", overview.summary.available],
                        ["Активных складов", overview.summary.active_warehouses],
                    ].map(([label, value], index) => (
                        <div
                            key={String(label)}
                            className={`warehouse72-metric metric-${index}`}
                        >
                            <small>{label}</small>
                            <strong>
                                {Number(value).toLocaleString("uk-UA")}
                            </strong>
                        </div>
                    ))}
                </div>
            )}

            <div className="warehouse72-tabs">
                {tabs.filter(
                    (item) => item.id !== "operation" || canManage
                ).map((item) => (
                    <button
                        type="button"
                        key={item.id}
                        className={tab === item.id ? "active" : ""}
                        disabled={locked}
                        onClick={() => chooseTab(item.id)}
                    >
                        {item.name}
                    </button>
                ))}
            </div>

            {pending && (
                <section className="crm65-panel warehouse72-panel">
                    <h2>
                        {busy
                            ? "Проведение документа..."
                            : "Результат проведения не подтверждён"}
                    </h2>

                    <p className="crm65-muted">
                        Номер: {String(
                        pending.reference ?? pending.operation_key
                    )}
                    </p>

                    <p className="crm65-muted">
                        Повторная отправка использует тот же UUID.
                        Уже проведённый документ не изменит остатки второй раз.
                    </p>

                    {!busy && (
                        <div className="warehouse72-actions">
                            <button
                                type="button"
                                className="crm65-primary"
                                onClick={() => void send(pending)}
                            >
                                Повторить проведение
                            </button>

                            <button
                                type="button"
                                className="crm65-secondary"
                                onClick={discardPending}
                            >
                                Очистить редактор
                            </button>
                        </div>
                    )}
                </section>
            )}

            {tab === "operation" && overview && canManage && (
                <DocumentEditor72
                    key={editorVersion}
                    warehouses={overview.warehouses}
                    disabled={locked}
                    onSubmit={submitDocument}
                    onDirty={setDirty}
                />
            )}

            {tab === "warehouses" && overview && (
                <WarehouseDirectory72
                    key={editorVersion}
                    warehouses={overview.warehouses}
                    canManage={canManage}
                    disabled={locked}
                    onSubmit={submitDocument}
                    onDirty={setDirty}
                />
            )}

            {isReadTab(tab) && (
                <section className="crm65-panel warehouse72-panel">

                    <form
                        className="warehouse72-grid warehouse72-filters"
                        onSubmit={(event) => {
                            event.preventDefault();

                            setSearch(query.trim());
                            resetPagination();
                            setReload((value) => value + 1);
                        }}
                    >
                        <label>
                            Склад
                            <select
                                value={warehouseId}
                                onChange={(event) => {
                                    setWarehouseId(event.target.value);
                                    resetPagination();
                                }}
                            >
                                <option value="">Все склады</option>

                                {overview?.warehouses.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                        {item.kind === "partner"
                                            ? " (партнёрский)" : ""}
                                    </option>
                                ))}
                            </select>
                        </label>

                        {tab === "stocks" && (
                            <label>
                                Наличие
                                <select
                                    value={stockStatus}
                                    onChange={(event) => {
                                        setStockStatus(event.target.value);
                                        resetPagination();
                                    }}
                                >
                                    <option value="all">Все</option>
                                    <option value="available">Есть в наличии</option>
                                    <option value="reserved">Есть резерв</option>
                                    <option value="low">Мало: 1–5 шт.</option>
                                    <option value="empty">Нет свободных</option>
                                </select>
                            </label>
                        )}

                        <label>
                            Поиск
                            <input
                                value={query}
                                maxLength={64}
                                placeholder={
                                    tab === "documents"
                                        ? "Номер документа"
                                        : "Название, SKU, штрихкод"
                                }
                                onChange={(event) => setQuery(event.target.value)}
                            />
                        </label>

                        <button
                            className="crm65-secondary"
                            disabled={loading}
                        >
                            Найти
                        </button>
                    </form>

                    {loading && (
                        <p className="crm65-muted">Загружаем данные...</p>
                    )}

                    {!loading && items.length === 0 && (
                        <p className="crm65-muted">Записей не найдено.</p>
                    )}

                    <div className="warehouse72-table-scroll">

                        {tab === "stocks" && (
                            <table className="warehouse72-table">
                                <thead>
                                <tr>
                                    <th>Товар / SKU</th>
                                    <th>Склад</th>
                                    <th>Физически</th>
                                    <th>Резерв</th>
                                    <th>Доступно</th>
                                </tr>
                                </thead>

                                <tbody>
                                {(items as Stock[]).map((item) => (
                                    <tr key={item.id}>
                                        <td>
                                            <strong>
                                                {item.product_name ?? `Товар #${item.product_id}`}
                                            </strong>
                                            <small>{item.sku}</small>

                                            {!item.is_active && (
                                                <small>Неактивный SKU</small>
                                            )}

                                            {!item.reserve_consistent && (
                                                <small className="warehouse72-negative">
                                                    Расхождение резервов
                                                </small>
                                            )}
                                        </td>

                                        <td>{item.warehouse_name}</td>
                                        <td>{item.on_hand_qty}</td>

                                        <td className="warehouse72-reserved">
                                            {item.reserved_qty}
                                        </td>

                                        <td className={
                                            Number(item.available_qty) === 0
                                                ? "warehouse72-negative"
                                                : "warehouse72-positive"
                                        }>
                                            {item.available_qty}
                                        </td>
                                    </tr>
                                ))}
                                </tbody>
                            </table>
                        )}

                        {tab === "movements" && (
                            <table className="warehouse72-table">
                                <thead>
                                <tr>
                                    <th>Товар / SKU</th>
                                    <th>Склад</th>
                                    <th>Операция</th>
                                    <th>Количество</th>
                                    <th>Дата</th>
                                </tr>
                                </thead>

                                <tbody>
                                {(items as Movement[]).map((item) => (
                                    <tr key={item.id}>
                                        <td>
                                            <strong>
                                                {item.product_name ?? "Товар"}
                                            </strong>
                                            <small>{item.sku}</small>
                                        </td>

                                        <td>{item.warehouse_name}</td>

                                        <td>
                                            {movementLabels[item.type] ?? item.type}
                                            <small>{item.reference ?? "—"}</small>
                                        </td>

                                        <td className={
                                            Number(item.quantity_delta) > 0
                                                ? "warehouse72-positive"
                                                : "warehouse72-negative"
                                        }>
                                            {Number(item.quantity_delta) > 0 ? "+" : ""}
                                            {item.quantity_delta}
                                        </td>

                                        <td>{when(item.created_at)}</td>
                                    </tr>
                                ))}
                                </tbody>
                            </table>
                        )}

                        {tab === "reservations" && (
                            <table className="warehouse72-table">
                                <thead>
                                <tr>
                                    <th>Товар / SKU</th>
                                    <th>Склад</th>
                                    <th>Зарезервировано</th>
                                    <th>Активный резерв</th>
                                    <th>Срок</th>
                                    <th>Заказ</th>
                                </tr>
                                </thead>

                                <tbody>
                                {(items as Reservation[]).map((item) => (
                                    <tr key={item.id}>
                                        <td>
                                            <strong>
                                                {item.product_name ?? "Товар"}
                                            </strong>
                                            <small>{item.sku}</small>
                                        </td>

                                        <td>{item.warehouse_name}</td>
                                        <td>{item.quantity}</td>

                                        <td className="warehouse72-reserved">
                                            {item.active_qty}
                                        </td>

                                        <td>{when(item.expires_at)}</td>

                                        <td>
                                            {item.order_key ? (
                                                <Link href={`/admin/orders/${item.order_key}`}>
                                                    Открыть
                                                </Link>
                                            ) : "—"}
                                        </td>
                                    </tr>
                                ))}
                                </tbody>
                            </table>
                        )}

                        {tab === "documents" && (
                            <table className="warehouse72-table">
                                <thead>
                                <tr>
                                    <th>Документ</th>
                                    <th>Тип</th>
                                    <th>Склад</th>
                                    <th>Строк</th>
                                    <th>Дата</th>
                                    <th></th>
                                </tr>
                                </thead>

                                <tbody>
                                {(items as DocumentRow[]).map((item) => (
                                    <tr key={item.id}>
                                        <td>
                                            <strong>#{item.id}</strong>
                                            <small>{item.reference ?? "—"}</small>
                                        </td>

                                        <td>
                                            {documentLabels[item.kind] ?? item.kind}
                                        </td>

                                        <td>
                                            {overview?.warehouses.find(
                                                (warehouse) =>
                                                    warehouse.id === item.warehouse_id
                                            )?.name ?? "—"}
                                        </td>

                                        <td>{item.line_count}</td>
                                        <td>{when(item.created_at)}</td>

                                        <td>
                                            <button
                                                type="button"
                                                className="crm65-secondary"
                                                onClick={() => void openDocument(item.id)}
                                            >
                                                Открыть
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                </tbody>
                            </table>
                        )}
                    </div>

                    <div className="warehouse72-pagination">
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
                            disabled={loading || !activePage?.next_before_id}
                            onClick={() => {
                                if (!activePage?.next_before_id) return;
                                setPrevious((old) => [...old, cursor]);
                                setCursor(activePage.next_before_id);
                            }}
                        >
                            Далее
                        </button>
                    </div>
                </section>
            )}

            {tab === "documents" && detail && (
                <section className="crm65-panel warehouse72-panel">
                    <div className="warehouse72-row-head">
                        <h2>Документ #{detail.document_id}</h2>

                        <button
                            type="button"
                            className="crm65-secondary"
                            onClick={() => setDetail(null)}
                        >
                            Закрыть
                        </button>
                    </div>

                    <p>{documentLabels[detail.kind] ?? detail.kind}</p>

                    <p className="crm65-muted">
                        {detail.reference ?? "Без номера"}
                        {" · "}Сотрудник #{detail.actor_id}
                        {" · "}{when(detail.created_at)}
                    </p>

                    <details open>
                        <summary>Результаты проведения</summary>
                        <pre className="warehouse72-json">
                            {JSON.stringify(detail.result, null, 2)}
                        </pre>
                    </details>

                    <details>
                        <summary>Исходные параметры документа</summary>
                        <pre className="warehouse72-json">
                            {JSON.stringify(detail.request, null, 2)}
                        </pre>
                    </details>
                </section>
            )}
        </div>
    );
}