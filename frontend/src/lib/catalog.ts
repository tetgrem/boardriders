export type Locale = "uk" | "ru";

export type Money = {
    amount_minor: number;
    compare_at_minor?: number | null;
    currency: string;
};

export type Stock = {
    available: number;
    in_stock: boolean;
};

export type ProductImage = {
    id: number;
    url: string;
    alt: string;
    is_primary: boolean;
    sort_order: number;
};

export type ProductSummary = {
    id: number;
    name: string;
    slug: string;
    short_description: string | null;
    brand: {
        id: number;
        name: string;
        slug: string;
    } | null;
    primary_category: {
        id: number;
        name: string;
        slug: string;
    } | null;
    image: {
        url: string;
        alt: string;
    } | null;
    price_from: Money;
    stock: Stock;
};

export type ProductVariant = {
    id: number;
    sku: string;
    barcode: string | null;
    price: Money;
    stock: Stock;
    size_eu: {
        system: "EU";
        code: string;
        label: string;
    } | null;
};

export type ProductDetail = ProductSummary & {
    description: string | null;
    seo: {
        title: string | null;
        description: string | null;
    };
    images: ProductImage[];
    variants: ProductVariant[];
    sizes_eu: {
        code: string;
        label: string;
        available: number;
        in_stock: boolean;
    }[];
};

const API_URL = (
    process.env.API_INTERNAL_URL ??
    "http://127.0.0.1:8000"
).replace(/\/+$/, "");

export async function getProducts(
    locale: Locale,
    brandSlug?: string
): Promise<ProductSummary[]> {
    const params = new URLSearchParams({
        locale,
        per_page: "24",
    });

    if (brandSlug) {
        params.set("brand", brandSlug);
    }

    const response = await fetch(
        `${API_URL}/api/v1/catalog/products?${params.toString()}`,
        {
            cache: "no-store",
            headers: {
                Accept: "application/json",
            },
        }
    );

    if (!response.ok) {
        throw new Error(
            `Laravel catalog API error: ${response.status}`
        );
    }

    const body = (await response.json()) as {
        data: ProductSummary[];
    };

    return body.data;
}

export async function getProduct(
    locale: Locale,
    slug: string
): Promise<ProductDetail | null> {
    const url =
        `${API_URL}/api/v1/catalog/products/` +
        `${encodeURIComponent(slug)}?locale=${locale}`;

    const response = await fetch(url, {
        cache: "no-store",
        headers: { Accept: "application/json" },
    });

    if (response.status === 404) {
        return null;
    }

    if (!response.ok) {
        throw new Error(
            `Laravel product API error: ${response.status}`
        );
    }

    const body = (await response.json()) as {
        data: ProductDetail;
    };

    return body.data;
}

export function formatMoney(
    amountMinor: number,
    currency: string,
    _locale: Locale
): string {
    const symbols: Record<string, string> = {
        UAH: "₴",
        USD: "$",
        EUR: "€",
    };

    const symbol = symbols[currency] ?? currency;

    const negative = amountMinor < 0 ? "-" : "";
    const absolute = Math.abs(amountMinor);

    const whole = Math.floor(absolute / 100)
        .toString()
        .replace(/\B(?=(\d{3})+(?!\d))/g, "\u00A0");

    const fraction = String(absolute % 100).padStart(2, "0");

    return `${negative}${whole},${fraction}\u00A0${symbol}`;
}

export type CatalogSort =
    | "newest"
    | "price_asc"
    | "price_desc";

export type CatalogFilters = {
    brand?: string;
    onlyInStock?: boolean;
    sort?: CatalogSort;
    cursor?: string;
};

export type CatalogPage = {
    data: ProductSummary[];
    meta: {
        per_page: number;
        has_more: boolean;
        next_cursor: string | null;
        previous_cursor: string | null;
    };
};

export type CatalogBrand = {
    id: number;
    name: string;
    slug: string;
};

export async function getCatalogPage(
    locale: Locale,
    filters: CatalogFilters = {},
): Promise<CatalogPage> {
    const params = new URLSearchParams({
        locale,
        per_page: "24",
    });

    if (filters.brand) {
        params.set("brand", filters.brand);
    }

    if (filters.onlyInStock) {
        params.set("in_stock", "1");
    }

    if (filters.sort) {
        params.set("sort", filters.sort);
    }

    if (filters.cursor) {
        params.set("cursor", filters.cursor);
    }

    const response = await fetch(
        `${API_URL}/api/v1/catalog/products?${params.toString()}`,
        {
            cache: "no-store",
            headers: {
                Accept: "application/json",
            },
        },
    );

    if (!response.ok) {
        throw new Error(
            `Laravel catalog API error: ${response.status}`,
        );
    }

    return (await response.json()) as CatalogPage;
}

export async function getCatalogBrands(): Promise<CatalogBrand[]> {
    const response = await fetch(
        `${API_URL}/api/v1/catalog/brands`,
        {
            cache: "no-store",
            headers: {
                Accept: "application/json",
            },
        },
    );

    if (!response.ok) {
        throw new Error(
            `Laravel brands API error: ${response.status}`,
        );
    }

    const body = (await response.json()) as {
        data: CatalogBrand[];
    };

    return body.data;
}