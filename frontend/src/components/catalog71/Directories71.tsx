"use client";

import { useState } from "react";
import { crm } from "@/lib/crm65/client";
import {
    AuditList,
    Panel,
    nullableNumber,
    nullableText,
    submitForm,
    textValue,
    type AuditEvent,
    type Lookups,
} from "./ProductEditor71";

type Kind = "brand" | "category";

export default function Directories71({
                                          lookups,
                                          busy,
                                          save,
                                      }: {
    lookups: Lookups;
    busy: boolean;
    save: (kind: Kind, data: Record<string, unknown>) => Promise<void>;
}) {
    const [kind, setKind] = useState<Kind>("brand");
    const [selected, setSelected] = useState("");
    const [history, setHistory] = useState<AuditEvent[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    const entries = kind === "brand"
        ? lookups.brands : lookups.categories;

    const entry = entries.find(
        (item) => String(item.id) === selected
    );

    return (
        <div className="catalog71-editor">
            <Panel title="Справочники каталога">
                <div className="catalog71-tabs">
                    <button
                        type="button"
                        disabled={busy}
                        className={kind === "brand" ? "active" : ""}
                        onClick={() => {
                            setKind("brand");
                            setSelected("");
                            setHistory(null);
                        }}
                    >
                        Бренды
                    </button>

                    <button
                        type="button"
                        disabled={busy}
                        className={kind === "category" ? "active" : ""}
                        onClick={() => {
                            setKind("category");
                            setSelected("");
                            setHistory(null);
                        }}
                    >
                        Категории
                    </button>
                </div>

                <label>
                    Запись
                    <select
                        value={selected}
                        disabled={busy}
                        onChange={(event) => {
                            setSelected(event.target.value);
                            setHistory(null);
                        }}
                    >
                        <option value="">Создать новую</option>

                        {entries.map((item) => (
                            <option key={item.id} value={item.id}>
                                #{item.id} · {item.name ?? "Без названия"}
                                {!item.is_active ? " (отключён)" : ""}
                            </option>
                        ))}
                    </select>
                </label>

                <form
                    key={`${kind}-${selected}-${entry?.catalog_revision ?? 0}`}
                    className="catalog71-form"
                    onSubmit={(event) => submitForm(event, (form) => {
                        setHistory(null);

                        void save(kind, {
                            id: entry?.id ?? null,
                            expected_revision: entry?.catalog_revision ?? 0,
                            name: textValue(form, "name"),
                            slug: textValue(form, "slug"),
                            is_active: form.has("active"),
                            parent_id: entry
                                ? entry.parent_id ?? null
                                : nullableNumber(form, "parent_id"),
                            sort_order: Number(textValue(form, "sort_order") || 0),
                            description: nullableText(form, "description"),
                            seo_title: nullableText(form, "seo_title"),
                            seo_description: nullableText(form, "seo_description"),
                        });
                    })}
                >
                    <label>
                        Название
                        <input name="name" required maxLength={200}
                               defaultValue={entry?.name ?? ""}/>
                    </label>

                    <label>
                        Slug
                        <input name="slug" required maxLength={200}
                               pattern="[a-z0-9]+(-[a-z0-9]+)*"
                               defaultValue={entry?.slug ?? ""}/>
                    </label>

                    {kind === "category" && <>
                        <label>
                            Родительская категория

                            <select
                                name="parent_id"
                                disabled={Boolean(entry)}
                                defaultValue={entry?.parent_id ?? ""}
                            >
                                <option value="">Корневая категория</option>

                                {lookups.categories.filter(
                                    (item) => item.is_active
                                        && item.id !== entry?.id
                                ).map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name ?? `#${item.id}`}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label>
                            Сортировка
                            <input type="number" name="sort_order" min={0}
                                   defaultValue={entry?.sort_order ?? 0}/>
                        </label>

                        <label>
                            Описание
                            <textarea name="description" rows={4} maxLength={10000}
                                      defaultValue={entry?.description ?? ""}/>
                        </label>

                        <label>
                            SEO title
                            <input name="seo_title" maxLength={255}
                                   defaultValue={entry?.seo_title ?? ""}/>
                        </label>

                        <label>
                            SEO description
                            <textarea name="seo_description" rows={3} maxLength={320}
                                      defaultValue={entry?.seo_description ?? ""}/>
                        </label>
                    </>}

                    <label className="catalog71-check">
                        <input name="active" type="checkbox"
                               defaultChecked={entry?.is_active ?? true}/>
                        Активная запись
                    </label>

                    <button className="crm65-primary" disabled={busy}>
                        {entry ? "Сохранить изменения" : "Создать"}
                    </button>
                </form>
            </Panel>

            {entry && (
                <Panel title="История справочника">
                    <button
                        type="button"
                        className="crm65-secondary"
                        disabled={busy}
                        onClick={async () => {
                            setError(null);

                            try {
                                setHistory(await crm<AuditEvent[]>(
                                    `catalog/directories/${kind}/${entry.id}/history`
                                ));
                            } catch (caught) {
                                setError(caught instanceof Error
                                    ? caught.message : "Ошибка истории");
                            }
                        }}
                    >
                        Показать историю
                    </button>

                    {error && <p className="crm65-error">{error}</p>}
                    {history && <AuditList items={history}/>}
                </Panel>
            )}
        </div>
    );
}