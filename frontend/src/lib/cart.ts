"use client";

import { useSyncExternalStore } from "react";

// ========================================
// ТИПИ ДАНИХ
// ========================================

export type CartItem = {
    variantId: number;
    quantity: number;
};

type StoredCart = {
    version: 1;
    items: CartItem[];
};

// ========================================
// НАЛАШТУВАННЯ
// ========================================

const STORAGE_KEY = "boardriders.cart.v1";

const CHANGE_EVENT = "boardriders:cart-changed";

// Тимчасові обмеження для клієнтського кошика.
const MAX_QUANTITY = 99;
const MAX_ITEMS = 200;

const EMPTY_CART: CartItem[] = [];

// Кеш потрібний для React useSyncExternalStore.
let cachedRaw: string | null | undefined;
let cachedItems: CartItem[] = EMPTY_CART;

// ========================================
// ПЕРЕВІРКА ДАНИХ
// ========================================

function parseCart(raw: string | null): CartItem[] {
    if (!raw) return EMPTY_CART;

    try {
        const parsed: unknown = JSON.parse(raw);

        if (!parsed || typeof parsed !== "object") {
            return EMPTY_CART;
        }

        const data = parsed as Partial<StoredCart>;

        if (
            data.version !== 1 ||
            !Array.isArray(data.items)
        ) {
            return EMPTY_CART;
        }

        const items: CartItem[] = [];
        const seen = new Set<number>();

        for (const value of data.items.slice(0, MAX_ITEMS)) {
            if (!value || typeof value !== "object") {
                continue;
            }

            const variantId = value.variantId;
            const quantity = value.quantity;

            if (
                !Number.isSafeInteger(variantId) ||
                variantId <= 0 ||
                !Number.isSafeInteger(quantity) ||
                quantity < 1 ||
                quantity > MAX_QUANTITY
            ) {
                continue;
            }

            if (seen.has(variantId)) {
                continue;
            }

            seen.add(variantId);

            items.push({
                variantId,
                quantity,
            });
        }

        return items;
    } catch {
        return EMPTY_CART;
    }
}

// ========================================
// ЧИТАННЯ КОШИКА
// ========================================

function getSnapshot(): CartItem[] {
    if (typeof window === "undefined") {
        return EMPTY_CART;
    }

    let raw: string | null;

    try {
        raw = window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return EMPTY_CART;
    }

    if (raw === cachedRaw) {
        return cachedItems;
    }

    cachedRaw = raw;
    cachedItems = parseCart(raw);

    return cachedItems;
}

// Початковий стан при серверному рендерингу.
function getServerSnapshot(): CartItem[] {
    return EMPTY_CART;
}

// ========================================
// ПІДПИСКА НА ЗМІНИ
// ========================================

function subscribe(callback: () => void) {
    const onChange = () => {
        callback();
    };

    const onStorage = (event: StorageEvent) => {
        if (
            event.key === STORAGE_KEY ||
            event.key === null
        ) {
            callback();
        }
    };

    window.addEventListener(CHANGE_EVENT, onChange);

    window.addEventListener("storage", onStorage);

    return () => {
        window.removeEventListener(
            CHANGE_EVENT,
            onChange
        );

        window.removeEventListener(
            "storage",
            onStorage
        );
    };
}

// ========================================
// ЗАПИС КОШИКА
// ========================================

function saveCart(items: CartItem[]): boolean {
    if (typeof window === "undefined") {
        return false;
    }

    const data: StoredCart = {
        version: 1,
        items,
    };

    const raw = JSON.stringify(data);

    try {
        window.localStorage.setItem(STORAGE_KEY, raw);

        cachedRaw = raw;
        cachedItems = items;

        window.dispatchEvent(
            new Event(CHANGE_EVENT)
        );

        return true;
    } catch {
        return false;
    }
}

// ========================================
// ОТРИМАННЯ ТОВАРІВ
// ========================================

export function useCartItems(): CartItem[] {
    return useSyncExternalStore(
        subscribe,
        getSnapshot,
        getServerSnapshot
    );
}

// ========================================
// ЛІЧИЛЬНИК ТОВАРІВ
// ========================================

export function useCartCount(): number {
    const items = useCartItems();

    return items.reduce(
        (total, item) => total + item.quantity,
        0
    );
}

// ========================================
// ДОДАВАННЯ ТОВАРУ
// ========================================

export function addToCart(
    variantId: number,
    available: number
): boolean {
    if (
        !Number.isSafeInteger(variantId) ||
        variantId <= 0 ||
        !Number.isFinite(available)
    ) {
        return false;
    }

    const limit = Math.min(
        MAX_QUANTITY,
        Math.max(0, Math.floor(available))
    );

    if (limit < 1) {
        return false;
    }

    const current = getSnapshot();

    const next = current.map((item) => ({
        ...item,
    }));

    const existing = next.find(
        (item) => item.variantId === variantId
    );

    if (existing) {
        // Не дозволяємо перевищити відомий залишок.
        if (existing.quantity >= limit) {
            return false;
        }

        existing.quantity += 1;
    } else {
        if (next.length >= MAX_ITEMS) {
            return false;
        }

        next.push({
            variantId,
            quantity: 1,
        });
    }

    return saveCart(next);
}

// ========================================
// ЗМІНА КІЛЬКОСТІ
// ========================================

export function setCartQuantity(
    variantId: number,
    quantity: number
): boolean {
    if (
        !Number.isSafeInteger(variantId) ||
        variantId <= 0 ||
        !Number.isSafeInteger(quantity) ||
        quantity < 1 ||
        quantity > 99
    ) {
        return false;
    }

    const current = getSnapshot();

    const exists = current.some(
        (item) => item.variantId === variantId
    );

    if (!exists) {
        return false;
    }

    const next = current.map((item) =>
        item.variantId === variantId
            ? {
                ...item,
                quantity,
            }
            : item
    );

    return saveCart(next);
}

// ========================================
// ВИДАЛЕННЯ ТОВАРУ
// ========================================

export function removeFromCart(
    variantId: number
): boolean {
    if (
        !Number.isSafeInteger(variantId) ||
        variantId <= 0
    ) {
        return false;
    }

    const current = getSnapshot();

    const next = current.filter(
        (item) => item.variantId !== variantId
    );

    if (next.length === current.length) {
        return false;
    }

    return saveCart(next);
}


// ========================================
// BOARDRIDERS — ПОВЕРНЕННЯ ВИДАЛЕНОГО ТОВАРУ
// ========================================

export function restoreCartItem(
    item: CartItem,
    position: number
): boolean {
    if (
        !Number.isSafeInteger(item.variantId) ||
        item.variantId <= 0 ||
        !Number.isSafeInteger(item.quantity) ||
        item.quantity < 1 ||
        item.quantity > MAX_QUANTITY
    ) {
        return false;
    }

    const current = getSnapshot();

    // Не додаємо той самий SKU двічі.
    if (
        current.some(
            (existing) => existing.variantId === item.variantId
        )
    ) {
        return false;
    }

    if (current.length >= MAX_ITEMS) {
        return false;
    }

    const next = [...current];

    const safePosition = Number.isFinite(position)
        ? Math.max(
            0,
            Math.min(Math.trunc(position), next.length)
        )
        : next.length;

    // Повертаємо товар на його попереднє місце.
    next.splice(safePosition, 0, {
        variantId: item.variantId,
        quantity: item.quantity,
    });

    return saveCart(next);
}