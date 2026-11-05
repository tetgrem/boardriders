"use client";

import { useEffect, useState } from "react";

import {
    formatMoney,
    type Locale,
} from "@/lib/catalog";

import type { CartItem } from "@/lib/cart";

// ========================================
// ТИПИ
// ========================================

type PromotionPreview = {
    valid: boolean;
    reason: string | null;
    code: string | null;
    currency: string | null;
    subtotal_minor: number;
    discount_minor: number;
    total_minor: number;
};

type PromotionResponse = {
    data: PromotionPreview;
};

type Props = {
    locale: Locale;
    items: CartItem[];
    subtotalMinor: number;
    currency: string | null;
    cartValid: boolean;
    refreshKey: number;
};

type StoredPreview = {
    key: string;
    data: PromotionPreview;
};

// ========================================
// ПОВІДОМЛЕННЯ ПРО ПОМИЛКИ
// ========================================

function getPromotionError(
    reason: string | null,
    uk: boolean
): string {
    const messages: Record<string, [string, string]> = {
        invalid_format: [
            "Введіть коректний промокод.",
            "Введите корректный промокод.",
        ],

        code_unavailable: [
            "Промокод недійсний, вимкнений або термін його дії закінчився.",
            "Промокод недействителен, отключён или срок его действия истёк.",
        ],

        minimum_not_met: [
            "Сума кошика недостатня для цього промокоду.",
            "Сумма корзины недостаточна для этого промокода.",
        ],

        promotion_limit_reached: [
            "Ліміт використання акції вичерпано.",
            "Лимит использования акции исчерпан.",
        ],

        code_limit_reached: [
            "Цей промокод більше недоступний.",
            "Этот промокод больше недоступен.",
        ],

        customer_limit_reached: [
            "Ви вже використали цей промокод максимальну кількість разів.",
            "Вы уже использовали этот промокод максимальное количество раз.",
        ],

        login_required: [
            "Для використання промокоду необхідно увійти в акаунт.",
            "Для использования промокода необходимо войти в аккаунт.",
        ],

        cart_unavailable: [
            "Деякі товари в кошику недоступні.",
            "Некоторые товары в корзине недоступны.",
        ],

        insufficient_stock: [
            "Недостатньо товарів на складі.",
            "Недостаточно товаров на складе.",
        ],

        price_changed: [
            "Ціна кошика змінилася. Оновіть ціни та спробуйте ще раз.",
            "Цена корзины изменилась. Обновите цены и попробуйте снова.",
        ],

        network_error: [
            "Не вдалося перевірити промокод. Спробуйте ще раз.",
            "Не удалось проверить промокод. Попробуйте снова.",
        ],

        empty_subtotal: [
            "Неможливо застосувати промокод до порожнього кошика.",
            "Невозможно применить промокод к пустой корзине.",
        ],

        no_discount: [
            "Цей промокод не надає знижки для поточного кошика.",
            "Этот промокод не даёт скидку для текущей корзины.",
        ],
    };

    const message = messages[reason ?? ""];

    if (!message) {
        return uk
            ? "Не вдалося застосувати промокод."
            : "Не удалось применить промокод.";
    }

    return uk ? message[0] : message[1];
}

// ========================================
// ГОЛОВНИЙ КОМПОНЕНТ
// ========================================

