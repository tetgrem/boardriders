"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useRef, useState, useTransition } from "react";

import type { CatalogBrand, CatalogSort, Locale } from "@/lib/catalog";

type Selection = {
    brand: string;
    sort: CatalogSort;
    inStock: boolean;
};

type Props = {
    locale: Locale;
    brands: CatalogBrand[];
    brand: string;
    sort: CatalogSort;
    inStock: boolean;
};

export default function CatalogFilters({
                                           locale,
                                           brands,
                                           brand,
                                           sort,
                                           inStock,
                                       }: Props) {
    const router = useRouter();
    const uk = locale === "uk";
    const [isPending, startTransition] = useTransition();
    const initial: Selection = { brand, sort, inStock };
    const [selection, setSelection] = useState<Selection>(initial);
    const selectionRef = useRef<Selection>(initial);

    function update(patch: Partial<Selection>) {
        const next: Selection = { ...selectionRef.current, ...patch };
        selectionRef.current = next;
        setSelection(next);

        const query = new URLSearchParams();
        if (next.brand) query.set("brand", next.brand);
        if (next.sort !== "newest") query.set("sort", next.sort);
        if (next.inStock) query.set("in_stock", "1");

        // Never keep a pagination cursor when the filter criteria change.
        const search = query.toString();
        const href = `/${locale}/products${search ? `?${search}` : ""}`;
        startTransition(() => router.replace(href, { scroll: false }));
    }

    return (
        <aside className="border border-[#CED4D7] p-5 lg:sticky lg:top-5 lg:border-0 lg:border-r lg:py-0 lg:pl-0 lg:pr-7">
            <div className="mb-6 flex items-center justify-between gap-2">
                <h2 className="text-xl font-bold">{uk ? "Фільтри" : "Фильтры"}</h2>
                {isPending && (
                    <span className="text-xs text-[#828B8D]" role="status" aria-live="polite">
            {uk ? "Оновлюємо…" : "Обновляем…"}
          </span>
                )}
            </div>

            <div className="grid gap-5 sm:grid-cols-3 lg:grid-cols-1">
                <div>
                    <label htmlFor="catalog-brand" className="mb-2 block text-sm font-semibold">
                        {uk ? "Бренд" : "Бренд"}
                    </label>
                    <select
                        id="catalog-brand"
                        name="brand"
                        value={selection.brand}
                        onChange={(event) => update({ brand: event.target.value })}
                        className="h-11 w-full border border-[#CED4D7] bg-white px-3 text-sm outline-offset-2 focus:border-black"
                    >
                        <option value="">{uk ? "Усі бренди" : "Все бренды"}</option>
                        {brands.map((item) => (
                            <option key={item.id} value={item.slug}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </div>

                <div>
                    <label htmlFor="catalog-sort" className="mb-2 block text-sm font-semibold">
                        {uk ? "Сортування" : "Сортировка"}
                    </label>
                    <select
                        id="catalog-sort"
                        name="sort"
                        value={selection.sort}
                        onChange={(event) => update({ sort: event.target.value as CatalogSort })}
                        className="h-11 w-full border border-[#CED4D7] bg-white px-3 text-sm outline-offset-2 focus:border-black"
                    >
                        <option value="newest">{uk ? "Останні додані" : "Последние добавленные"}</option>
                        <option value="price_asc">{uk ? "Ціна: від дешевих" : "Цена: сначала дешёвые"}</option>
                        <option value="price_desc">{uk ? "Ціна: від дорогих" : "Цена: сначала дорогие"}</option>
                    </select>
                </div>

                <label className="flex min-h-11 cursor-pointer items-center gap-3 text-sm">
                    <input
                        type="checkbox"
                        name="in_stock"
                        value="1"
                        checked={selection.inStock}
                        onChange={(event) => update({ inStock: event.target.checked })}
                        className="h-[18px] w-[18px] accent-black"
                    />
                    {uk ? "Лише в наявності" : "Только в наличии"}
                </label>

                <div className="sm:col-span-3 lg:col-span-1">
                    <Link
                        href={`/${locale}/products`}
                        scroll={false}
                        className="flex min-h-11 items-center justify-center border border-[#CED4D7] px-6 text-sm font-medium hover:border-black"
                    >
                        {uk ? "Скинути фільтри" : "Сбросить фильтры"}
                    </Link>
                </div>
            </div>
        </aside>
    );
}
