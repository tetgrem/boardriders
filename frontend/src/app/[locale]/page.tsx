import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import BrandStrip from "@/components/home/BrandStrip";

import ProductCard from "@/components/catalog/ProductCard";

import {
    getProducts,
    type Locale,
    type ProductSummary,
} from "@/lib/catalog";

type PageProps = {
    params: Promise<{ locale: string }>;
};

function tr(locale: Locale, uk: string, ru: string): string {
    return locale === "uk" ? uk : ru;
}

export default function HomePage({ params }: PageProps) {
    return (
        <Suspense fallback={<HomeLoading />}>
            <HomeContent params={params} />
        </Suspense>
    );
}

async function HomeContent({ params }: PageProps) {
    const { locale: routeLocale } = await params;

    if (routeLocale !== "uk" && routeLocale !== "ru") {
        notFound();
    }

    const locale: Locale = routeLocale;
    const catalogHref = `/${locale}/products`;

    return (
        <main className="min-h-screen bg-white text-black">
            {/* Figma Main: 1680px artboard; content width 1360px; hero 1360×482px. */}
            <section className="mx-auto max-w-[1360px] px-4 pt-[25px] xl:px-0">
                <Link
                    href={catalogHref}
                    aria-label={tr(locale, "Перейти до каталогу", "Перейти в каталог")}
                    className="group relative block h-[355px] overflow-hidden rounded-[9px] sm:h-[420px] xl:h-[482px]"
                >
                    <Image
                        src="/figma/home/hero-snow.webp"
                        alt={tr(locale, "Зимовий пейзаж зі сноубордом", "Зимний пейзаж со сноубордом")}
                        fill
                        priority
                        sizes="(max-width: 1360px) 100vw, 1360px"
                        className="object-cover object-center transition-transform duration-700 group-hover:scale-[1.02]"
                    />
                    <div className="absolute inset-0 bg-gradient-to-t from-black/25 via-transparent to-transparent" />
                    <div className="absolute bottom-10 left-0 max-w-[94%] bg-black px-5 py-4 text-white sm:bottom-[76px] sm:px-[30px] sm:py-[17px]">
                        <h1 className="text-[25px] font-black uppercase leading-tight tracking-tight sm:text-[37px] xl:text-[48px]">
                            WINTER SALE {tr(locale, "ДО", "ДО")} -70%
                        </h1>
                    </div>
                </Link>
            </section>

            {/* Brand logos are composed from the image fills of the Figma Group 3. */}
            <div className="mx-auto mt-[48px] max-w-[1360px] overflow-hidden px-4 xl:px-0">
                <BrandStrip locale={locale} />
            </div>

            {/* The two promo blocks correspond to Figma Group 50. */}
            <section
                aria-label={tr(locale, "Спеціальні пропозиції", "Специальные предложения")}
                className="mx-auto mt-[48px] grid max-w-[1360px] gap-[26px] px-4 md:grid-cols-2 xl:px-0"
            >
                <PromoTile
                    href={catalogHref}
                    image="/figma/home/campaign-dc.webp"
                    alt="DC Shoes Snowboarding"
                    caption={tr(locale, "КОЛЕКЦІЯ DC SHOES", "КОЛЛЕКЦИЯ DC SHOES")}
                />
                <PromoTile
                    href={catalogHref}
                    image="/figma/home/campaign-flow.webp"
                    alt="FLOW Boards"
                    caption={tr(locale, "СНОУБОРД: НОВИЙ СЕЗОН", "СНОУБОРД: НОВЫЙ СЕЗОН")}
                />
            </section>

            <Suspense fallback={<ProductsLoading locale={locale} />}>
                <ProductSections locale={locale} />
            </Suspense>

            {/* Editorial block from the Main Figma frame. Not a live blog yet. */}
            <section className="mt-24 bg-black text-white lg:mt-[100px]">
                <div className="mx-auto grid max-w-[1680px] lg:min-h-[540px] lg:grid-cols-2">
                    <div className="relative min-h-[340px] sm:min-h-[460px]">
                        <Image
                            src="/figma/home/blog-story.webp"
                            alt={tr(locale, "Червона скейтова колекція", "Красная скейтовая коллекция")}
                            fill
                            sizes="(max-width: 1024px) 100vw, 50vw"
                            className="object-cover"
                        />
                    </div>
                    <div className="flex flex-col justify-center px-6 py-12 sm:px-12 lg:px-[65px]">
                        <p className="mb-5 text-xs uppercase tracking-[0.3em] text-white/60">
                            BOARDRIDERS STORIES
                        </p>
                        <h2 className="text-3xl font-bold sm:text-4xl">
                            {tr(locale, "Блоги та історії", "Блоги и истории")}
                        </h2>
                        <p className="mt-6 max-w-xl text-base leading-relaxed text-white/70">
                            {tr(
                                locale,
                                "Світ борд-спорту, нові колекції та натхнення для активного життя.",
                                "Мир борд-спорта, новые коллекции и вдохновение для активной жизни."
                            )}
                        </p>
                        <p className="mt-8 text-xs uppercase tracking-[0.15em] text-white/45">
                            {tr(locale, "Розділ у розробці", "Раздел в разработке")}
                        </p>
                    </div>
                </div>
            </section>

            {/* Loyalty and newsletter visual tiles are also taken from Main. */}
            <section className="mx-auto mt-12 grid max-w-[1360px] gap-6 px-4 pb-24 md:grid-cols-2 xl:px-0">
                <div className="relative min-h-[410px] overflow-hidden rounded-md sm:min-h-[510px]">
                    <Image
                        src="/figma/home/loyalty-club.webp"
                        alt={tr(locale, "Сноубордисти в горах", "Сноубордисты в горах")}
                        fill
                        sizes="(max-width: 768px) 100vw, 50vw"
                        className="object-cover"
                    />
                    <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/20 to-transparent" />
                    <div className="absolute inset-x-0 bottom-0 p-7 text-white sm:p-12">
                        <h2 className="text-3xl font-bold sm:text-4xl">
                            {tr(locale, "Програма лояльності", "Программа лояльности")}
                        </h2>
                        <p className="mt-3 max-w-[390px] leading-relaxed text-white/85">
                            {tr(locale, "Більше можливостей для учасників клубу.", "Больше возможностей для участников клуба.")}
                        </p>
                        <p className="mt-5 text-xs uppercase tracking-widest text-white/60">
                            {tr(locale, "Незабаром", "Скоро")}
                        </p>
                    </div>
                </div>
                <div className="relative min-h-[410px] overflow-hidden rounded-md sm:min-h-[510px]">
                    <Image
                        src="/figma/home/newsletter-snow.webp"
                        alt={tr(locale, "Сноуборд у зимових горах", "Сноуборд в зимних горах")}
                        fill
                        sizes="(max-width: 768px) 100vw, 50vw"
                        className="object-cover"
                    />
                    <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/25 to-transparent" />
                    <div className="absolute inset-x-0 bottom-0 p-7 text-white sm:p-12">
                        <h2 className="text-3xl font-bold sm:text-4xl">
                            {tr(locale, "Новини BOARDRIDERS", "Новости BOARDRIDERS")}
                        </h2>
                        <p className="mt-3 max-w-[390px] leading-relaxed text-white/85">
                            {tr(
                                locale,
                                "Незабаром тут можна буде підписатися на новинки й акції.",
                                "Скоро здесь можно будет подписаться на новинки и акции."
                            )}
                        </p>
                        <p className="mt-5 text-xs uppercase tracking-widest text-white/60">
                            {tr(locale, "Підписка у розробці", "Подписка в разработке")}
                        </p>
                    </div>
                </div>
            </section>
        </main>
    );
}

