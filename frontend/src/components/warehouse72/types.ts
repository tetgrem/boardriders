export type DocumentKind =
    | "receipt"
    | "transfer"
    | "stocktake"
    | "writeoff";

export type Warehouse = {
    id: number;
    code: string;
    name: string;
    kind: "owned" | "partner";
    country_code: string;
    city: string | null;
    address: string | null;
    is_active: boolean;
    admin_revision: number;
};

export type Overview = {
    summary: {
        on_hand: number;
        reserved: number;
        available: number;
        active_warehouses: number;
    };
    warehouses: Warehouse[];
};

export type Variant = {
    id: number;
    product_id: number;
    sku: string;
    barcode: string | null;
    product_name: string | null;
    product_status: string;
    is_active: boolean;
    on_hand_qty: number;
    reserved_qty: number;
    available_qty: number;
    revision: number;
};

export type Stock = Variant & {
    variant_id: number;
    warehouse_id: number;
    warehouse_name: string;
    warehouse_kind: string;
    reserve_consistent: boolean;
};

export type Movement = {
    id: number;
    type: string;
    quantity_delta: number;
    reference: string | null;
    sku: string;
    product_name: string | null;
    warehouse_name: string;
    created_at: string;
};

export type Reservation = {
    id: number;
    sku: string;
    product_name: string | null;
    warehouse_name: string;
    quantity: number;
    shipped_qty: number;
    released_qty: number;
    active_qty: number;
    order_key: string | null;
    expires_at: string | null;
};

export type DocumentRow = {
    id: number;
    kind: string;
    actor_id: number;
    warehouse_id: number | null;
    reference: string | null;
    created_at: string;
    line_count: number;
};

export type DocumentDetail = {
    document_id: number;
    kind: string;
    actor_id: number;
    operation_key: string;
    reference: string | null;
    created_at: string;
    request: Record<string, unknown>;
    result: Record<string, unknown>;
};

export type Page<T> = {
    items: T[];
    next_before_id: number | null;
};

export type DocumentBody = Record<string, unknown> & {
    kind: string;
};

export type PostedDocument = {
    document_id: number;
    kind: string;
    replayed: boolean;
    result: Record<string, unknown>;
};

export const documentLabels: Record<string, string> = {
    receipt: "Приход товара",
    transfer: "Перемещение",
    stocktake: "Инвентаризация",
    writeoff: "Списание",
    warehouse_create: "Создание склада",
    warehouse_update: "Изменение склада",
};

export const movementLabels: Record<string, string> = {
    receipt: "Приход",
    sale: "Продажа",
    customer_return: "Возврат покупателя",
    supplier_return: "Возврат поставщику",
    adjustment: "Корректировка",
    transfer_in: "Перемещение — приход",
    transfer_out: "Перемещение — расход",
};