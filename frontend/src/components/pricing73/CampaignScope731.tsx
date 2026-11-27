"use client";

import { useEffect, useState } from "react";
import { crm } from "@/lib/crm65/client";
import LiveSearch from "@/components/crm65/LiveSearch";

import type {
    Page,
    ScopeOption,
    ScopeType,
} from "./types";

type Props = {
    scope: Exclude<ScopeType, "all">;
    selected: ScopeOption[];
    disabled: boolean;
    onChange: (items: ScopeOption[]) => void;
};

export default function CampaignScope731({
                                             scope,
                                             selected,
                                             disabled,
                                             onChange,
                                         }: Props) {
    const [query, setQuery] = useState("");

    const [appliedSearch, setAppliedSearch] =
        useState<string | null>(null);

    const [requestCursor, setRequestCursor] =
        useState<number | null>(null);

    const [data, setData] =
        useState<Page<ScopeOption> | null>(null);

    const [loading, setLoading] =
        useState(false);

    const [error, setError] =
        useState<string | null>(null);

    const [revision, setRevision] =
        useState(0);

    // ========================================================
    // LIVE SEARCH
    // ========================================================

    useEffect(() => {
        if (appliedSearch === null) return;

        let live = true;

        setLoading(true);
        setError(null);
        setData(null);

        const params = new URLSearchParams({
            scope_type: scope,
            limit: "30",
        });

        if (appliedSearch !== "") {
            params.set("q", appliedSearch);
        }

        if (requestCursor !== null) {
            params.set(
                "before_id",
                String(requestCursor)
            );
        }

        crm<Page<ScopeOption>>(
            `pricing/scopes?${params}`
        )
            .then((result) => {
                if (live) {
                    setData(result);
                }
            })
            .catch((caught) => {
                if (live) {
                    setError(
                        caught instanceof Error
                            ? caught.message
                            : "Не удалось выполнить поиск"
                    );
                }
            })
            .finally(() => {
                if (live) {
                    setLoading(false);
                }
            });

        return () => {
            live = false;
        };
    }, [
        scope,
        appliedSearch,
        requestCursor,
        revision,
    ]);

    // ========================================================
    // SEARCH CONTROLS
    // ========================================================

    function clearSearch() {
        setQuery("");
        setAppliedSearch(null);
        setRequestCursor(null);
        setData(null);
        setError(null);
        setLoading(false);
    }

    function applySearch(value: string) {
        if (value === "") {
            clearSearch();
            return;
        }

        setRequestCursor(null);
        setAppliedSearch(value);
        setRevision((old) => old + 1);
    }

    function showAll() {
        setQuery("");
        setRequestCursor(null);
        setAppliedSearch("");
        setError(null);
        setRevision((old) => old + 1);
    }

    function addItem(item: ScopeOption) {
        if (disabled || selected.length >= 500) {
            return;
        }

        if (
            selected.some(
                (entry) => entry.id === item.id
            )
        ) {
            return;
        }

        onChange([
            ...selected,
            item,
        ]);
    }

    function removeItem(id: number) {
        if (disabled) return;

        onChange(
            selected.filter(
                (entry) => entry.id !== id
            )
        );
    }

    // ========================================================
    // INTERFACE
    // ========================================================

    return (
        <div className="campaign731-picker">

            <div className="pricing73-actions">

                <LiveSearch
                    value={query}
                    applied={appliedSearch ?? ""}
                    onChange={setQuery}
                    disabled={disabled}
                    placeholder="Поиск по названию или SKU"
                    onSearch={applySearch}
                />

                <button
                    type="button"
                    className="crm65-secondary"
                    disabled={disabled || loading}
                    onClick={showAll}
                >
                    Показать все
                </button>

            </div>

            {/* Selected objects */}

            <div className="campaign731-selected">

                {selected.map((item) => (
                    <button
                        type="button"
                        key={item.id}
                        disabled={disabled}
                        onClick={() => removeItem(item.id)}
                        title="Убрать из выбранных"
                    >
                        {item.name ?? `#${item.id}`}
                        {" · ×"}
                    </button>
                ))}

            </div>

            <small className="crm65-muted">
                Выбрано: {selected.length}/500
            </small>

            {loading && (
                <small className="crm65-muted">
                    Поиск...
                </small>
            )}

            {/* Search results */}

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
                                    || loading
                                    || exists
                                    || selected.length >= 500
                                }
                                onClick={() => addItem(item)}
                            >
                                <span>
                                    {item.name ?? "Без названия"}

                                    <small>
                                        {" #"}
                                        {item.id}
                                    </small>
                                </span>

                                <strong>
                                    {exists ? "✓" : "+"}
                                </strong>
                            </button>
                        );
                    })}

                    {data.items.length === 0 && (
                        <p className="crm65-muted">
                            Ничего не найдено.
                        </p>
                    )}

                    {requestCursor !== null && (
                        <button
                            type="button"
                            disabled={disabled || loading}
                            onClick={() => {
                                setRequestCursor(null);
                            }}
                        >
                            В начало списка
                        </button>
                    )}

                    {data.next_before_id !== null && (
                        <button
                            type="button"
                            disabled={disabled || loading}
                            onClick={() => {
                                setRequestCursor(
                                    data.next_before_id
                                );
                            }}
                        >
                            Следующая страница
                        </button>
                    )}

                </div>
            )}

            {error && (
                <p
                    className="crm65-error"
                    role="alert"
                >
                    {error}
                </p>
            )}

        </div>
    );
}