function PromoTile({
                       href,
                       image,
                       alt,
                       caption,
                   }: {
    href: string;
    image: string;
    alt: string;
    caption: string;
}) {
    return (
        <Link href={href} className="group block overflow-hidden rounded-[5px] bg-black text-white">
            <div className="relative aspect-[667/269] overflow-hidden bg-[#e5e5e5]">
                <Image
                    src={image}
                    alt={alt}
                    fill
                    sizes="(max-width: 768px) 100vw, 50vw"
                    className="object-cover transition-transform duration-500 group-hover:scale-[1.04]"
                />
            </div>
            <div className="flex min-h-[71px] items-center px-5 sm:px-8">
        <span className="text-base font-bold uppercase tracking-wide sm:text-[21px]">
          {caption}
        </span>
                <span aria-hidden className="ml-auto text-2xl">↗</span>
            </div>
        </Link>
    );
}

async function ProductSections({ locale }: { locale: Locale }) {
    let products: ProductSummary[] = [];

    try {
        products = await getProducts(locale);
    } catch {
        // The marketing page must stay available if local Laravel is stopped.
        return (
            <section className="mx-auto mt-24 max-w-[1360px] px-4 xl:px-0">
                <h2 className="text-3xl font-bold">{tr(locale, "Новинки", "Новинки")}</h2>
                <p className="mt-5 text-neutral-500">
                    {tr(locale, "Не вдалося завантажити товари. Спробуйте пізніше.", "Не удалось загрузить товары. Попробуйте позже.")}
                </p>
            </section>
        );
    }

    return (
        <>
            <ProductGrid
                locale={locale}
                title={tr(locale, "Новинки", "Новинки")}
                products={products.slice(0, 4)}
            />

            <section className="mx-auto mt-[95px] max-w-[1360px] px-4 xl:px-0">
                <Link
                    href={`/${locale}/products`}
                    className="group relative block h-[260px] overflow-hidden rounded-md sm:h-[395px]"
                >
                    <Image
                        src="/figma/home/dc-feature.webp"
                        alt="DC Shoes — featured collection"
                        fill
                        sizes="(max-width: 1360px) 100vw, 1360px"
                        className="object-cover transition-transform duration-700 group-hover:scale-[1.02]"
                    />
                </Link>
                <div className="py-6 text-center">
                    <h2 className="text-2xl font-bold sm:text-3xl">
                        DC Shoes — {tr(locale, "нова колекція", "новая коллекция")}
                    </h2>
                    <p className="mt-2 text-sm text-neutral-600">
                        {tr(locale, "Відкривай товари у каталозі", "Открывай товары в каталоге")}
                    </p>
                </div>
            </section>

            {products.length > 4 && (
                <ProductGrid
                    locale={locale}
                    title={tr(locale, "Ще товари", "Ещё товары")}
                    products={products.slice(4, 8)}
                />
            )}
        </>
    );
}

