import Image from "next/image";
import Link from "next/link";
import type { Locale } from "@/lib/catalog";

export const figmaBrands = [
    { slug: "roxy", name: "Roxy" },
    { slug: "element", name: "Element" },
    { slug: "dc-shoes", name: "DC Shoes" },
    { slug: "quiksilver", name: "Quiksilver" },
    { slug: "billabong", name: "Billabong" },
    { slug: "boardriders", name: "Boardriders" },
    { slug: "lib-tech", name: "Lib Tech" },
    { slug: "rvca", name: "RVCA" },
    { slug: "gnu", name: "GNU" },
    { slug: "skullcandy", name: "Skullcandy" },
] as const;

export default function BrandStrip({
                                       locale,
                                   }: {
    locale: Locale;
}) {
    return (
        <nav
            aria-label={locale === "uk" ? "Бренди" : "Бренды"}
            className="mx-auto mt-12 max-w-[1360px] px-4 xl:px-0"
        >
            <div className="overflow-x-auto pb-2">
                <div className="grid min-w-[1050px] grid-cols-10 gap-3">
                    {figmaBrands.map(({ slug, name }) => (
                        <Link
                            key={slug}
                            href={`/${locale}/brands/${slug}`}
                            title={name}
                            aria-label={`${
                                locale === "uk" ? "Товари бренду" : "Товары бренда"
                            } ${name}`}
                            className="flex h-[84px] min-w-0 items-center justify-center rounded-sm transition-opacity hover:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black"
                        >
                            <Image
                                src={`/figma/brands/${slug}.png`}
                                alt={name}
                                width={120}
                                height={74}
                                sizes="120px"
                                className="h-[74px] w-[120px] object-contain"
                            />
                        </Link>
                    ))}
                </div>
            </div>
        </nav>
    );
}