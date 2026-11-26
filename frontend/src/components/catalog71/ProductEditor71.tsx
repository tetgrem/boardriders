"use client";

import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import { crm, when } from "@/lib/crm65/client";
import { catalogSlug71 } from "./catalogSlug71";

export type Translation = {
    locale: string;
    name: string;
    slug: string;
    short_description: string | null;
    description: string | null;
    seo_title: string | null;
    seo_description: string | null;
};

export type DirectoryEntry = {
    id: number;
    name: string | null;
    slug: string | null;
    is_active: boolean;
    catalog_revision: number;
    parent_id?: number | null;
    sort_order?: number;
    description?: string | null;
    seo_title?: string | null;
    seo_description?: string | null;
};

export type Attribute = {
    id: number;
    name: string;
    code: string;
    type: "select" | "text" | "number" | "boolean";
    is_variant: boolean;
    options: Array<{
        id: number;
        code: string;
        name: string | null;
    }>;
};

export type Lookups = {
    brands: DirectoryEntry[];
    categories: DirectoryEntry[];
    languages: Array<{
        code: string;
        native_name: string;
        is_enabled: boolean;
    }>;
    attributes: Attribute[];
};

type Variant = {
    id: number;
    sku: string;
    barcode: string | null;
    is_active: boolean;
    sort_order: number;
    weight_grams: number | null;
    options: Array<{
        attribute_id: number;
        option_id: number;
    }>;
};

type ProductImage = {
    id: number;
    path: string;
    url: string;
    alt_uk: string | null;
    alt_ru: string | null;
    is_primary: boolean;
    sort_order: number;
};

export type Product = {
    id: number;
    brand_id: number | null;
    status: "draft" | "active" | "archived";
    revision: number;
    translations: Translation[];
    categories: Array<{
        category_id: number;
        is_primary: boolean;
    }>;
    attributes: Array<{
        attribute_id: number;
        option_id: number | null;
        value_text: string | null;
        value_number: string | null;
        value_boolean: boolean | null;
    }>;
    variants: Variant[];
    images: ProductImage[];
};

export type AuditEvent = {
    revision: number;
    actor_id: number;
    action: string;
    payload_json: string;
    created_at: string;
};

export function textValue(form: FormData, key: string) {
    return String(form.get(key) ?? "").trim();
}

export function nullableText(form: FormData, key: string) {
    return textValue(form, key) || null;
}

export function nullableNumber(form: FormData, key: string) {
    const value = textValue(form, key);
    return value === "" ? null : Number(value);
}

export function submitForm(
    event: FormEvent<HTMLFormElement>,
    callback: (form: FormData) => void
) {
    event.preventDefault();
    callback(new FormData(event.currentTarget));
}

export function Panel({
                          title, children,
                      }: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="crm65-panel catalog71-panel">
            <h2>{title}</h2>
            {children}
        </section>
    );
}

export function AuditList({ items }: { items: AuditEvent[] }) {
    return (
        <div className="catalog71-audit">
            {items.length === 0 && <p>Записей пока нет.</p>}

            {items.map((item) => (
                <details key={item.revision}>
                    <summary>
                        #{item.revision} · {item.action}
                        {" · "}{when(item.created_at)}
                    </summary>

                    <small>Сотрудник #{item.actor_id}</small>

                    <pre>{item.payload_json}</pre>
                </details>
            ))}
        </div>
    );
}

type Change71 = {
    action: string;
    data: Record<string, unknown>;
};

function TranslationFields71({
                                 locale,
                                 label,
                                 translation,
                             }: {
    locale: string;
    label: string;
    translation?: Translation;
}) {
    const [name, setName] = useState(translation?.name ?? "");

    return (
        <div className="catalog71-form">
            <h3>{label}</h3>

            <label>
                Название
                <input
                    name={`${locale}_name`}
                    value={name}
                    required={locale === "uk"}
                    maxLength={255}
                    onChange={(event) => setName(event.target.value)}
                />
            </label>

            <label>
                Slug
                <input
                    readOnly
                    className="catalog71-generated"
                    value={translation?.slug || catalogSlug71(name)}
                    placeholder="Формируется автоматически"
                />
            </label>

            <label>
                Краткое описание
                <textarea
                    name={`${locale}_short`}
                    rows={3}
                    maxLength={2000}
                    defaultValue={translation?.short_description ?? ""}
                />
            </label>

            <label>
                Полное описание
                <textarea
                    name={`${locale}_description`}
                    rows={7}
                    maxLength={30000}
                    defaultValue={translation?.description ?? ""}
                />
            </label>

            <label>
                SEO title
                <input
                    name={`${locale}_seo_title`}
                    maxLength={255}
                    defaultValue={translation?.seo_title ?? ""}
                />
            </label>

            <label>
                SEO description
                <textarea
                    name={`${locale}_seo_description`}
                    rows={3}
                    maxLength={320}
                    defaultValue={translation?.seo_description ?? ""}
                />
            </label>
        </div>
    );
}