export default function PromotionSummary({
                                             locale,
                                             items,
                                             subtotalMinor,
                                             currency,
                                             cartValid,
                                             refreshKey,
                                         }: Props) {
    const uk = locale === "uk";

    const [input, setInput] = useState("");

    const [requestedCode, setRequestedCode] =
        useState<string | null>(null);

    const [preview, setPreview] =
        useState<StoredPreview | null>(null);

    const [error, setError] =
        useState<string | null>(null);

    const [checking, setChecking] = useState(false);

    const [attempt, setAttempt] = useState(0);

    // ========================================
    // ІДЕНТИФІКАТОР ПОТОЧНОГО КОШИКА
    // ========================================

    const cartKey = JSON.stringify({
        locale,
        items: items.map((item) => [
            item.variantId,
            item.quantity,
        ]),
        subtotalMinor,
        currency,
        refreshKey,
        attempt,
    });

    // ========================================
    // ПЕРЕВІРКА ПРОМОКОДУ ЧЕРЕЗ LARAVEL
    // ========================================

    useEffect(() => {
        if (
            !requestedCode ||
            !cartValid ||
            !currency ||
            items.length === 0
        ) {
            return;
        }

        const controller = new AbortController();

        let active = true;

        async function verifyPromotion() {
            setChecking(true);
            setError(null);
            setPreview(null);

            try {
                const response = await fetch(
                    "/api/promotions/preview",
                    {
                        method: "POST",

                        headers: {
                            "Content-Type": "application/json",
                        },

                        body: JSON.stringify({
                            code: requestedCode,

                            items: items.map((item) => ({
                                variant_id: item.variantId,
                                quantity: item.quantity,
                            })),
                        }),

                        signal: controller.signal,

                        cache: "no-store",
                    }
                );

                if (!response.ok) {
                    throw new Error("Promotion request failed");
                }

                const payload =
                    (await response.json()) as PromotionResponse;

                if (!active) return;

                const result = payload?.data;

                if (!result || typeof result.valid !== "boolean") {
                    throw new Error("Invalid promotion response");
                }

                if (!result.valid) {
                    setError(result.reason ?? "code_unavailable");
                    return;
                }

                // ====================================
                // ПЕРЕВІРКА ВІДПОВІДНОСТІ СУМ
                // ====================================

                const amountsMatch =
                    result.currency === currency &&
                    result.subtotal_minor === subtotalMinor &&
                    Number.isSafeInteger(result.discount_minor) &&
                    Number.isSafeInteger(result.total_minor) &&
                    result.discount_minor > 0 &&
                    result.discount_minor <= subtotalMinor &&
                    result.total_minor ===
                    subtotalMinor - result.discount_minor;

                if (!amountsMatch) {
                    setError("price_changed");
                    return;
                }

                setPreview({
                    key: cartKey,
                    data: result,
                });
            } catch {
                if (active) {
                    setError("network_error");
                }
            } finally {
                if (active) {
                    setChecking(false);
                }
            }
        }

        void verifyPromotion();

        return () => {
            active = false;
            controller.abort();
        };
    }, [
        requestedCode,
        cartKey,
        cartValid,
        currency,
        subtotalMinor,
        items,
    ]);

    // ========================================
    // ЧИ ЗАСТОСОВАНО ПРОМОКОД
    // ========================================

    const applied =
        cartValid &&
        !checking &&
        requestedCode !== null &&
        preview?.key === cartKey &&
        preview.data.valid === true;

    const discountMinor = applied
        ? preview.data.discount_minor
        : 0;

    const finalTotalMinor =
        subtotalMinor - discountMinor;

    const formattedSubtotal =
        cartValid && currency
            ? formatMoney(
                subtotalMinor,
                currency,
                locale
            )
            : "—";

    const formattedDiscount =
        applied && currency
            ? formatMoney(
                -discountMinor,
                currency,
                locale
            )
            : null;

    const formattedTotal =
        cartValid && currency
            ? formatMoney(
                finalTotalMinor,
                currency,
                locale
            )
            : "—";

    // ========================================
    // ЗАСТОСУВАННЯ ПРОМОКОДУ
    // ========================================

    function handleApply(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!cartValid) {
            return;
        }

        const code = input.trim().toUpperCase();

        if (!/^[A-Z0-9][A-Z0-9_-]{2,39}$/.test(code)) {
            setError("invalid_format");
            setRequestedCode(null);
            setPreview(null);
            return;
        }

        setError(null);
        setPreview(null);
        setRequestedCode(code);

        // Дозволяє повторити запит навіть
        // для того самого промокоду.
        setAttempt((value) => value + 1);
    }

    // ========================================
    // ВИДАЛЕННЯ ПРОМОКОДУ
    // ========================================

    function handleRemove() {
        setInput("");
        setRequestedCode(null);
        setPreview(null);
        setError(null);
        setChecking(false);
    }

    // ========================================
    // ІНТЕРФЕЙС
    // ========================================

    return (
        <aside
            aria-label={
                uk ? "Підсумок кошика" : "Итог корзины"
            }
            className="border border-[#CED4D7] bg-white p-5 sm:p-7 lg:sticky lg:top-6"
        >
            {/* ЗАГОЛОВОК */}

            <h2 className="text-[21px] font-semibold">
                {uk ? "Ваше замовлення" : "Ваш заказ"}
            </h2>

            {/* ВАРТІСТЬ ТОВАРІВ */}

            <div className="mt-6 flex items-start justify-between gap-3 text-sm">
        <span>
          {uk ? "Товари" : "Товары"} (
            {items.reduce(
                (total, item) => total + item.quantity,
                0
            )}
            )
        </span>

                <span className="font-medium">
          {formattedSubtotal}
        </span>
            </div>

            {/* =====================================
          ПРОМОКОД
      ===================================== */}

            <div className="mt-7 border-t border-[#E5E5E5] pt-6">
                <h3 className="mb-3 text-sm font-semibold">
                    {uk ? "Маєте промокод?" : "Есть промокод?"}
                </h3>

                <form
                    onSubmit={handleApply}
                    className="flex flex-col gap-2 sm:flex-row"
                >
                    <label
                        htmlFor="boardriders-promo"
                        className="sr-only"
                    >
                        {uk ? "Промокод" : "Промокод"}
                    </label>

                    <input
                        id="boardriders-promo"
                        type="text"
                        value={input}
                        maxLength={40}
                        autoComplete="off"
                        spellCheck={false}
                        placeholder={
                            uk
                                ? "Введіть промокод"
                                : "Введите промокод"
                        }
                        onChange={(event) => {
                            const value =
                                event.target.value.toUpperCase();

                            setInput(value);

                            if (
                                requestedCode &&
                                value.trim() !== requestedCode
                            ) {
                                setRequestedCode(null);
                                setPreview(null);
                                setError(null);
                            }
                        }}
                        className="h-[48px] min-w-0 flex-1 border border-[#CED4D7] px-3 text-sm uppercase outline-none focus:border-[#111111]"
                    />

                    <button
                        type="submit"
                        disabled={!cartValid || checking}
                        className="h-[48px] shrink-0 cursor-pointer bg-[#111111] px-4 text-xs font-semibold uppercase text-white transition-colors hover:bg-[#333333] disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {checking
                            ? uk
                                ? "Перевірка…"
                                : "Проверка…"
                            : uk
                                ? "Застосувати"
                                : "Применить"}
                    </button>
                </form>

                {/* УСПІШНА ПЕРЕВІРКА */}

                {applied && (
                    <div
                        role="status"
                        className="mt-3 text-xs leading-5 text-[#268653]"
                    >
                        ✓{" "}
                        {uk
                            ? `Промокод ${requestedCode} застосовано`
                            : `Промокод ${requestedCode} применён`}
                    </div>
                )}

                {/* ПОМИЛКА */}

                {error && cartValid && !checking && (
                    <p
                        role="alert"
                        className="mt-3 text-xs leading-5 text-[#EB5757]"
                    >
                        {getPromotionError(error, uk)}
                    </p>
                )}

                {/* ОЧІКУВАННЯ ПЕРЕВІРКИ */}

                {requestedCode && !cartValid && (
                    <p className="mt-3 text-xs leading-5 text-[#828B8D]">
                        {uk
                            ? "Промокод буде перевірено після оновлення кошика."
                            : "Промокод будет проверен после обновления корзины."}
                    </p>
                )}

                {/* ПРИБРАТИ ПРОМОКОД */}

                {requestedCode && (
                    <button
                        type="button"
                        onClick={handleRemove}
                        className="mt-3 cursor-pointer text-xs text-[#828B8D] underline underline-offset-4 hover:text-[#EB5757]"
                    >
                        {uk
                            ? "Прибрати промокод"
                            : "Убрать промокод"}
                    </button>
                )}
            </div>

            {/* =====================================
          ЗНИЖКА
      ===================================== */}

            {applied && formattedDiscount && (
                <div className="mt-6 flex items-start justify-between gap-3 text-sm">
          <span>
            {uk ? "Знижка" : "Скидка"} (
              {requestedCode})
          </span>

                    <span className="font-semibold text-[#EB5757]">
            {formattedDiscount}
          </span>
                </div>
            )}

            {/* ДОСТАВКА */}

            <p className="mt-5 text-xs leading-5 text-[#828B8D]">
                {uk
                    ? "Вартість доставки та бонуси будуть враховані під час оформлення замовлення."
                    : "Стоимость доставки и бонусы будут учтены при оформлении заказа."}
            </p>

            <div className="my-6 h-px bg-[#CED4D7]" />

            {/* =====================================
          ПІДСУМКОВА СУМА
      ===================================== */}

            <div className="flex items-start justify-between gap-3 text-lg font-semibold">
        <span>
          {uk ? "Разом" : "Итого"}
        </span>

                <span>
          {formattedTotal}
        </span>
            </div>

            {/* ПОПЕРЕДЖЕННЯ ПРО ЗАЛИШКИ */}

            {!cartValid && (
                <p
                    role="status"
                    className="mt-4 text-xs leading-5 text-[#828B8D]"
                >
                    {uk
                        ? "Перевіряємо актуальні ціни та доступність товарів. Якщо перевірка завершилася, перегляньте позиції кошика."
                        : "Проверяем актуальные цены и наличие товаров. Если проверка завершилась, проверьте позиции корзины."}
                </p>
            )}

            {/* ОФОРМЛЕННЯ ЗАМОВЛЕННЯ */}

            <button
                type="button"
                disabled
                className="mt-7 flex h-[68px] w-full cursor-not-allowed items-center justify-center bg-[#EB5757] px-4 text-center text-sm font-semibold uppercase text-white opacity-60"
            >
                {uk
                    ? "Оформити замовлення"
                    : "Оформить заказ"}
            </button>

            <p className="mt-2 text-center text-[11px] text-[#828B8D]">
                {uk
                    ? "Оформлення замовлення додамо на наступному етапі"
                    : "Оформление заказа добавим на следующем этапе"}
            </p>
        </aside>
    );
}