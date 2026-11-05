"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useMemo, useState } from "react";

import { formatMoney, type Locale } from "@/lib/catalog";

import {
    removeFromCart,
    restoreCartItem,
    setCartQuantity,
    useCartItems,
    type CartItem,
} from "@/lib/cart";

import { productImageUrl } from "@/lib/media";

import PromotionSummary from "./PromotionSummary";

// ========================================
// ТИПИ
// ========================================

type ItemQuote = {
    variant_id: number;
    available: boolean;

    name?: string;
    slug?: string;
    brand?: string | null;

    sku?: string;
    size?: string | null;

    image?: {
        url: string;
        alt: string;
    } | null;

    price?: {
        amount_minor: number;
        currency: string;
    };

    stock?: {
        available: number;
        in_stock: boolean;
    };
};

type QuoteResponse = {
    data: ItemQuote[];
};

type RemovedItem = {
    item: CartItem;
    position: number;
    name: string;
    expiresAt: number;
};

const UNDO_DURATION_MS = 8000;

type Props = {
    locale: Locale;
};

// ========================================
// BOARDRIDERS — КРУГОВИЙ ТАЙМЕР
// ========================================

function UndoCountdown({
                           expiresAt,
                       }: {
    expiresAt: number;
}) {
    const [remainingMs, setRemainingMs] = useState(() =>
        Math.max(0, expiresAt - Date.now())
    );

    useEffect(() => {
        // Оновлюємо таймер кожні 50 мс.
        // Це забезпечує плавне скорочення кола.
        const update = () => {
            const remaining = Math.max(
                0,
                expiresAt - Date.now()
            );

            setRemainingMs(remaining);
        };

        update();

        const interval = window.setInterval(update, 50);

        return () => {
            window.clearInterval(interval);
        };
    }, [expiresAt]);

    // Секунди, що залишилися.
    const seconds = Math.ceil(remainingMs / 1000);

    // Частина кола, яка ще повинна залишатися.
    // 1 = повне коло.
    // 0 = коло повністю зникло.
    const progress = Math.min(
        1,
        Math.max(0, remainingMs / UNDO_DURATION_MS)
    );

    // Довжина кола нормалізована до 100.
    const circumference = 100;

    const dashOffset =
        circumference * (1 - progress);

    return (
        <div
            className="
        relative
        flex
        h-[48px]
        w-[48px]
        shrink-0
        items-center
        justify-center
        select-none
      "
            aria-hidden="true"
        >

            {/* КРУГОВА ПОЛОСКА */}

            <svg
                width="48"
                height="48"
                viewBox="0 0 48 48"
                className="absolute inset-0"
            >
                <circle
                    cx="24"
                    cy="24"
                    r="19"
                    fill="none"
                    stroke="#EB5757"
                    strokeWidth="4"
                    pathLength={100}
                    strokeDasharray={circumference}
                    strokeDashoffset={dashOffset}
                    strokeLinecap="round"
                    style={{
                        transform: "rotate(-90deg)",
                        transformOrigin: "50% 50%",
                        transition:
                            "stroke-dashoffset 50ms linear",
                    }}
                />
            </svg>

            {/* ЦИФРА В ЦЕНТРІ */}

            <span
                className="
          relative
          z-10
          text-[16px]
          font-bold
          tabular-nums
          text-[#111111]
        "
            >
        {seconds}
      </span>

        </div>
    );
}

// ========================================
// ГОЛОВНИЙ КОМПОНЕНТ
// ========================================

export default function CartView({ locale }: Props) {
    const uk = locale === "uk";

    const items = useCartItems();

    const [quotes, setQuotes] = useState<ItemQuote[]>([]);

    const [status, setStatus] = useState<
        "loading" | "ready" | "error"
    >("loading");

    const [refresh, setRefresh] = useState(0);

    const [removed, setRemoved] =
        useState<RemovedItem | null>(null);

    const [undoError, setUndoError] = useState(false);

    // ========================================
    // ІДЕНТИФІКАТОРИ ТОВАРІВ
    // ========================================

    const itemIds = items
        .map((item) => item.variantId)
        .join(",");

    // ========================================
    // ОТРИМАННЯ ДАНИХ ІЗ LARAVEL
    // ========================================

    useEffect(() => {
        if (!itemIds) {
            setQuotes([]);
            setStatus("ready");
            return;
        }

        const controller = new AbortController();

        setStatus("loading");

        const requested = itemIds.split(",").map((id) => ({
            variant_id: Number(id),
            quantity: 1,
        }));

        fetch("/api/cart/preview", {
            method: "POST",

            headers: {
                "Content-Type": "application/json",
            },

            body: JSON.stringify({
                locale,
                items: requested,
            }),

            signal: controller.signal,
            cache: "no-store",
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data =
                    (await response.json()) as QuoteResponse;

                if (!Array.isArray(data.data)) {
                    throw new Error("Invalid cart response");
                }

                return data.data;
            })
            .then((data) => {
                if (controller.signal.aborted) return;

                setQuotes(data);
                setStatus("ready");
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setStatus("error");
                }
            });

        return () => controller.abort();
    }, [itemIds, locale, refresh]);

    // ========================================
    // ТАЙМЕР ПОВЕРНЕННЯ ВИДАЛЕНОГО ТОВАРУ
    // ========================================

    useEffect(() => {
        if (!removed) return;

        const remaining = Math.max(
            0,
            removed.expiresAt - Date.now()
        );

        const timer = window.setTimeout(() => {
            setRemoved((current) => {
                if (
                    current?.expiresAt !== removed.expiresAt
                ) {
                    return current;
                }

                return null;
            });
        }, remaining);

        return () => {
            window.clearTimeout(timer);
        };
    }, [removed]);

    // ========================================
    // ІНДЕКС ТОВАРІВ
    // ========================================

    const byId = useMemo(
        () =>
            new Map(
                quotes.map((quote) => [
                    quote.variant_id,
                    quote,
                ])
            ),
        [quotes]
    );

    // ========================================
    // КІЛЬКІСТЬ ТОВАРІВ
    // ========================================

    const count = items.reduce(
        (total, item) => total + item.quantity,
        0
    );

    // ========================================
    // ПІДРАХУНОК СУМИ
    // ========================================

    let subtotalMinor = 0;

    let currency: string | null = null;

    let valid =
        status === "ready" && items.length > 0;

    for (const item of items) {
        const quote = byId.get(item.variantId);

        if (
            !quote?.available ||
            !quote.price ||
            !quote.stock
        ) {
            valid = false;
            continue;
        }

        if (
            !quote.stock.in_stock ||
            quote.stock.available < item.quantity
        ) {
            valid = false;
        }

        if (
            currency &&
            currency !== quote.price.currency
        ) {
            valid = false;
        }

        currency ??= quote.price.currency;

        subtotalMinor +=
            quote.price.amount_minor * item.quantity;
    }





    function handleRemove(item: CartItem) {
        const position = items.findIndex(
            (current) => current.variantId === item.variantId
        );

        const name =
            byId.get(item.variantId)?.name ??
            `SKU #${item.variantId}`;

        if (removeFromCart(item.variantId)) {
            setRemoved({
                item: { ...item },
                position,
                name,
                expiresAt: Date.now() + UNDO_DURATION_MS,
            });

            setUndoError(false);
        }
    }


    // ========================================
    // ПОВЕРНЕННЯ ВИДАЛЕНОГО ТОВАРУ
    // ========================================

    function handleUndo() {
        if (!removed) return;

        const restored = restoreCartItem(
            removed.item,
            removed.position
        );

        if (restored) {
            setRemoved(null);
            setUndoError(false);
        } else {
            setUndoError(true);
        }
    }

    // ========================================
    // ІНТЕРФЕЙС
    // ========================================

    return (
        <main className="min-h-[65vh] bg-white text-[#111111]">

            <div className="mx-auto max-w-[1360px] px-4 pb-20 pt-7 xl:px-0">

                {/* =====================================
            НАВІГАЦІЯ
        ===================================== */}

                <nav
                    aria-label={
                        uk ? "Навігація" : "Навигация"
                    }
                    className="flex gap-2 text-xs text-[#828B8D] sm:text-sm"
                >
                    <Link
                        href={`/${locale}`}
                        className="hover:text-black"
                    >
                        {uk ? "Головна" : "Главная"}
                    </Link>

                    <span>/</span>

                    <span className="text-black">
            {uk ? "Кошик" : "Корзина"}
          </span>
                </nav>

                {/* =====================================
            ЗАГОЛОВОК
        ===================================== */}

                <div className="mt-8 flex flex-wrap items-end justify-between gap-4 border-b border-[#CED4D7] pb-5">

                    <h1 className="text-[30px] font-semibold leading-tight sm:text-[40px]">
                        {uk ? "Кошик" : "Корзина"}

                        {count > 0 && (
                            <span className="ml-3 text-base font-normal text-[#828B8D]">
                ({count})
              </span>
                        )}
                    </h1>

                    {/* ОНОВЛЕННЯ ЦІН */}

                    {items.length > 0 && (
                        <button
                            type="button"
                            onClick={() =>
                                setRefresh((value) => value + 1)
                            }
                            className="cursor-pointer text-xs underline underline-offset-4 hover:text-[#EB5757] sm:text-sm"
                        >
                            {uk
                                ? "Оновити ціни та залишки"
                                : "Обновить цены и остатки"}
                        </button>
                    )}

                </div>

                {/* =====================================
            ПОВІДОМЛЕННЯ ПРО ВИДАЛЕННЯ
        ===================================== */}

                {removed && (
                    <div
                        role="status"
                        aria-live="polite"
                        className="
      mt-5
      flex
      flex-wrap
      items-center
      justify-between
      gap-3
      border
      border-[#CED4D7]
      bg-[#F7F7F7]
      px-4
      py-4
      text-sm
    "
                    >

                        {/* ТАЙМЕР ТА НАЗВА ТОВАРУ */}

                        <div
                            className="
        flex
        min-w-0
        flex-1
        items-center
        gap-3
      "
                        >

                            {/* КРУГОВИЙ ТАЙМЕР */}

                            <UndoCountdown
                                key={removed.expiresAt}
                                expiresAt={removed.expiresAt}
                            />

                            {/* ПОВІДОМЛЕННЯ */}

                            <span className="min-w-0 break-words">
        {uk
            ? "Товар видалено з кошика:"
            : "Товар удалён из корзины:"}

                                {" "}

                                <strong className="font-semibold">
          {removed.name}
        </strong>
      </span>

                        </div>

                        {/* КНОПКА ПОВЕРНЕННЯ */}

                        <button
                            type="button"
                            onClick={handleUndo}
                            className="
        shrink-0
        cursor-pointer
        font-semibold
        text-[#EB5757]
        underline
        underline-offset-4
        transition-colors
        hover:text-[#D94B4B]
      "
                        >
                            {uk ? "Повернути" : "Вернуть"}
                        </button>

                    </div>
                )}

                {/* ПОМИЛКА ПОВЕРНЕННЯ */}

                {undoError && (
                    <p
                        role="alert"
                        className="mt-3 text-sm text-[#D94B4B]"
                    >
                        {uk
                            ? "Не вдалося повернути товар. Оновіть кошик і спробуйте ще раз."
                            : "Не удалось вернуть товар. Обновите корзину и попробуйте снова."}
                    </p>
                )}

                {/* =====================================
            ПОРОЖНІЙ КОШИК
        ===================================== */}

                {items.length === 0 ? (

                    <section className="mx-auto flex max-w-md flex-col items-center py-16 text-center sm:py-24">

                        <h2 className="text-[23px] font-semibold">
                            {uk
                                ? "Ваш кошик поки порожній"
                                : "Ваша корзина пока пуста"}
                        </h2>

                        <p className="mt-3 text-sm leading-6 text-[#828B8D]">
                            {uk
                                ? "Перегляньте каталог та додайте товари, які вам сподобалися."
                                : "Посмотрите каталог и добавьте понравившиеся товары."}
                        </p>

                        <Link
                            href={`/${locale}/products`}
                            className="mt-8 flex h-[60px] w-full cursor-pointer items-center justify-center bg-[#EB5757] px-6 text-sm font-semibold uppercase text-white hover:bg-[#D94B4B]"
                        >
                            {uk
                                ? "Перейти до каталогу"
                                : "Перейти в каталог"}
                        </Link>

                    </section>

                ) : (

                    /* =====================================
                        КОШИК ІЗ ТОВАРАМИ
                    ===================================== */

                    <div className="mt-7 grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-10">

                        {/* ЛІВА КОЛОНКА */}

                        <section
                            aria-label={
                                uk
                                    ? "Товари в кошику"
                                    : "Товары в корзине"
                            }
                        >

                            {/* ЗАВАНТАЖЕННЯ */}

                            {status === "loading" && (
                                <p
                                    role="status"
                                    className="mb-4 text-sm text-[#828B8D]"
                                >
                                    {uk
                                        ? "Перевіряємо ціни та залишки…"
                                        : "Проверяем цены и остатки…"}
                                </p>
                            )}

                            {/* ПОМИЛКА API */}

                            {status === "error" && (
                                <div
                                    role="alert"
                                    className="mb-5 flex flex-wrap items-center gap-3 border border-[#EB5757] p-4 text-sm"
                                >
                  <span>
                    {uk
                        ? "Не вдалося отримати дані товарів."
                        : "Не удалось получить данные товаров."}
                  </span>

                                    <button
                                        type="button"
                                        onClick={() =>
                                            setRefresh((value) => value + 1)
                                        }
                                        className="cursor-pointer font-semibold underline"
                                    >
                                        {uk ? "Повторити" : "Повторить"}
                                    </button>
                                </div>
                            )}

                            {/* =====================================
                  СПИСОК ТОВАРІВ
              ===================================== */}

                            <div className="border-t border-[#E5E5E5]">

                                {items.map((item) => {
                                    const quote = byId.get(
                                        item.variantId
                                    );

                                    const found =
                                        quote?.available === true &&
                                        !!quote.price &&
                                        !!quote.stock &&
                                        !!quote.name &&
                                        !!quote.slug;

                                    const stockAvailable =
                                        quote?.stock?.available ?? 0;

                                    const enough =
                                        found &&
                                        stockAvailable >= item.quantity;

                                    const productHref = quote?.slug
                                        ? `/${locale}/products/${quote.slug}`
                                        : `/${locale}/products`;

                                    return (
                                        <article
                                            key={item.variantId}
                                            className="grid grid-cols-[96px_minmax(0,1fr)] gap-4 border-b border-[#E5E5E5] py-5 sm:grid-cols-[144px_minmax(0,1fr)] sm:gap-6 sm:py-7"
                                        >

                                            {/* ФОТО ТОВАРУ */}

                                            <Link
                                                href={productHref}
                                                className="relative block h-[124px] bg-[#F7F7F7] sm:h-[178px]"
                                                aria-label={
                                                    quote?.name ??
                                                    (uk
                                                        ? "Перейти до каталогу"
                                                        : "Перейти в каталог")
                                                }
                                            >

                                                {quote?.image?.url ? (

                                                    <Image
                                                        unoptimized
                                                        src={productImageUrl(
                                                            quote.image.url
                                                        )}
                                                        alt={
                                                            quote.image.alt ||
                                                            quote.name ||
                                                            ""
                                                        }
                                                        fill
                                                        sizes="(max-width: 640px) 96px, 144px"
                                                        loading={
                                                            items[0]?.variantId ===
                                                            item.variantId
                                                                ? "eager"
                                                                : "lazy"
                                                        }
                                                        className="object-contain p-2"
                                                    />

                                                ) : (

                                                    <span className="flex h-full items-center justify-center p-2 text-center text-xs text-[#828B8D]">
                            {uk
                                ? "Немає фото"
                                : "Нет фото"}
                          </span>

                                                )}

                                            </Link>

                                            {/* ІНФОРМАЦІЯ ПРО ТОВАР */}

                                            <div className="flex min-w-0 flex-col">

                                                {found ? (
                                                    <>

                                                        {/* БРЕНД */}

                                                        {quote?.brand && (
                                                            <p className="text-[11px] uppercase tracking-wide text-[#828B8D] sm:text-xs">
                                                                {quote.brand}
                                                            </p>
                                                        )}

                                                        {/* НАЗВА */}

                                                        <Link
                                                            href={productHref}
                                                            className="mt-1 text-[14px] font-semibold leading-5 hover:underline sm:text-[17px] sm:leading-6"
                                                        >
                                                            {quote?.name}
                                                        </Link>

                                                        {/* РОЗМІР ТА SKU */}

                                                        <p className="mt-2 break-words text-[11px] text-[#828B8D] sm:text-xs">

                                                            {quote?.size && (
                                                                <span>
                                  {uk
                                      ? "Розмір"
                                      : "Размер"}
                                                                    : {quote.size} ·{" "}
                                </span>
                                                            )}

                                                            SKU: {quote?.sku}

                                                        </p>

                                                        {/* ЦІНА */}

                                                        <p className="mt-3 text-[17px] font-semibold sm:text-[20px]">
                                                            {quote?.price &&
                                                                formatMoney(
                                                                    quote.price.amount_minor,
                                                                    quote.price.currency,
                                                                    locale
                                                                )}
                                                        </p>

                                                    </>
                                                ) : (

                                                    <p className="text-sm font-medium text-[#828B8D]">
                                                        {status === "loading"
                                                            ? uk
                                                                ? "Завантаження…"
                                                                : "Загрузка…"
                                                            : uk
                                                                ? "Товар більше недоступний"
                                                                : "Товар больше недоступен"}
                                                    </p>

                                                )}

                                                {/* НЕДОСТАТНІЙ ЗАЛИШОК */}

                                                {status === "ready" &&
                                                    found &&
                                                    !enough && (

                                                        <p
                                                            role="status"
                                                            className="mt-2 text-xs text-[#D94B4B]"
                                                        >

                                                            {stockAvailable < 1
                                                                ? uk
                                                                    ? "Немає в наявності"
                                                                    : "Нет в наличии"
                                                                : uk
                                                                    ? `Залишилося лише ${stockAvailable} шт.`
                                                                    : `Осталось только ${stockAvailable} шт.`}

                                                        </p>

                                                    )}

                                                {/* =====================================
                            КІЛЬКІСТЬ ТА ВИДАЛЕННЯ
                        ===================================== */}

                                                <div className="mt-auto flex flex-wrap items-end justify-between gap-x-3 gap-y-3 pt-4">

                                                    {/* ЛІЧИЛЬНИК */}

                                                    <div className="flex h-10 items-center border border-[#CED4D7]">

                                                        {/* МІНУС */}

                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                item.quantity === 1
                                                                    ? handleRemove(item)
                                                                    : setCartQuantity(
                                                                        item.variantId,
                                                                        item.quantity - 1
                                                                    )
                                                            }
                                                            aria-label={
                                                                uk
                                                                    ? "Зменшити кількість"
                                                                    : "Уменьшить количество"
                                                            }
                                                            className="h-full w-9 cursor-pointer text-lg hover:bg-[#F7F7F7] sm:w-10"
                                                        >
                                                            −
                                                        </button>

                                                        {/* КІЛЬКІСТЬ */}

                                                        <span
                                                            aria-label={
                                                                uk
                                                                    ? "Кількість"
                                                                    : "Количество"
                                                            }
                                                            className="min-w-9 text-center text-sm sm:min-w-10"
                                                        >
                              {item.quantity}
                            </span>

                                                        {/* ПЛЮС */}

                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setCartQuantity(
                                                                    item.variantId,
                                                                    item.quantity + 1
                                                                )
                                                            }
                                                            disabled={
                                                                status !== "ready" ||
                                                                !found ||
                                                                item.quantity >=
                                                                Math.min(
                                                                    99,
                                                                    stockAvailable
                                                                )
                                                            }
                                                            aria-label={
                                                                uk
                                                                    ? "Збільшити кількість"
                                                                    : "Увеличить количество"
                                                            }
                                                            className="h-full w-9 cursor-pointer text-lg hover:bg-[#F7F7F7] disabled:cursor-not-allowed disabled:opacity-30 sm:w-10"
                                                        >
                                                            +
                                                        </button>

                                                    </div>

                                                    {/* ВИДАЛЕННЯ */}

                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            handleRemove(item)
                                                        }
                                                        className="cursor-pointer text-xs text-[#828B8D] underline underline-offset-4 hover:text-[#EB5757]"
                                                    >
                                                        {uk
                                                            ? "Видалити"
                                                            : "Удалить"}
                                                    </button>

                                                </div>

                                            </div>

                                        </article>
                                    );
                                })}

                            </div>

                            {/* ПРОДОВЖИТИ ПОКУПКИ */}

                            <Link
                                href={`/${locale}/products`}
                                className="mt-7 inline-flex text-sm font-medium underline underline-offset-4 hover:text-[#EB5757]"
                            >
                                ←{" "}
                                {uk
                                    ? "Продовжити покупки"
                                    : "Продолжить покупки"}
                            </Link>

                        </section>

                        {/* =====================================
                ПРАВА КОЛОНКА — ПІДСУМОК
            ===================================== */}

                        <PromotionSummary
                            locale={locale}
                            items={items}
                            subtotalMinor={subtotalMinor}
                            currency={currency}
                            cartValid={valid}
                            refreshKey={refresh}
                        />

                    </div>

                )}

            </div>

        </main>
    );
}