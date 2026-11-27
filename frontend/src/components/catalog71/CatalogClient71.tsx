"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { crm, CrmError } from "@/lib/crm65/client";
import Directories71 from "./Directories71";
import { catalogSlug71 } from "./catalogSlug71";
import LiveSearch from "@/components/crm65/LiveSearch";
import ProductEditor71, {
    Panel,
    submitForm,
    textValue,
    type Lookups,
    type Product,
} from "./ProductEditor71";

type ProductRow = {
    id: number;
    name: string | null;
    slug: string | null;
    brand_name: string | null;
    status: Product["status"];
    variant_count: number;
    revision: number;
};

type ProductPage = {
    items: ProductRow[];
    next_before_id: number | null;
};

const statuses = {
    draft: "Черновик",
    active: "Активный",
    archived: "Архивный",
};

export default function CatalogClient71() {
    const router = useRouter();

    const [tab, setTab] = useState<"products" | "directories">("products");

    const [lookups, setLookups] = useState<Lookups | null>(null);
    const [data, setData] = useState<ProductPage | null>(null);
    const [product, setProduct] = useState<Product | null>(null);
    const [editorDirty, setEditorDirty] = useState(false);
    const [editorReset, setEditorReset] = useState(0);

    const [query, setQuery] = useState("");
    const [draftQuery, setDraftQuery] = useState("");
    const [newName, setNewName] = useState("");
    const [status, setStatus] = useState("all");
    const [cursor, setCursor] = useState<number | null>(null);
    const [previous, setPrevious] = useState<Array<number | null>>([]);
    const [refresh, setRefresh] = useState(0);

    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const busyRef = useRef(false);
    const selectedId = useRef<number | null>(null);

    const fail = useCallback((caught: unknown) => {
        if (caught instanceof CrmError && caught.status === 401) {
            router.replace("/admin/login");
            return;
        }

        setError(
            caught instanceof Error
                ? caught.message : "Ошибка каталога"
        );
    }, [router]);

    const loadLookups = useCallback(async () => {
        setLookups(await crm<Lookups>("catalog/lookups"));
    }, []);

    useEffect(() => {
        let live = true;

        crm<Lookups>("catalog/lookups")
            .then((result) => {
                if (live) setLookups(result);
            })
            .catch((caught) => {
                if (live) fail(caught);
            });

        return () => { live = false; };
    }, [fail]);

    useEffect(() => {
        let live = true;

        setLoading(true);

        const params = new URLSearchParams({
            status,
            limit: "25",
        });

        if (query !== "") params.set("q", query);
        if (cursor !== null) params.set("before_id", String(cursor));

        crm<ProductPage>(`catalog/products?${params.toString()}`)
            .then((response) => {
                if (live) setData(response);
            })
            .catch((caught) => {
                if (live) {
                    setData(null);
                    fail(caught);
                }
            })
            .finally(() => {
                if (live) setLoading(false);
            });

        return () => { live = false; };
    }, [query, status, cursor, refresh, fail]);

    function reloadList() {
        setRefresh((value) => value + 1);
    }

    async function execute(callback: () => Promise<void>) {
        if (busyRef.current) return;

        busyRef.current = true;
        setBusy(true);
        setError(null);
        setNotice(null);

        try {
            await callback();
        } catch (caught) {
            fail(caught);
        } finally {
            busyRef.current = false;
            setBusy(false);
        }
    }

    async function openProduct(id: number) {
        if (editorDirty) return;
        await execute(async () => {
            const result = await crm<Product>(
                `catalog/products/${id}`
            );

            selectedId.current = result.id;
            setProduct(result);
        });
    }

    async function createProduct(form: FormData) {
        if (editorDirty) return;
        await execute(async () => {
            const result = await crm<Product>("catalog/products", {
                method: "POST",
                body: {
                    name: textValue(form, "name"),
                },
            });

            selectedId.current = result.id;
            setProduct(result);
            setNotice("Черновик товара создан.");
            setNewName("");

            setQuery("");
            setDraftQuery("");
            setStatus("all");
            setCursor(null);
            setPrevious([]);
            reloadList();
        });
    }

    async function changeProduct(
        action: string,
        values: Record<string, unknown>
    ) {
        const current = product;

        if (!current || selectedId.current !== current.id) return;

        await execute(async () => {
            const updated = await crm<Product>(
                `catalog/products/${current.id}/change`,
                {
                    method: "POST",
                    body: {
                        action,
                        expected_revision: current.revision,
                        ...values,
                    },
                }
            );

            if (selectedId.current === current.id) {
                setProduct(updated);
            }

            setNotice("Изменения сохранены.");
            reloadList();
        });
    }

    async function saveDirectory(
        kind: "brand" | "category",
        values: Record<string, unknown>
    ) {
        await execute(async () => {
            await crm(`catalog/directories/${kind}`, {
                method: "POST",
                body: values,
            });

            await loadLookups();
            reloadList();

            setNotice("Справочник сохранён.");
        });
    }

    return (
        <div className="catalog71-page">

            <div className="crm65-pagehead">
                <div>
                    <p className="crm65-overline">
                        COMMERCE / КАТАЛОГ
                    </p>

                    <h1>Управление каталогом</h1>

                    <p className="crm65-muted">
                        Товары, варианты, характеристики и справочники.
                    </p>
                </div>

                <button
                    type="button"
                    className="crm65-secondary"
                    disabled={busy || loading || editorDirty}
                    onClick={() => void execute(async () => {
                        await loadLookups();

                        if (selectedId.current !== null) {
                            setProduct(await crm<Product>(
                                `catalog/products/${selectedId.current}`
                            ));
                        }

                        reloadList();
                    })}
                >
                    ↻ Обновить
                </button>
            </div>

            {error && <p className="crm65-error" role="alert">{error}</p>}

            {notice && (
                <p className="catalog71-success" role="status">
                    {notice}
                </p>
            )}

            <div className="catalog71-tabs">
                <button
                    type="button"
                    disabled={busy}
                    className={tab === "products" ? "active" : ""}
                    onClick={() => setTab("products")}
                >
                    Товары
                </button>

                <button
                    type="button"
                    disabled={busy || editorDirty}
                    className={tab === "directories" ? "active" : ""}
                    onClick={() => setTab("directories")}
                >
                    Бренды и категории
                </button>
            </div>

            {tab === "directories" ? (
                lookups
                    ? <Directories71 lookups={lookups}
                                     busy={busy} save={saveDirectory}/>
                    : <p className="crm65-muted">Загружаем справочники...</p>
            ) : (
                <div className="catalog71-layout">
                    <div className="catalog71-list-column">

                        <Panel title="Создать товар">
                            <form
                                className="catalog71-form"
                                onSubmit={(event) => submitForm(event, (form) =>
                                    void createProduct(form)
                                )}
                            >
                                <label>
                                    Название UK
                                    <input
                                        name="name"
                                        required
                                        maxLength={255}
                                        value={newName}
                                        onChange={(event) =>
                                            setNewName(event.target.value)
                                        }
                                    />
                                </label>

                                <label>
                                    Slug — автоматически
                                    <input
                                        readOnly
                                        className="catalog71-generated"
                                        value={catalogSlug71(newName)}
                                        placeholder="Формируется из названия"
                                    />
                                </label>

                                <button
                                    className="crm65-primary"
                                    disabled={busy || editorDirty}
                                >
                                    Создать черновик
                                </button>
                            </form>
                        </Panel>

                        <Panel title="Товары">
                            <div className="catalog71-form">
                                <label>
                                    Поиск
                                    <LiveSearch
                                        value={draftQuery}
                                        applied={query}
                                        onChange={setDraftQuery}
                                        placeholder="Название, бренд или SKU"
                                        onSearch={(value) => {
                                            setQuery(value);
                                            setCursor(null);
                                            setPrevious([]);
                                            reloadList();
                                        }}
                                    />
                                </label>

                                <label>
                                    Статус
                                    <select
                                        name="status"
                                        value={status}
                                        onChange={(event) => {
                                            setStatus(event.target.value);
                                            setCursor(null);
                                            setPrevious([]);
                                        }}
                                    >
                                        <option value="all">Все</option>
                                        <option value="draft">Черновики</option>
                                        <option value="active">Активные</option>
                                        <option value="archived">Архивные</option>
                                    </select>
                                </label>
                            </div>

                            {loading && (
                                <p className="crm65-muted">Загружаем каталог...</p>
                            )}

                            {!loading && data?.items.length === 0 && (
                                <p className="crm65-empty">Товаров нет.</p>
                            )}

                            <div className="catalog71-product-list">
                                {data?.items.map((item) => (
                                    <button
                                        type="button"
                                        key={item.id}
                                        className={product?.id === item.id ? "active" : ""}
                                        disabled={busy || loading || editorDirty}
                                        onClick={() => void openProduct(item.id)}
                                    >
                                        <strong>
                                            {item.name ?? `Товар #${item.id}`}
                                        </strong>

                                        <small>
                                            {item.brand_name ?? "Без бренда"}
                                            {" · "}{item.variant_count} SKU
                                        </small>

                                        <span className={`catalog71-state ${item.status}`}>
                                            {statuses[item.status]}
                                        </span>
                                    </button>
                                ))}
                            </div>

                            <div className="catalog71-pagination">
                                <button
                                    type="button"
                                    disabled={busy || loading || previous.length === 0}
                                    onClick={() => {
                                        setCursor(previous[previous.length - 1]);
                                        setPrevious((old) => old.slice(0, -1));
                                    }}
                                >
                                    Назад
                                </button>

                                <button
                                    type="button"
                                    disabled={busy || loading || !data?.next_before_id}
                                    onClick={() => {
                                        if (!data?.next_before_id) return;
                                        setPrevious((old) => [...old, cursor]);
                                        setCursor(data.next_before_id);
                                    }}
                                >
                                    Далее
                                </button>
                            </div>
                        </Panel>
                    </div>

                    <div className="catalog71-detail-column">
                        {product && lookups ? (
                            <ProductEditor71
                                key={`${product.id}-${product.revision}-${editorReset}`}
                                product={product}
                                lookups={lookups}
                                busy={busy}
                                change={changeProduct}
                                onDirtyChange={setEditorDirty}
                                onDiscard={() => {
                                    setEditorDirty(false);
                                    setEditorReset((value) => value + 1);
                                }}
                            />
                        ) : (
                            <Panel title="Редактор товара">
                                <p className="crm65-muted">
                                    Выберите существующий товар или создайте
                                    новый черновик в левой колонке.
                                </p>
                            </Panel>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}