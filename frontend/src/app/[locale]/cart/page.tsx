import { Suspense } from "react";
import { notFound } from "next/navigation";

import CartView from "@/components/cart/CartView";

type Props = {
    params: Promise<{
        locale: string;
    }>;
};

// ========================================
// ДИНАМІЧНИЙ КОНТЕНТ КОШИКА
// ========================================

async function CartContent({ params }: Props) {
    const { locale } = await params;

    if (locale !== "uk" && locale !== "ru") {
        notFound();
    }

    return <CartView locale={locale} />;
}

// ========================================
// СТОРІНКА КОШИКА
// ========================================

export default function CartPage({ params }: Props) {
    return (
        <Suspense
            fallback={
                <main className="min-h-[65vh] bg-white">
                    <div className="mx-auto max-w-[1360px] px-4 py-10">
                        <div className="h-10 w-48 animate-pulse bg-[#F5F5F5]" />

                        <div className="mt-8 h-48 animate-pulse bg-[#F5F5F5]" />
                    </div>
                </main>
            }
        >
            <CartContent params={params} />
        </Suspense>
    );
}