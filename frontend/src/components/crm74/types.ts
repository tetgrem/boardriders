export type Segment =
    | "prospect"
    | "new"
    | "regular"
    | "vip"
    | "inactive";

export type Page<T> = {
    items: T[];
    next_before_id: number | null;
};

export type Customer = {
    id: number;
    name: string;
    email: string;
    email_verified: boolean;
    created_at: string;
    orders_count: number;
    paid_count: number;
    last_paid_order_at: string | null;
    segment: Segment;
};

export type Overview = {
    customers_total: number;
    segments: Record<Segment, number>;
    guest_orders_count: number;
    vip_paid_orders: number;
    inactive_days: number;
};

export type Contact = {
    contact_id: number | null;
    phone: string | null;
    city: string | null;
    address: string | null;
    preferred_channel: string;
    updated_at: string | null;
    customer_verified: boolean;
};

export type CurrencyStatistics = {
    currency_code: string;
    minor_units: number;
    paid_orders: number;
    goods_total_minor: string;
    average_goods_minor: string;
};

export type Profile = {
    customer: Customer;
    contact: Contact;

    statistics: {
        financial_restricted: boolean;
        currencies: CurrencyStatistics[];
    };

    can_manage: boolean;
    can_view_returns: boolean;
    can_view_access: boolean;
};

export type Order = {
    id: number;
    order_key: string;
    payment_status: string;
    created_at: string;
    goods_payable_minor: string | null;
    currency_code: string | null;
    minor_units: number | null;
};

export type ReturnCase = {
    id: number;
    return_key: string;
    reason_code: string;
    created_at: string;
    order_key: string;
};

export type Wallet = {
    id: number;
    market_code: string;
    market_name: string;
    currency_code: string;
    status: string;
    market_active: boolean;

    balance: {
        pending_units: number;
        available_units: number;
        reserved_units: number;
        debt_units: number;
        spendable_units: number;
        blocked_reason: string | null;
        calculated_at: string;
    } | null;
};

export type LedgerEntry = {
    id: number;
    entry_type: string;
    origin: string;
    pending_delta: number;
    available_delta: number;
    reserved_delta: number;
    debt_delta: number;
    occurred_at: string;
};

export type CustomerEvent = {
    id: number;
    kind: string;
    actor_id: number;
    actor_name: string | null;
    created_at: string;

    payload: {
        body?: string;
        follow_up_at?: string | null;
        section?: string;
    };
};

export type WriteCommand = {
    kind: string;
    [key: string]: unknown;
};

export type WriteResult = {
    event_id: number;
    kind: string;
    replayed: boolean;
};

export const segmentLabels: Record<Segment, string> = {
    prospect: "Потенциальный",
    new: "Новый",
    regular: "Постоянный",
    vip: "VIP",
    inactive: "Неактивный",
};

export const eventLabels: Record<string, string> = {
    contact: "Изменение контактов",
    note: "Внутренняя заметка",
    call: "Телефонный звонок",
    message: "Переписка",
    complaint: "Обращение / жалоба",
    offer: "Персональное предложение",
    view: "Просмотр данных",
};

export const sectionLabels: Record<string, string> = {
    profile: "Профиль",
    orders: "Заказы",
    returns: "Возвраты",
    wallets: "Бонусные кошельки",
    ledger: "История бонусов",
    history: "Взаимодействия",
    contact: "Контакты",
    access: "Журнал доступа",
};

export const channelLabels: Record<string, string> = {
    none: "Не указан",
    phone: "Телефон",
    email: "Email",
    telegram: "Telegram",
    viber: "Viber",
    whatsapp: "WhatsApp",
};

export function formatMoney(
    amount: string | null,
    currency: string | null,
    digits = 2
): string {
    if (amount === null || currency === null) {
        return "—";
    }

    const value = String(amount);

    if (!/^\d+$/.test(value)) {
        return "—";
    }

    const padded = value.padStart(digits + 1, "0");

    const whole = digits === 0
        ? padded
        : padded.slice(0, -digits);

    const fraction = digits === 0
        ? ""
        : "," + padded.slice(-digits);

    const grouped = whole.replace(
        /\B(?=(\d{3})+(?!\d))/g,
        " "
    );

    return `${grouped}${fraction} ${currency === "UAH" ? "₴" : currency}`;
}

export function signedUnits(value: number): string {
    return value > 0 ? `+${value}` : String(value);
}