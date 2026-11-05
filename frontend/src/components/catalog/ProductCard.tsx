import Link from "next/link";

import {
    formatMoney,
    type Locale,
    type ProductSummary,
} from "@/lib/catalog";

import { productImageUrl } from "@/lib/media";

type Props = {
    product: ProductSummary;
    locale: Locale;
};

export default function ProductCard({
                                        product,
                                        locale,
                                    }: Props) {
    const current = product.price_from.amount_minor;
    const previous = product.price_from.compare_at_minor;

    const onSale =
        previous != null && previous > current;

    const discount =
        onSale && previous != null
            ? Math.round(((previous - current) / previous) * 100)
            : 0;

    const href =
        `/${locale}/products/${encodeURIComponent(product.slug)}`;

    const outOfStock =
        locale === "uk"
            ? "Немає в наявності"
            : "Нет в наличии";

    return (
        <Link
            href={href}
            className="group block min-w-0 text-black"
        >
            {/* Область фотографії — 320 × 370 */}
            <div className="relative aspect-[320/370] overflow-hidden bg-[#F9F9F9] group-hover:bg-[#F2F2F2]">

                {product.image ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                        src={productImageUrl(product.image.url)}
                        alt={product.image.alt || product.name}
                        loading="lazy"
                        className="h-full w-full object-contain p-5 transition-transform duration-300 group-hover:scale-[1.035] sm:p-6"
                    />
                ) : (
                    <div className="flex h-full flex-col items-center justify-center gap-1 text-neutral-400">
            <span className="text-4xl font-black tracking-[-0.09em]">
              B.
            </span>

                        <span className="text-[10px] tracking-[0.2em]">
              BOARDRIDERS
            </span>
                    </div>
                )}

                {/* Значок обраного: поки декоративний */}
                <span
                    aria-hidden="true"
                    className="absolute right-2 top-2 flex h-10 w-10 items-center justify-center bg-white/80 text-black"
                >
          <svg
              width="22"
              height="22"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.6"
              strokeLinecap="round"
              strokeLinejoin="round"
          >
            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78Z" />
          </svg>
        </span>

                {!product.stock.in_stock && (
                    <span className="absolute left-2 top-2 max-w-[70%] bg-white/90 px-2 py-1 text-[11px] font-medium text-[#828B8D]">
            {outOfStock}
          </span>
                )}

                {onSale && (
                    <span className="absolute bottom-0 left-0 flex h-[25px] min-w-[55px] items-center justify-center bg-[#C4C4C4] px-2 text-[14px] font-medium leading-none text-black">
            -{discount}%
          </span>
                )}
            </div>

            {/* Інформація про товар */}
            <div className="pt-[10px]">

                <p className="truncate text-[16px] font-medium leading-[18px]">
                    {product.brand?.name ?? "BOARDRIDERS"}
                </p>

                <h3 className="mt-[5px] line-clamp-1 text-[14px] font-medium leading-[18px] text-[#828B8D]">
                    {product.name}
                </h3>

                <div className="mt-[8px] flex min-h-[30px] flex-wrap items-baseline gap-x-3 gap-y-0.5">

                    {onSale && previous != null && (
                        <span className="text-[15px] leading-[24px] text-[#828B8D] line-through">
              {formatMoney(
                  previous,
                  product.price_from.currency,
                  locale
              )}
            </span>
                    )}

                    <span className="text-[18px] font-medium leading-[30px]">
            {formatMoney(
                current,
                product.price_from.currency,
                locale
            )}
          </span>
                </div>
            </div>
        </Link>
    );
}