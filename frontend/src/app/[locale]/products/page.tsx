import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";

import ProductCard from "@/components/catalog/ProductCard";
import CatalogFilters from "@/components/catalog/CatalogFilters";
import {
    getCatalogBrands,
    getCatalogPage,
    type CatalogSort,
} from "@/lib/catalog";

type QueryParams = Record<string, string | string[] | undefined>;

type Props = {
    params: Promise<{ locale: string }>;
    searchParams: Promise<QueryParams>;
};

function one(value: string | string[] | undefined): string | undefined {
    return typeof value === "string" ? value : undefined;
}

function catalogUrl(
    locale: "uk" | "ru",
    options: {
        brand?: string;
        inStock?: boolean;
        sort?: CatalogSort;
        cursor?: string;
    },
): string {
    const query = new URLSearchParams();
    if (options.brand) query.set("brand", options.brand);
    if (options.inStock) query.set("in_stock", "1");
    if (options.sort && options.sort !== "newest") query.set("sort", options.sort);
    if (options.cursor) query.set("cursor", options.cursor);

    const search = query.toString();
    return `/${locale}/products${search ? `?${search}` : ""}`;
}

export default function ProductsPage({ params, searchParams }: Props) {
    return (
        <Suspense fallback={<CatalogLoading />}>
            <CatalogContent params={params} searchParams={searchParams} />
        </Suspense>
    );
}

async function CatalogContent({ params, searchParams }: Props) {
    const [{ locale: requestedLocale }, query] = await Promise.all([
        params,
        searchParams,
    ]);

    if (requestedLocale !== "uk" && requestedLocale !== "ru") {
        notFound();
    }

    const locale = requestedLocale;
    const uk = locale === "uk";
    const brand = one(query.brand) ?? "";
    const inStock = one(query.in_stock) === "1";
    const requestedSort = one(query.sort);
    const sort: CatalogSort =
        requestedSort === "price_asc" || requestedSort === "price_desc"
            ? requestedSort
            : "newest";
    const cursor = one(query.cursor);

    const [catalog, brands] = await Promise.all([
        getCatalogPage(locale, {
            brand: brand || undefined,
            onlyInStock: inStock,
            sort,
            cursor,
        }),
        getCatalogBrands(),
    ]);

    const currentOptions = {
        brand: brand || undefined,
        inStock,
        sort,
    };

    return (
        <main className="min-h-[65vh] bg-white text-[#111111]">
            <div className="mx-auto max-w-[1360px] px-4 pb-24 pt-7 xl:px-0">
                <nav
                    aria-label={uk ? "Навігаційний шлях" : "Навигационная цепочка"}
                    className="flex flex-wrap items-center gap-2 text-xs text-[#828B8D] sm:text-sm"
                >
                    <Link href={`/${locale}`} className="hover:text-black">
                        {uk ? "Головна" : "Главная"}
                    </Link>
                    <span aria-hidden="true">/</span>
                    <span className="text-[#111111]">{uk ? "Каталог" : "Каталог"}</span>
                </nav>

                <div className="mt-8 flex flex-wrap items-end justify-between gap-3 border-b border-[#CED4D7] pb-6">
                    <div>
                        <p className="mb-2 text-[11px] font-semibold uppercase tracking-[0.22em] text-[#828B8D]">
                            BOARDRIDERS
                        </p>
                        <h1 className="text-[32px] font-bold leading-tight tracking-tight sm:text-[42px]">
                            {uk ? "Каталог товарів" : "Каталог товаров"}
                        </h1>
                    </div>
                    <p className="text-xs text-[#828B8D] sm:text-sm">
                        {uk ? "На цій сторінці" : "На этой странице"}: {catalog.data.length}
                    </p>
                </div>

                <div className="mt-8 grid items-start gap-8 lg:grid-cols-[250px_minmax(0,1fr)] lg:gap-9">
                    <CatalogFilters
                        key={`${locale}:${brand}:${sort}:${inStock}`}
                        locale={locale}
                        brands={brands}
                        brand={brand}
                        sort={sort}
                        inStock={inStock}
                    />

                    <section aria-label={uk ? "Товари" : "Товары"}>
                        {catalog.data.length === 0 ? (
                            <div className="border border-[#CED4D7] bg-[#F9F9F9] px-6 py-16 text-center">
                                <h2 className="text-lg font-bold">
                                    {uk ? "За цими фільтрами товарів немає" : "По этим фильтрам товаров нет"}
                                </h2>
                                <p className="mt-3 text-sm text-[#828B8D]">
                                    {uk ? "Спробуйте змінити параметри пошуку." : "Попробуйте изменить параметры поиска."}
                                </p>
                                <Link href={`/${locale}/products`} className="mt-6 inline-block text-sm font-semibold underline underline-offset-4">
                                    {uk ? "Увесь каталог" : "Весь каталог"}
                                </Link>
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-x-3 gap-y-9 sm:gap-x-5 xl:grid-cols-3 xl:gap-x-[26px]">
                                {catalog.data.map((product) => (
                                    <ProductCard
                                        key={product.id}
                                        product={product}
                                        locale={locale}
                                    />
                                ))}
                            </div>
                        )}

                        {(catalog.meta.previous_cursor || catalog.meta.next_cursor) && (
                            <nav
                                aria-label={uk ? "Сторінки каталогу" : "Страницы каталога"}
                                className="mt-12 flex flex-wrap justify-between gap-3 border-t border-[#CED4D7] pt-6"
                            >
                                {catalog.meta.previous_cursor ? (
                                    <Link
                                        href={catalogUrl(locale, {
                                            ...currentOptions,
                                            cursor: catalog.meta.previous_cursor,
                                        })}
                                        className="border border-[#CED4D7] px-5 py-3 text-sm font-semibold hover:border-black"
                                    >
                                        ← {uk ? "Назад" : "Назад"}
                                    </Link>
                                ) : <span />}

                                {catalog.meta.next_cursor ? (
                                    <Link
                                        href={catalogUrl(locale, {
                                            ...currentOptions,
                                            cursor: catalog.meta.next_cursor,
                                        })}
                                        className="bg-black px-6 py-3 text-sm font-semibold text-white hover:bg-[#333333]"
                                    >
                                        {uk ? "Далі" : "Далее"} →
                                    </Link>
                                ) : null}
                            </nav>
                        )}
                    </section>
                </div>
            </div>
        </main>
    );
}

function CatalogLoading() {
    return (
        <main className="mx-auto max-w-[1360px] animate-pulse px-4 py-10 xl:px-0">
            <div className="mb-8 h-10 w-64 bg-[#F0F0F0]" />
            <div className="grid gap-8 lg:grid-cols-[250px_minmax(0,1fr)]">
                <div className="h-64 bg-[#F6F6F6]" />
                <div className="grid grid-cols-2 gap-4 xl:grid-cols-3">
                    {Array.from({ length: 6 }, (_, index) => (
                        <div key={index} className="aspect-[320/370] bg-[#F0F0F0]" />
                    ))}
                </div>
            </div>
        </main>
    );
}