function fileBase6471(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onerror = () => reject(
            new Error("Не удалось прочитать фотографию")
        );

        reader.onload = () => {
            const value = String(reader.result);
            resolve(value.slice(value.indexOf(",") + 1));
        };

        reader.readAsDataURL(file);
    });
}

function equal71(first: unknown, second: unknown): boolean {
    return JSON.stringify(first) === JSON.stringify(second);
}

export default function ProductEditor71({
                                            product,
                                            lookups,
                                            busy,
                                            change,
                                            onDirtyChange,
                                            onDiscard,
                                        }: {
    product: Product;
    lookups: Lookups;
    busy: boolean;
    change: (action: string, data: Record<string, unknown>) => Promise<void>;
    onDirtyChange: (value: boolean) => void;
    onDiscard: () => void;
}) {
    const [locale, setLocale] = useState("uk");
    const [preparing, setPreparing] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [history, setHistory] = useState<AuditEvent[] | null>(null);

    const [newVariants, setNewVariants] = useState<number[]>([]);
    const [newImages, setNewImages] = useState<number[]>([]);

    const [nextVariant, setNextVariant] = useState(1);
    const [nextImage, setNextImage] = useState(1);
    const locked = busy || preparing;

    useEffect(() => {
        onDirtyChange(dirty);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        return () => onDirtyChange(false);
    }, [onDirtyChange]);

    useEffect(() => {
        if (!dirty) return;

        function beforeUnload(event: BeforeUnloadEvent) {
            event.preventDefault();
            event.returnValue = "";
        }

        function handleLink(event: MouseEvent) {
            if (!(event.target instanceof Element)) return;

            const link = event.target.closest("a[href]");

            if (!link) return;

            if (!window.confirm(
                "Есть несохранённые изменения. Покинуть карточку товара?"
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
    }, [dirty]);

    const languages = lookups.languages.filter(
        (item) => ["uk", "ru", "en"].includes(item.code)
    );

    const regularAttributes = lookups.attributes.filter(
        (item) => !item.is_variant
    );

    const variantAttributes = lookups.attributes.filter(
        (item) => item.is_variant
    );

    function markDirty() {
        setDirty(true);
        setError(null);
    }

    function addVariant() {
        setNewVariants((old) => [...old, nextVariant]);
        setNextVariant((old) => old + 1);
        markDirty();
    }

    function addImage() {
        setNewImages((old) => [...old, nextImage]);
        setNextImage((old) => old + 1);
        markDirty();
    }

    async function saveAll(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (locked) return;

        const form = new FormData(event.currentTarget);
        const changes: Change71[] = [];

        setPreparing(true);
        setError(null);

        try {
            // Brand and categories.
            const categories = form.getAll("categories")
                .map(Number)
                .sort((a, b) => a - b);

            const oldCategories = product.categories
                .map((item) => item.category_id)
                .sort((a, b) => a - b);

            const details = {
                brand_id: nullableNumber(form, "brand_id"),
                category_ids: categories,
                primary_category_id: nullableNumber(form, "primary"),
            };

            const oldDetails = {
                brand_id: product.brand_id,
                category_ids: oldCategories,
                primary_category_id: product.categories.find(
                    (item) => item.is_primary
                )?.category_id ?? null,
            };

            if (!equal71(details, oldDetails)) {
                changes.push({
                    action: "details",
                    data: details,
                });
            }

            // Translations and SEO.
            for (const language of languages) {
                const code = language.code;

                const old = product.translations.find(
                    (item) => item.locale === code
                );

                const values = {
                    name: textValue(form, `${code}_name`),
                    short_description: nullableText(form, `${code}_short`),
                    description: nullableText(form, `${code}_description`),
                    seo_title: nullableText(form, `${code}_seo_title`),
                    seo_description: nullableText(
                        form,
                        `${code}_seo_description`
                    ),
                };

                if (!old && Object.values(values).every(
                    (value) => value === null || value === ""
                )) {
                    continue;
                }

                if (!values.name) {
                    throw new Error(
                        `Введите название для языка ${language.native_name}`
                    );
                }

                const previous = {
                    name: old?.name ?? "",
                    short_description: old?.short_description ?? null,
                    description: old?.description ?? null,
                    seo_title: old?.seo_title ?? null,
                    seo_description: old?.seo_description ?? null,
                };

                if (!equal71(values, previous)) {
                    changes.push({
                        action: "translation",
                        data: {
                            locale: code,
                            ...values,
                        },
                    });
                }
            }

            // Product attributes.
            for (const attribute of regularAttributes) {
                const old = product.attributes.find(
                    (item) => item.attribute_id === attribute.id
                );

                const raw = textValue(
                    form,
                    `attr_${attribute.id}`
                );

                const oldRaw = old
                    ? String(
                        old.option_id
                        ?? old.value_text
                        ?? old.value_number
                        ?? old.value_boolean
                        ?? ""
                    )
                    : "";

                if (raw === oldRaw) continue;

                if (raw === "") {
                    if (old) {
                        changes.push({
                            action: "attribute_remove",
                            data: {
                                attribute_id: attribute.id,
                            },
                        });
                    }

                    continue;
                }

                const value = attribute.type === "select"
                    ? Number(raw)
                    : attribute.type === "boolean"
                        ? raw === "true"
                        : raw;

                changes.push({
                    action: "attribute",
                    data: {
                        attribute_id: attribute.id,
                        value,
                    },
                });
            }

            // Existing variants.
            for (const variant of product.variants) {
                const prefix = `v_${variant.id}`;

                const values = {
                    barcode: nullableText(form, `${prefix}_barcode`),
                    weight_grams: nullableNumber(form, `${prefix}_weight`),
                    sort_order: Number(
                        textValue(form, `${prefix}_sort`)
                    ),
                    is_active: form.has(`${prefix}_active`),
                };

                const old = {
                    barcode: variant.barcode,
                    weight_grams: variant.weight_grams,
                    sort_order: variant.sort_order,
                    is_active: variant.is_active,
                };

                if (!equal71(values, old)) {
                    changes.push({
                        action: "variant_update",
                        data: {
                            variant_id: variant.id,
                            ...values,
                        },
                    });
                }
            }

            // New variants: SKU will be generated by Laravel.
            for (const row of newVariants) {
                const prefix = `new_v_${row}`;

                changes.push({
                    action: "variant_create",
                    data: {
                        barcode: nullableText(form, `${prefix}_barcode`),
                        weight_grams: nullableNumber(form, `${prefix}_weight`),
                        option_ids: variantAttributes
                            .map((attribute) => nullableNumber(
                                form,
                                `${prefix}_${attribute.id}`
                            ))
                            .filter((value): value is number => value !== null),
                    },
                });
            }

            // Existing photographs.
            const primary = nullableNumber(form, "primary_image");

            for (const image of product.images) {
                const prefix = `img_${image.id}`;

                if (form.has(`${prefix}_remove`)) {
                    continue;
                }

                const values = {
                    alt_uk: nullableText(form, `${prefix}_uk`),
                    alt_ru: nullableText(form, `${prefix}_ru`),
                    sort_order: Number(
                        textValue(form, `${prefix}_sort`)
                    ),
                    is_primary: primary === image.id,
                };

                const old = {
                    alt_uk: image.alt_uk,
                    alt_ru: image.alt_ru,
                    sort_order: image.sort_order,
                    is_primary: image.is_primary,
                };

                if (!equal71(values, old)) {
                    changes.push({
                        action: "image_update",
                        data: {
                            image_id: image.id,
                            ...values,
                        },
                    });
                }
            }

            for (const image of product.images) {
                if (form.has(`img_${image.id}_remove`)) {
                    changes.push({
                        action: "image_remove",
                        data: {
                            image_id: image.id,
                        },
                    });
                }
            }

            // New photographs.
            let totalBytes = 0;

            for (const row of newImages) {
                const file = form.get(`new_img_${row}_file`);

                if (!(file instanceof File) || file.size === 0) {
                    throw new Error("Выберите фотографию или уберите пустую строку");
                }

                if (file.size > 2 * 1024 * 1024) {
                    throw new Error("Размер одной фотографии — не более 2 МБ");
                }

                totalBytes += file.size;

                if (totalBytes > 4 * 1024 * 1024) {
                    throw new Error(
                        "За одно сохранение можно загрузить до 4 МБ фотографий"
                    );
                }

                changes.push({
                    action: "image_upload",
                    data: {
                        base64: await fileBase6471(file),
                        alt_uk: nullableText(form, `new_img_${row}_uk`),
                        alt_ru: nullableText(form, `new_img_${row}_ru`),
                    },
                });
            }

            // Publication or archiving is applied last.
            const status = textValue(form, "status");

            if (status !== product.status) {
                changes.push({
                    action: "status",
                    data: { status },
                });
            }

            if (changes.length === 0) {
                setDirty(false);
                return;
            }

            setHistory(null);

            await change("batch", { changes });

        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : "Не удалось сохранить товар"
            );
        } finally {
            setPreparing(false);
        }
    }

    async function loadHistory() {
        setError(null);

        try {
            setHistory(await crm<AuditEvent[]>(
                `catalog/products/${product.id}/history`
            ));
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : "Не удалось загрузить историю"
            );
        }
    }

    return (
        <form
            className="catalog71-editor catalog71-unified"
            onSubmit={(event) => void saveAll(event)}
            onChange={markDirty}
        >
            <fieldset disabled={locked} className="catalog71-fieldset">

                <Panel title={`Товар #${product.id}`}>
                    <p className="crm65-muted">
                        Ревизия: {product.revision}
                    </p>

                    <label>
                        Статус товара
                        <select name="status" defaultValue={product.status}>
                            <option value="draft">Черновик</option>
                            <option value="active">Опубликовать</option>
                            <option value="archived">Отправить в архив</option>
                        </select>
                    </label>

                    <p className="crm65-muted">
                        Новый статус применяется при общем сохранении.
                    </p>
                </Panel>

                <Panel title="Бренд и категории">
                    <label>
                        Бренд
                        <select name="brand_id" defaultValue={product.brand_id ?? ""}>
                            <option value="">Без бренда</option>

                            {lookups.brands.filter(
                                (item) => item.is_active || item.id === product.brand_id
                            ).map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>

                    <div className="catalog71-category-checks">
                        {lookups.categories.filter(
                            (item) => item.is_active
                                || product.categories.some(
                                    (category) => category.category_id === item.id
                                )
                        ).map((item) => (
                            <label
                                key={item.id}
                                className="catalog71-check"
                            >
                                <input
                                    type="checkbox"
                                    name="categories"
                                    value={item.id}
                                    defaultChecked={product.categories.some(
                                        (category) => category.category_id === item.id
                                    )}
                                />
                                {item.name ?? `#${item.id}`}
                            </label>
                        ))}
                    </div>

                    <label>
                        Основная категория
                        <select
                            name="primary"
                            defaultValue={product.categories.find(
                                (item) => item.is_primary
                            )?.category_id ?? ""}
                        >
                            <option value="">Не назначена</option>

                            {lookups.categories.filter(
                                (item) => item.is_active
                                    || product.categories.some(
                                        (category) => category.category_id === item.id
                                    )
                            ).map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name ?? `#${item.id}`}
                                </option>
                            ))}
                        </select>
                    </label>
                </Panel>

                <Panel title="Описание и SEO">
                    <div className="catalog71-tabs">
                        {languages.map((language) => (
                            <button
                                type="button"
                                key={language.code}
                                className={locale === language.code ? "active" : ""}
                                onClick={() => setLocale(language.code)}
                            >
                                {language.native_name}
                            </button>
                        ))}
                    </div>

                    {languages.map((language) => (
                        <div
                            key={language.code}
                            hidden={locale !== language.code}
                        >
                            <TranslationFields71
                                locale={language.code}
                                label={language.native_name}
                                translation={product.translations.find(
                                    (item) => item.locale === language.code
                                )}
                            />
                        </div>
                    ))}
                </Panel>

                <Panel title="Характеристики">
                    <div className="catalog71-grid">
                        {regularAttributes.map((attribute) => {
                            const old = product.attributes.find(
                                (item) => item.attribute_id === attribute.id
                            );

                            const value = String(
                                old?.option_id
                                ?? old?.value_text
                                ?? old?.value_number
                                ?? old?.value_boolean
                                ?? ""
                            );

                            return (
                                <label key={attribute.id}>
                                    {attribute.name}

                                    {attribute.type === "select" ? (
                                        <select
                                            name={`attr_${attribute.id}`}
                                            defaultValue={value}
                                        >
                                            <option value="">Не выбрано</option>

                                            {old?.option_id != null
                                                && !attribute.options.some(
                                                    (item) => item.id === old.option_id
                                                )
                                                && (
                                                    <option value={old.option_id}>
                                                        Неактивное значение #{old.option_id}
                                                    </option>
                                                )}

                                            {attribute.options.map((item) => (
                                                <option key={item.id} value={item.id}>
                                                    {item.name ?? item.code}
                                                </option>
                                            ))}
                                        </select>
                                    ) : attribute.type === "boolean" ? (
                                        <select
                                            name={`attr_${attribute.id}`}
                                            defaultValue={value}
                                        >
                                            <option value="">Не выбрано</option>
                                            <option value="true">Да</option>
                                            <option value="false">Нет</option>
                                        </select>
                                    ) : (
                                        <input
                                            name={`attr_${attribute.id}`}
                                            defaultValue={value}
                                            maxLength={2000}
                                        />
                                    )}
                                </label>
                            );
                        })}
                    </div>
                </Panel>

                <Panel title="Варианты товара">
                    {product.variants.map((variant) => (
                        <div
                            key={variant.id}
                            className="catalog71-variant catalog71-form"
                        >
                            <strong>{variant.sku}</strong>

                            <small className="crm65-muted">
                                {variant.options.map((item) => {
                                    const attribute = lookups.attributes.find(
                                        (a) => a.id === item.attribute_id
                                    );

                                    const option = attribute?.options.find(
                                        (o) => o.id === item.option_id
                                    );

                                    return `${attribute?.name ?? item.attribute_id}: ${
                                        option?.name ?? option?.code ?? item.option_id
                                    }`;
                                }).join(" · ") || "Без вариантных характеристик"}
                            </small>

                            <div className="catalog71-grid">
                                <label>
                                    Штрихкод
                                    <input
                                        name={`v_${variant.id}_barcode`}
                                        maxLength={100}
                                        defaultValue={variant.barcode ?? ""}
                                    />
                                </label>

                                <label>
                                    Вес, г
                                    <input
                                        name={`v_${variant.id}_weight`}
                                        type="number"
                                        min={1}
                                        max={1000000}
                                        defaultValue={variant.weight_grams ?? ""}
                                    />
                                </label>

                                <label>
                                    Сортировка
                                    <input
                                        name={`v_${variant.id}_sort`}
                                        type="number"
                                        min={0}
                                        max={1000000}
                                        defaultValue={variant.sort_order}
                                        required
                                    />
                                </label>
                            </div>

                            <label className="catalog71-check">
                                <input
                                    type="checkbox"
                                    name={`v_${variant.id}_active`}
                                    defaultChecked={variant.is_active}
                                />
                                Активный вариант
                            </label>
                        </div>
                    ))}

                    {newVariants.map((row, index) => (
                        <div
                            key={row}
                            className="catalog71-variant catalog71-form catalog71-pending"
                        >
                            <div className="catalog71-row-head">
                                <strong>Новый вариант {index + 1}</strong>

                                <button
                                    type="button"
                                    className="catalog71-danger"
                                    onClick={() => {
                                        setNewVariants((old) =>
                                            old.filter((item) => item !== row)
                                        );
                                        markDirty();
                                    }}
                                >
                                    Убрать
                                </button>
                            </div>

                            <label>
                                SKU
                                <input
                                    readOnly
                                    className="catalog71-generated"
                                    value="Создастся автоматически при сохранении"
                                />
                            </label>

                            <div className="catalog71-grid">
                                <label>
                                    Штрихкод
                                    <input
                                        name={`new_v_${row}_barcode`}
                                        maxLength={100}
                                    />
                                </label>

                                <label>
                                    Вес, г
                                    <input
                                        name={`new_v_${row}_weight`}
                                        type="number"
                                        min={1}
                                        max={1000000}
                                    />
                                </label>

                                {variantAttributes.map((attribute) => (
                                    <label key={attribute.id}>
                                        {attribute.name}
                                        <select name={`new_v_${row}_${attribute.id}`}>
                                            <option value="">Не используется</option>

                                            {attribute.options.map((item) => (
                                                <option key={item.id} value={item.id}>
                                                    {item.name ?? item.code}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                ))}
                            </div>

                            <small className="crm65-muted">
                                Вариант создаётся неактивным.
                            </small>
                        </div>
                    ))}

                    <button
                        type="button"
                        className="crm65-secondary"
                        onClick={addVariant}
                    >
                        + Добавить вариант
                    </button>
                </Panel>

                <Panel title="Фотографии">
                    <div className="catalog71-images">
                        {product.images.map((image) => (
                            <div
                                key={image.id}
                                className="catalog71-image catalog71-form"
                            >
                                {/* eslint-disable-next-line @next/next/no-img-element */}
                                <img
                                    src={image.url}
                                    alt={image.alt_uk ?? ""}
                                    loading="lazy"
                                />

                                <label>
                                    ALT UK
                                    <input
                                        name={`img_${image.id}_uk`}
                                        defaultValue={image.alt_uk ?? ""}
                                        maxLength={255}
                                    />
                                </label>

                                <label>
                                    ALT RU
                                    <input
                                        name={`img_${image.id}_ru`}
                                        defaultValue={image.alt_ru ?? ""}
                                        maxLength={255}
                                    />
                                </label>

                                <label>
                                    Сортировка
                                    <input
                                        name={`img_${image.id}_sort`}
                                        type="number"
                                        min={0}
                                        max={1000000}
                                        defaultValue={image.sort_order}
                                        required
                                    />
                                </label>

                                <label className="catalog71-check">
                                    <input
                                        type="radio"
                                        name="primary_image"
                                        value={image.id}
                                        defaultChecked={image.is_primary}
                                    />
                                    Главное фото
                                </label>

                                <label className="catalog71-check">
                                    <input
                                        type="checkbox"
                                        name={`img_${image.id}_remove`}
                                    />
                                    Убрать при сохранении
                                </label>
                            </div>
                        ))}

                        {newImages.map((row) => (
                            <div
                                key={row}
                                className="catalog71-image catalog71-form catalog71-pending"
                            >
                                <label>
                                    Новое фото
                                    <input
                                        type="file"
                                        name={`new_img_${row}_file`}
                                        accept="image/jpeg,image/png,image/webp"
                                        required
                                    />
                                </label>

                                <label>
                                    ALT UK
                                    <input
                                        name={`new_img_${row}_uk`}
                                        maxLength={255}
                                    />
                                </label>

                                <label>
                                    ALT RU
                                    <input
                                        name={`new_img_${row}_ru`}
                                        maxLength={255}
                                    />
                                </label>

                                <button
                                    type="button"
                                    className="catalog71-danger"
                                    onClick={() => {
                                        setNewImages((old) =>
                                            old.filter((item) => item !== row)
                                        );
                                        markDirty();
                                    }}
                                >
                                    Убрать
                                </button>
                            </div>
                        ))}
                    </div>

                    <button
                        type="button"
                        className="crm65-secondary"
                        onClick={addImage}
                    >
                        + Добавить фотографию
                    </button>
                </Panel>

                <Panel title="История изменений">
                    <button
                        type="button"
                        className="crm65-secondary"
                        onClick={() => void loadHistory()}
                    >
                        Показать историю
                    </button>

                    {history && <AuditList items={history}/>}
                </Panel>

            </fieldset>

            {error && (
                <p className="crm65-error" role="alert">
                    {error}
                </p>
            )}

            <div className="catalog71-savebar">
                <div>
                    <strong>
                        {dirty ? "Есть несохранённые изменения" : "Карточка товара"}
                    </strong>

                    <small>
                        Все разделы сохраняются одновременно
                    </small>
                </div>
                <button
                    type="button"
                    className="crm65-secondary"
                    disabled={locked || !dirty}
                    onClick={() => {
                        if (window.confirm(
                            "Отменить все несохранённые изменения?"
                        )) {
                            onDiscard();
                        }
                    }}
                >
                    Отменить
                </button>
                <button
                    type="submit"
                    className="crm65-primary"
                    disabled={locked || !dirty}
                >
                    {locked ? "Сохраняем..." : "Сохранить товар"}
                </button>
            </div>
        </form>
    );
}