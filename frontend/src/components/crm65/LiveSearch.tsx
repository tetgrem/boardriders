"use client";

import { useEffect, useRef } from "react";

type Props = {
    value: string;
    applied: string;
    onChange: (value: string) => void;
    onSearch: (value: string) => void;
    placeholder?: string;
    maxLength?: number;
    disabled?: boolean;
    delay?: number;
};

export default function LiveSearch({
                                       value,
                                       applied,
                                       onChange,
                                       onSearch,
                                       placeholder = "Поиск...",
                                       maxLength = 64,
                                       disabled = false,
                                       delay = 400,
                                   }: Props) {
    const inputRef = useRef<HTMLInputElement>(null);
    const searchRef = useRef(onSearch);

    useEffect(() => {
        searchRef.current = onSearch;
    }, [onSearch]);

    useEffect(() => {
        const next = value.trim();

        if (disabled || next === applied) return;

        const timer = window.setTimeout(() => {
            searchRef.current(next);
        }, next === "" ? 0 : delay);

        return () => window.clearTimeout(timer);
    }, [value, applied, disabled, delay]);

    function clear() {
        if (disabled) return;

        onChange("");
        onSearch("");

        inputRef.current?.focus();
    }

    return (
        <span className="crm-live-search">
            <input
                ref={inputRef}
                type="search"
                value={value}
                maxLength={maxLength}
                placeholder={placeholder}
                aria-label={placeholder}
                autoComplete="off"
                spellCheck={false}
                disabled={disabled}
                onChange={(event) => {
                    event.stopPropagation();

                    const next = event.target.value;

                    onChange(next);

                    if (next.trim() === "") {
                        onSearch("");
                    }
                }}
                onKeyDown={(event) => {
                    if (event.nativeEvent.isComposing) return;

                    if (event.key === "Enter") {
                        event.preventDefault();
                        event.stopPropagation();

                        if (value.trim() !== applied) {
                            onSearch(value.trim());
                        }
                    }

                    if (event.key === "Escape") {
                        event.preventDefault();
                        clear();
                    }
                }}
            />

            {value !== "" && (
                <button
                    type="button"
                    className="crm-live-search-clear"
                    title="Очистить поиск"
                    aria-label="Очистить поиск"
                    disabled={disabled}
                    onClick={(event) => {
                        event.preventDefault();
                        clear();
                    }}
                >
                    <svg
                        width="18"
                        height="18"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinecap="round"
                        aria-hidden="true"
                    >
                        <path d="M6 6L18 18"/>
                        <path d="M18 6L6 18"/>
                    </svg>
                </button>
            )}
        </span>
    );
}