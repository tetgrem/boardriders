"use client";

import { useState } from "react";
import Link from "next/link";

import ProductGallery from "./ProductGallery";

import {
    formatMoney,
    type Locale,
    type ProductDetail,
} from "@/lib/catalog";

import {
    addToCart,
    useCartItems,
} from "@/lib/cart";

type Props = {
    locale: Locale;
    product: ProductDetail;
};

export default function ProductDetailView({
                                              locale,
                                              product,
                                          }: Props) {
    const uk = locale === "uk";

    // =========================================
    // СТАН СТОРІНКИ
    // =========================================

    const [selectedVariantId, setSelectedVariantId] =
        useState<number | null>(null);

    const [cartMessage, setCartMessage] = useState("");

    const cartItems = useCartItems();

    // =========================================
    // ВАРІАНТ ТОВАРУ
    // =========================================

    const selectedVariant =
        product.variants.find(
            (variant) => variant.id === selectedVariantId
        ) ?? null;

    const isInCart = selectedVariant
        ? cartItems.some(
            (item) => item.variantId === selectedVariant.id
        )
        : false;

    // =========================================
    // ЦІНА ТА НАЯВНІСТЬ
    // =========================================

    const price =
        selectedVariant?.price ?? product.price_from;

    const stock =
        selectedVariant?.stock ?? product.stock;

    const money = (minor: number) =>
        formatMoney(minor, price.currency, locale);

    // =========================================
    // ТЕКСТИ
    // =========================================

    const t = {
        back: uk ? "До каталогу" : "В каталог",
        available: uk ? "В наявності" : "В наличии",
        unavailable: uk
            ? "Немає в наявності"
            : "Нет в наличии",
        size: uk ? "Оберіть розмір" : "Выберите размер",
        selectSize: uk
            ? "Спочатку оберіть розмір"
            : "Сначала выберите размер",
        addToCart: uk
            ? "Додати в кошик"
            : "Добавить в корзину",
        inCart: uk ? "В кошику" : "В корзине",
        added: uk
            ? "Товар додано до кошика"
            : "Товар добавлен в корзину",
        addError: uk
            ? "Не вдалося додати товар. Перевірте доступну кількість."
            : "Не удалось добавить товар. Проверьте доступное количество.",
        availableQuantity: uk ? "Доступно" : "Доступно",
        description: uk ? "Опис товару" : "Описание товара",
    };

    // =========================================
    // ВИБІР РОЗМІРУ
    // =========================================

    function handleSelectVariant(variantId: number) {
        setSelectedVariantId(variantId);
        setCartMessage("");
    }

    // =========================================
    // ДОДАВАННЯ ДО КОШИКА
    // =========================================

    function handleAddToCart() {
        if (!selectedVariant) return;
        if (!selectedVariant.stock.in_stock) return;

        const added = addToCart(
            selectedVariant.id,
            selectedVariant.stock.available
        );

        setCartMessage(added ? t.added : t.addError);
    }

    // =========================================
    // ІНТЕРФЕЙС
    // =========================================

    return (
        <main className="min-h-[65vh] bg-white text-[#111111]">
            <div className="mx-auto max-w-[1360px] px-4 pb-24 pt-7 xl:px-0">

                {/* НАВІГАЦІЯ */}

                <nav className="mb-8 text-[13px]">
                    <Link
                        href={`/${locale}/products`}
                        className="text-[#828B8D] transition-colors hover:text-black"
                    >
                        ← {t.back}
                    </Link>
                </nav>

                {/* ОСНОВНИЙ КОНТЕНТ */}

                <div className="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(360px,440px)] lg:gap-12">

                    {/* ГАЛЕРЕЯ */}

                    <ProductGallery
                        cover={product.image}
                        images={product.images}
                        productName={product.name}
                        locale={locale}
                    />

                    {/* ПРАВА КОЛОНКА */}

                    <section className="min-w-0">

                        {/* БРЕНД */}

                        {product.brand && (
                            <p className="mb-3 text-[13px] font-medium uppercase tracking-wide text-[#828B8D]">
                                {product.brand.name}
                            </p>
                        )}

                        {/* НАЗВА */}

                        <h1 className="text-[22px] font-semibold leading-[1.5] tracking-[-0.02em] sm:text-[24px]">
                            {product.name}
                        </h1>

                        {/* КАТЕГОРІЯ */}

                        {product.primary_category && (
                            <p className="mt-2 text-[13px] text-[#828B8D]">
                                {product.primary_category.name}
                            </p>
                        )}

                        {/* ЦІНА */}

                        <div className="mt-5 flex flex-wrap items-baseline gap-x-4 gap-y-2">
              <span className="text-[30px] font-semibold leading-tight sm:text-[32px]">
                {money(price.amount_minor)}
              </span>

                            {price.compare_at_minor != null &&
                                price.compare_at_minor > price.amount_minor && (
                                    <span className="text-[18px] text-[#828B8D] line-through">
                    {money(price.compare_at_minor)}
                  </span>
                                )}
                        </div>

                        {/* РОЗДІЛЮВАЧ */}

                        <div className="my-6 h-px bg-[#E5E5E5]" />

                        {/* НАЯВНІСТЬ */}

                        <div className="flex flex-wrap items-center gap-2 text-[13px]">
              <span
                  className={
                      stock.in_stock
                          ? "font-medium text-[#248747]"
                          : "font-medium text-[#828B8D]"
                  }
              >
                {stock.in_stock
                    ? t.available
                    : t.unavailable}
              </span>

                            {selectedVariant && stock.in_stock && (
                                <span className="text-[#828B8D]">
                  · {t.availableQuantity}: {stock.available}
                </span>
                            )}
                        </div>

                        {/* ВИБІР РОЗМІРУ */}

                        {product.variants.length > 0 && (
                            <div className="mt-8">

                                <h2 className="mb-5 text-[14px] font-medium">
                                    {t.size}
                                </h2>

                                <div className="flex flex-wrap gap-[8px]">
                                    {product.variants.map((variant) => {
                                        const selected =
                                            selectedVariantId === variant.id;

                                        const available =
                                            variant.stock.in_stock;

                                        const label = variant.size_eu
                                            ? variant.size_eu.label
                                            : variant.sku;

                                        return (
                                            <button
                                                key={variant.id}
                                                type="button"
                                                disabled={!available}
                                                aria-pressed={selected}
                                                aria-label={`${label} — ${
                                                    available
                                                        ? t.available
                                                        : t.unavailable
                                                }`}
                                                title={
                                                    available
                                                        ? undefined
                                                        : t.unavailable
                                                }
                                                onClick={() =>
                                                    handleSelectVariant(variant.id)
                                                }
                                                className={`
                          relative flex min-h-[48px] min-w-[48px]
                          items-center justify-center
                          border px-3 py-2
                          text-center text-[13px]
                          transition-colors duration-150
                          ${
                                                    !available
                                                        ? "cursor-not-allowed border-[#F5F5F5] bg-[#F7F7F7] text-[#BFC2C4] line-through"
                                                        : selected
                                                            ? "cursor-pointer border-black bg-black text-white"
                                                            : "cursor-pointer border-[#F7F7F7] bg-[#F7F7F7] text-[#111111] hover:border-black"
                                                }
                        `}
                                            >
                                                {label}
                                            </button>
                                        );
                                    })}
                                </div>

                            </div>
                        )}

                        {/* SKU */}

                        {selectedVariant && (
                            <div className="mt-6 text-[12px] text-[#828B8D]">
                                <p>
                                    SKU:{" "}
                                    <span className="text-[#111111]">
                    {selectedVariant.sku}
                  </span>
                                </p>

                                {selectedVariant.barcode && (
                                    <p className="mt-1">
                                        EAN: {selectedVariant.barcode}
                                    </p>
                                )}
                            </div>
                        )}

                        {/* КНОПКА КОШИКА */}

                        <div className="mt-10">

                            <button
                                type="button"
                                disabled={
                                    !selectedVariant ||
                                    !selectedVariant.stock.in_stock
                                }
                                onClick={handleAddToCart}
                                className="
                  flex h-[68px] w-full
                  cursor-pointer items-center justify-center
                  border border-[#EB5757]
                  bg-[#EB5757] px-4
                  text-center text-[16px]
                  font-semibold uppercase
                  text-white
                  transition-colors duration-200
                  hover:border-[#D94B4B]
                  hover:bg-[#D94B4B]
                  focus-visible:outline-2
                  focus-visible:outline-offset-2
                  focus-visible:outline-[#EB5757]
                  disabled:cursor-not-allowed
                  disabled:border-[#E5E5E5]
                  disabled:bg-[#E5E5E5]
                  disabled:text-[#828B8D]
                "
                            >
                                {!selectedVariant
                                    ? t.selectSize
                                    : isInCart
                                        ? t.inCart
                                        : t.addToCart}
                            </button>

                            {/* ПОВІДОМЛЕННЯ */}

                            {cartMessage && (
                                <p
                                    role="status"
                                    className="mt-3 text-[13px] text-[#111111]"
                                >
                                    {cartMessage}
                                </p>
                            )}

                        </div>

                        {/* ОПИС */}

                        {product.description && (
                            <div className="mt-10 border-t border-[#E5E5E5] pt-6">

                                <h2 className="mb-4 text-[18px] font-semibold">
                                    {t.description}
                                </h2>

                                <p className="whitespace-pre-line text-[14px] leading-7 text-[#555555]">
                                    {product.description}
                                </p>

                            </div>
                        )}

                    </section>
                </div>
            </div>
        </main>
    );
}