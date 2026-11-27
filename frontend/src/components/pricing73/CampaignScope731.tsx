"use client";

import { useState } from "react";
import { crm } from "@/lib/crm65/client";

import type {
    Page,
    ScopeOption,
    ScopeType,
} from "./types";

export default function CampaignScope731({
                                             scope,
                                             selected,
                                             disabled,
                                             onChange,
                                         }: {
    scope: Exclude<ScopeType, "all">;
    selected: ScopeOption[];
    disabled: boolean;
    onChange: (items: ScopeOption[]) => void;
}) {
    const [query, setQuery] = useState("");
    const [data, setData] = useState<Page<ScopeOption> | null>(null);

    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function search(before: number | null = null) {
        if (disabled || loading) return;

        setLoading(true);
        setError(null);

        try {
            const params = new URLSearchParams({
                scope_type: scope,
                q: query.trim(),
                limit: "30",
            });

            if (before !== null) {
                params.set("before_id", String(before));
            }

            setData(await crm<Page<ScopeOption>>(
                `pricing/scopes?${params}`
            ));
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : "Не удалось выполнить поиск"
            );
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="campaign731-picker">
            <div className="pricing73-actions">
                <input
                    value={query}
                    maxLength={64}
                    disabled={disabled || loading}
                    placeholder="Поиск по названию или SKU"
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
                    disabled={disabled || loading}
                    onClick={() => void search()}
                >
                    {loading ? "Поиск..." : "Найти"}
                </button>
            </div>

            <div className="campaign731-selected">
                {selected.map((item) => (
                    <button
                        type="button"
                        key={item.id}
                        disabled={disabled}
                        onClick={() => onChange(
                            selected.filter(
                                (entry) => entry.id !== item.id
                            )
                        )}
                    >
                        {item.name ?? `#${item.id}`}
                        {" · ×"}
                    </button>
                ))}
            </div>

            <small className="crm65-muted">
                Выбрано: {selected.length}/500
            </small>

            {data && (
                <div className="campaign731-search-results">
                    {data.items.map((item) => {
                        const exists = selected.some(
                            (entry) => entry.id === item.id
                        );

                        return (
                            <button
                                type="button"
                                key={item.id}
                                disabled={
                                    disabled
                                    || exists
                                    || selected.length >= 500
                                }
                                onClick={() => onChange([
                                    ...selected,
                                    item,
                                ])}
                            >
                                <span>
                                    {item.name ?? "Без названия"}
                                    <small> #{item.id}</small>
                                </span>

                                <strong>
                                    {exists ? "✓" : "+"}
                                </strong>
                            </button>
                        );
                    })}

                    {!data.items.length && (
                        <p className="crm65-muted">
                            Ничего не найдено.
                        </p>
                    )}

                    {data.next_before_id !== null && (
                        <button
                            type="button"
                            disabled={disabled || loading}
                            onClick={() => void search(
                                data.next_before_id
                            )}
                        >
                            Следующая страница
                        </button>
                    )}
                </div>
            )}

            {error && <p className="crm65-error">{error}</p>}
        </div>
    );
}