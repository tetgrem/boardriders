import { notFound } from "next/navigation";
import Link from "next/link";
import { Suspense } from "react";

import ProductCard from "@/components/catalog/ProductCard";
import { figmaBrands } from "@/components/home/BrandStrip";
import { getProducts, type Locale } from "@/lib/catalog";

type Props = {
    params: Promise<{
        locale: string;
        slug: string;
    }>;
};

export default function BrandPage({ params }: Props) {
    return (
        <Suspense
            fallback={
                <main className="mx-auto min-h-[360px] max-w-[1360px] animate-pulse px-4 py-12 xl:px-0">
                    <div className="h-10 w-64 rounded bg-neutral-100" />
                </main>
            }
        >
            <BrandPageContent params={params} />
        </Suspense>
    );
}

async function BrandPageContent({ params }: Props) {
    const { locale: lang, slug } = await params;

    if (lang !== "uk" && lang !== "ru") {
        notFound();
    }

    const locale: Locale = lang;

    const brand = figmaBrands.find(
        (item) => item.slug === slug
    );

    if (!brand) {
        notFound();
    }

    const products = await getProducts(locale, brand.slug);

    return (
        <main className="mx-auto min-h-[460px] max-w-[1360px] px-4 py-10 text-black xl:px-0">
            <Link
                href={`/${locale}`}
                className="text-sm text-neutral-500 hover:text-black"
            >
                ← {locale === "uk" ? "На головну" : "На главную"}
            </Link>

            <h1 className="mb-2 mt-8 text-3xl font-bold sm:text-4xl">
                {brand.name}
            </h1>

            <p className="mb-10 text-sm text-neutral-500">
                {locale === "uk" ? "Товари бренду" : "Товары бренда"}
            </p>

            {products.length ? (
                <div className="grid grid-cols-2 gap-x-4 gap-y-10 md:grid-cols-3 lg:grid-cols-4 lg:gap-x-[26px]">
                    {products.map((product) => (
                        <ProductCard
                            key={product.id}
                            product={product}
                            locale={locale}
                        />
                    ))}
                </div>
            ) : (
                <div className="rounded border border-neutral-200 p-8">
                    <p className="text-lg font-medium">
                        {locale === "uk"
                            ? "Наразі немає опублікованих товарів цього бренду."
                            : "Пока нет опубликованных товаров этого бренда."}
                    </p>

                    <Link
                        href={`/${locale}/products`}
                        className="mt-4 inline-block text-sm underline underline-offset-4"
                    >
                        {locale === "uk"
                            ? "Переглянути весь каталог"
                            : "Посмотреть весь каталог"}
                    </Link>
                </div>
            )}
        </main>
    );
}