function ProductGrid({
                         locale,
                         title,
                         products,
                     }: {
    locale: Locale;
    title: string;
    products: ProductSummary[];
}) {
    return (
        <section className="mx-auto mt-[94px] max-w-[1360px] px-4 xl:px-0">
            <div className="mb-10 flex flex-wrap items-end justify-between gap-4">
                <h2 className="text-[30px] font-bold sm:text-[32px]">{title}</h2>
                <Link
                    href={`/${locale}/products`}
                    className="text-sm font-semibold underline underline-offset-4 hover:text-neutral-600"
                >
                    {tr(locale, "Усі товари", "Все товары")} ↗
                </Link>
            </div>

            {products.length === 0 ? (
                <p className="text-neutral-500">
                    {tr(locale, "Опублікованих товарів поки немає.", "Опубликованных товаров пока нет.")}
                </p>
            ) : (
                <div className="grid grid-cols-2 gap-x-4 gap-y-9 md:grid-cols-3 lg:grid-cols-4 lg:gap-x-[26px]">
                    {products.map((product) => (
                        <ProductCard key={product.id} product={product} locale={locale} />
                    ))}
                </div>
            )}
        </section>
    );
}

function ProductsLoading({ locale }: { locale: Locale }) {
    return (
        <section className="mx-auto mt-[94px] max-w-[1360px] px-4 xl:px-0">
            <h2 className="mb-10 text-[32px] font-bold">{tr(locale, "Новинки", "Новинки")}</h2>
            <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 lg:gap-[26px]">
                {Array.from({ length: 4 }, (_, index) => (
                    <div key={index} className="animate-pulse">
                        <div className="aspect-[320/370] rounded-md bg-neutral-100" />
                        <div className="mt-4 h-4 w-24 rounded bg-neutral-100" />
                        <div className="mt-3 h-5 w-4/5 rounded bg-neutral-100" />
                    </div>
                ))}
            </div>
        </section>
    );
}

function HomeLoading() {
    return (
        <main className="mx-auto max-w-[1360px] px-4 pt-[25px] xl:px-0">
            <div className="h-[355px] animate-pulse rounded-[9px] bg-neutral-100 sm:h-[420px] xl:h-[482px]" />
            <div className="mt-12 h-[75px] animate-pulse bg-neutral-50" />
            <div className="mt-12 grid gap-6 md:grid-cols-2">
                <div className="h-[340px] animate-pulse rounded-md bg-neutral-100" />
                <div className="h-[340px] animate-pulse rounded-md bg-neutral-100" />
            </div>
        </main>
    );
}
