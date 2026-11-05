import { Suspense, type ReactNode } from "react";
import SiteHeader from "@/components/layout/SiteHeader";
import SiteFooter from "@/components/layout/SiteFooter";

type Props = {
    children: ReactNode;
    params: Promise<{ locale: string }>;
};

async function HeaderWithLocale({
                                    params,
                                }: {
    params: Props["params"];
}) {
    const { locale } = await params;

    return <SiteHeader locale={locale === "ru" ? "ru" : "uk"} />;
}

async function FooterWithLocale({
                                    params,
                                }: {
    params: Props["params"];
}) {
    const { locale } = await params;

    return <SiteFooter locale={locale === "ru" ? "ru" : "uk"} />;
}

export default function LocaleLayout({
                                         children,
                                         params,
                                     }: Props) {
    return (
        <>
            {/* ШАПКА САЙТА */}
            <Suspense
                fallback={
                    <div
                        aria-hidden="true"
                        className="h-[115px] bg-white xl:h-[204px]"
                    />
                }
            >
                <HeaderWithLocale params={params} />
            </Suspense>

            {/* СОДЕРЖИМОЕ СТРАНИЦЫ */}
            {children}

            {/* ФУТЕР САЙТА */}
            <Suspense
                fallback={
                    <div
                        aria-hidden="true"
                        className="h-16 bg-[#F9F9F9]"
                    />
                }
            >
                <FooterWithLocale params={params} />
            </Suspense>
        </>
    );
}