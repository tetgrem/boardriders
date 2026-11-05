import { Suspense } from "react";
import { notFound } from "next/navigation";

import { getProduct } from "@/lib/catalog";
import ProductDetailView from "@/components/catalog/ProductDetailView";

type Props = {
    params: Promise<{
        locale: string;
        slug: string;
    }>;
};

// Основная страница с Suspense
export default function ProductPage({ params }: Props) {
    return (
        <Suspense fallback={<ProductLoading />}>
            <ProductContent params={params} />
        </Suspense>
    );
}

// Асинхронная загрузка товара
async function ProductContent({ params }: Props) {
    const { locale, slug } = await params;

    if (locale !== "uk" && locale !== "ru") {
        notFound();
    }

    const product = await getProduct(locale, slug);

    if (!product) {
        notFound();
    }

    return (
        <ProductDetailView
            locale={locale}
            product={product}
        />
    );
}

// Скелетон во время загрузки
function ProductLoading() {
    return (
        <main className="min-h-screen bg-neutral-50">
            <div className="mx-auto max-w-7xl px-4 py-10 sm:px-8">
                <div className="grid gap-10 lg:grid-cols-2 lg:gap-16">

                    {/* Фотография */}
                    <div className="aspect-[4/5] animate-pulse rounded-xl bg-neutral-200" />

                    {/* Информация о товаре */}
                    <div className="space-y-6 py-4">
                        <div className="h-4 w-32 animate-pulse rounded bg-neutral-200" />

                        <div className="h-10 w-3/4 animate-pulse rounded bg-neutral-200" />

                        <div className="h-8 w-40 animate-pulse rounded bg-neutral-200" />

                        <div className="mt-10 flex gap-3">
                            {[1, 2, 3, 4, 5].map((size) => (
                                <div
                                    key={size}
                                    className="h-14 w-16 animate-pulse rounded-lg bg-neutral-200"
                                />
                            ))}
                        </div>
                    </div>

                </div>
            </div>
        </main>
    );
}