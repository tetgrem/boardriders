export type PriceList = {
    id: number;
    code: string;
    name: string;
    currency_code: string;
    country_code: string;
    minor_units: number;
};

export type Market = PriceList;

export type Overview = {
    price_lists: PriceList[];
    markets: Market[];
    summary: {
        prices: number;
        promotions: number;
        enabled_promotions: number;
        wallets: number;
    };
};

export type Page<T> = {
    items: T[];
    next_before_id: number | null;
};

export type PriceRow = {
    id: number;
    product_id: number;
    product_name: string | null;
    sku: string;
    barcode: string | null;
    product_status: string;
    is_active: boolean;
    price_minor: number | null;
    compare_at_minor: number | null;
    revision: number;
};

export type Promotion = {
    id: number;
    name: string;
    price_list_id: number;
    price_list_code: string;
    currency_code: string;
    type: "percent" | "fixed";
    value: number;
    min_subtotal_minor: number;
    max_discount_minor: number | null;
    usage_limit: number | null;
    per_customer_limit: number | null;
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
    admin_revision: number;
    used_count?: number;
};

export type PromotionCode = {
    id: number;
    code: string;
    is_active: boolean;
    usage_limit: number | null;
    used_count: number;
    pending_count: number;
};

export type PromotionDetail = {
    promotion: Promotion;
    codes: PromotionCode[];
};

export type Policy = {
    id: number;
    program_code: string;
    rule_version: string;
    is_enabled: boolean;
    bonus_units_per_step: number;
    money_minor_per_step: number;
    max_goods_share_bps: number;
    max_discount_minor: number | null;
    minimum_cash_minor: number;
    effective_from: string;
};

export type ScopeType =
    | "all" | "brand" | "category" | "product" | "variant";

export type Rule = {
    scope_type: ScopeType;
    scope_id: number | null;
    decision: "allow" | "deny";
    priority: number;
};

export type StoredRule = {
    id: number;
    scope_type: ScopeType;
    decision: "allow" | "deny";
    priority: number;
    brand_id: number | null;
    category_id: number | null;
    product_id: number | null;
    variant_id: number | null;
};

export type Policies = {
    items: Policy[];
    active_policy_id: number | null;
    latest: Policy | null;
    rules: StoredRule[];
};

export type ScopeOption = {
    id: number;
    name: string | null;
};

export type Command = {
    action: string;
    [key: string]: unknown;
};

export type CommandResult = {
    event_id: number;
    replayed: boolean;
    result: Record<string, unknown>;
};

export type AuditRow = {
    id: number;
    actor_id: number;
    action: string;
    created_at: string;
    result: Record<string, unknown>;
};

export type SubmitCommand = (command: Command) => void;

export function formText(form: FormData, key: string): string {
    return String(form.get(key) ?? "").trim();
}

export function integer(
    value: string,
    min = 0,
    max = 1000000000
): number {
    if (!/^\d+$/.test(value)) {
        throw new Error("Введите целое число.");
    }

    const result = Number(value);

    if (!Number.isSafeInteger(result)
        || result < min || result > max) {
        throw new Error("Число выходит за допустимый диапазон.");
    }

    return result;
}

export function optionalInteger(
    form: FormData,
    key: string,
    min = 1
): number | null {
    const value = formText(form, key);

    return value === "" ? null : integer(value, min);
}

export function toMinor(
    raw: string,
    digits: number
): number {
    const text = raw.trim().replace(",", ".");

    if (!/^\d+(?:\.\d+)?$/.test(text)) {
        throw new Error("Некорректная денежная сумма.");
    }

    const [whole, fraction = ""] = text.split(".");

    if (fraction.length > digits) {
        throw new Error(
            `Допускается не более ${digits} знаков после запятой.`
        );
    }

    const result = Number(
        whole + fraction.padEnd(digits, "0")
    );

    if (!Number.isSafeInteger(result)
        || result < 0
        || result > 100000000000) {
        throw new Error("Недопустимая денежная сумма.");
    }

    return result;
}

export function optionalMinor(
    form: FormData,
    key: string,
    digits: number
): number | null {
    const raw = formText(form, key);

    return raw === "" ? null : toMinor(raw, digits);
}

export function fromMinor(
    value: number | null | undefined,
    digits: number
): string {
    if (value === null || value === undefined) {
        return "";
    }

    const raw = String(value).padStart(digits + 1, "0");

    if (digits === 0) return raw;

    return raw.slice(0, -digits)
        + "."
        + raw.slice(-digits);
}

export function utcDate(raw: string): string | null {
    if (!raw) return null;

    const date = new Date(raw);

    if (!Number.isFinite(date.getTime())) {
        throw new Error("Некорректная дата.");
    }

    return date.toISOString().replace(/\.\d{3}Z$/, "Z");
}

export const actionLabels: Record<string, string> = {
    prices_save: "Изменение цен",
    promotion_create: "Создание акции",
    promotion_toggle: "Статус акции",
    code_create: "Создание промокода",
    code_toggle: "Статус промокода",
    market_create: "Создание рынка",
    policy_create: "Новая бонусная политика",
    campaign_create: "Создание автоматической акции",
    campaign_toggle: "Изменение статуса автоматической акции",
};