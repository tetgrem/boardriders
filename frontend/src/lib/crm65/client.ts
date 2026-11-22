export type OrderRow = {
  order_id: number;
  order_key: string;
  payment_status: string;
  goods_payable_minor: number | null;
  currency_code: string | null;
  rma_count: number;
  paid_capture_count: number | null;
  journal_count: number | null;
  open_fiscal_case_count: number | null;
};

export type OrderPage = { items: OrderRow[]; next_before_id: number | null };
export type Dashboard = {
  orders_total: number;
  orders_by_payment_status: Record<string, number>;
  rma_cases: number;
  fiscal_issues: number | null;
  confirmed_refund_journal_count: number | null;
};

export type OrderNote = {
  note_id: number; category: string; body: string; written_by: number; created_at: string;
};

export type TimelineItem = {
  source: string; source_id: number; created_at: string; event: string; detail: string | null;
};

export type OrderCard = {
  order_key: string;
  payment_status: string;
  returns: {
    restricted: boolean;
    case_count: number;
    items: Array<{
      case_id: number; return_key: string; reason_code: string; requested_qty: number;
      workflow: string; decision: unknown;
    }>;
  };
  delivery: {
    restricted: boolean;
    tracking_count: number;
    items: Array<{
      tracking_key: string; observed_state: string;
      action_code: string; needs_review: boolean;
    }>;
  };
  finance: { restricted?: boolean; [key: string]: unknown };
  fiscal: {
    restricted: boolean;
    documents: Array<{
      document_id: number; kind: string; status: string; amount_minor: number;
      currency_code: string; ticket: null | {
        status: string; fiscal_code: string | null; provider_done_evidence: boolean;
      };
      incidents: Array<{ case_id: number; reason_code: string; status: string }>;
    }>;
  };
  notes: OrderNote[];
  timeline: { items: TimelineItem[]; truncated: boolean };
};

export class CrmError extends Error {
  constructor(public status: number, message: string) { super(message); }
}

export async function crm<T>(path: string, init?: { method?: "POST"; body?: unknown }): Promise<T> {
  const response = await fetch(`/api/crm65/bridge/${path}`, {
    method: init?.method ?? "GET",
    headers: { Accept: "application/json", ...(init?.method ? { "Content-Type": "application/json" } : {}) },
    ...(init?.method ? { body: JSON.stringify(init.body) } : {}),
    credentials: "same-origin",
    cache: "no-store",
  });
  if (!response.ok) {
    if (response.status === 401) {
      throw new CrmError(
          401,
          "Сессия истекла"
      );
    }
    let message = `Ошибка CRM (${response.status})`;
    try { const b = (await response.json()) as { message?: string }; message = b.message || message; } catch { /* ignore */ }
    throw new CrmError(response.status, message);
  }
  return (await response.json()) as T;
}

export function money(minor?: number | null, currency = "UAH"): string {
  if (typeof minor !== "number") return "Нет доступа";
  return new Intl.NumberFormat("uk-UA", {
    style: "currency", currency, maximumFractionDigits: 2,
  }).format(minor / 100);
}

export function when(text?: string | null): string {
  if (!text) return "—";
  const parsed = Date.parse(text.replace(" ", "T"));
  return Number.isFinite(parsed) ? new Intl.DateTimeFormat("uk-UA", {
    dateStyle: "medium", timeStyle: "short",
  }).format(new Date(parsed)) : text;
}

export function statusLabel(value: string): string {
  const labels: Record<string, string> = {
    paid: "Оплачено", pending: "Ожидает оплаты", review: "На проверке",
    prepared: "Подготовлено", transmitting: "Отправляется",
    uncertain: "Результат неизвестен", provider_pending: "У провайдера",
    needs_review: "Требует проверки", done: "Фискализировано",
    open: "Открыто", acknowledged: "Принято", closed: "Закрыто",
    awaiting_inspection: "Осмотр", awaiting_review: "На рассмотрении",
    awaiting_separate_execution: "К исполнению", closed_rejected: "Отклонено",
  };
  return labels[value] ?? value.replaceAll("_", " ");
}
