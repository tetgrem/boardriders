"use client";

import Link from "next/link";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { CrmError, crm, money, statusLabel, type OrderPage } from "@/lib/crm65/client";
import type { StaffIdentity } from "@/lib/crm65/session";
import { useRouter } from "next/navigation";

type Filters = { key: string; status: string; rma: string; fiscal: string };

export default function OrdersClient({ staff }: { staff: StaffIdentity }) {
  const router = useRouter();
  const [draft, setDraft] = useState<Filters>({ key: "", status: "", rma: "", fiscal: "" });
  const [filters, setFilters] = useState(draft);
  const [cursor, setCursor] = useState<number | null>(null);
  const [stack, setStack] = useState<Array<number | null>>([]);
  const [page, setPage] = useState<OrderPage | null>(null);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [revision, setRevision] = useState(0);
  const canFinance = staff.abilities.includes("crm64.finance.view");
  const canFiscal = staff.abilities.includes("crm64.fiscal.view");

  const reload = useCallback(async () => {
    const query = new URLSearchParams({ limit: "20" });
    if (filters.key.trim()) query.set("order_key", filters.key.trim());
    if (filters.status) query.set("payment_status", filters.status);
    if (filters.rma) query.set("has_rma", filters.rma);
    if (canFiscal && filters.fiscal) query.set("has_fiscal_issue", filters.fiscal);
    if (cursor !== null) query.set("before_id", String(cursor));
    return crm<OrderPage>(`orders?${query.toString()}`);
  }, [filters, cursor, canFiscal]);

  useEffect(() => {
    let live = true;

    reload()
        .then((result) => {
          if (!live) return;

          setPage(result);
          setError(null);
        })
        .catch((error: unknown) => {
          if (!live) return;

          if (
              error instanceof CrmError &&
              error.status === 401
          ) {
            router.replace("/admin/login");
            return;
          }

          setError(
              error instanceof Error
                  ? error.message
                  : "Ошибка загрузки"
          );
        })
        .finally(() => {
          if (live) {
            setBusy(false);
          }
        });

    return () => {
      live = false;
    };
  }, [reload, revision, router]);

  function markLoading(): void {
    setBusy(true);
    setError(null);
  }

  function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    markLoading();
    setCursor(null); setStack([]); setFilters({ ...draft });
    setRevision(v => v + 1);
  }

  function reset() {
    markLoading();
    const clean = { key: "", status: "", rma: "", fiscal: "" };
    setDraft(clean); setFilters(clean); setCursor(null); setStack([]);
    setRevision(v => v + 1);
  }

  return <div>
    <div className="crm65-pagehead"><div><p className="crm65-overline">ORDER MANAGEMENT</p><h1>Заказы</h1><p>Единая очередь заказов и связанных процессов.</p></div><button onClick={() => {
      markLoading();
      setRevision((x) => x + 1);
    }} className="crm65-secondary">↻ Обновить</button></div>
    <form onSubmit={submit} className="crm65-filterbar" aria-label="Фильтры заказов">
      <label className="crm65-search-label"><span>Найти по номеру</span><input value={draft.key} onChange={e => setDraft(v => ({ ...v, key: e.target.value }))} placeholder="UUID или начало UUID" maxLength={36} /></label>
      <label><span>Оплата</span><select value={draft.status} onChange={e => setDraft(v => ({ ...v, status: e.target.value }))}><option value="">Все</option><option value="paid">Оплачено</option><option value="pending">Ожидание</option><option value="review">Проверка</option></select></label>
      <label><span>Возвраты</span><select value={draft.rma} onChange={e => setDraft(v => ({ ...v, rma: e.target.value }))}><option value="">Все</option><option value="true">Есть RMA</option><option value="false">Нет RMA</option></select></label>
      {canFiscal && <label><span>Фискальные проблемы</span><select value={draft.fiscal} onChange={e => setDraft(v => ({ ...v, fiscal: e.target.value }))}><option value="">Все</option><option value="true">Есть проблемы</option><option value="false">Нет проблем</option></select></label>}
      <button type="submit" className="crm65-primary">Применить</button><button type="button" className="crm65-ghost" onClick={reset}>Сброс</button>
    </form>
    <section className="crm65-panel crm65-table-panel">
      <div className="crm65-panel-head"><h2>Список заказов</h2><span className="crm65-muted">По убыванию ID · 20 на страницу</span></div>
      {error && <p className="crm65-error" role="alert">{error}</p>}
      <div className="crm65-table-scroll"><table className="crm65-table"><thead><tr><th>Заказ</th><th>Оплата</th><th>Сумма</th><th>Возвраты</th>{canFinance && <th>Платежи</th>}{canFiscal && <th>ПРРО</th>}<th /></tr></thead>
        <tbody>{!busy && (page?.items.length ?? 0) === 0 && <tr><td colSpan={canFinance && canFiscal ? 7 : canFinance || canFiscal ? 6 : 5} className="crm65-empty">Заказы по заданным фильтрам не найдены.</td></tr>}
          {(page?.items ?? []).map(order => <tr key={order.order_id}><td><strong>#{order.order_id}</strong><span className="crm65-small crm65-muted crm65-code">{order.order_key.slice(0, 18)}...</span></td><td><span className={`crm65-pill ${order.payment_status === "paid" ? "is-ok" : ""}`}>{statusLabel(order.payment_status)}</span></td><td><strong>{money(order.goods_payable_minor, order.currency_code ?? "UAH")}</strong></td><td>{order.rma_count}</td>{canFinance && <td>{order.paid_capture_count ?? "—"}</td>}{canFiscal && <td>{order.open_fiscal_case_count ?? "—"}</td>}<td className="crm65-cell-action"><Link href={`/admin/orders/${encodeURIComponent(order.order_key)}`}>Открыть ↗</Link></td></tr>)}
        </tbody></table></div>
      {busy && <div className="crm65-table-loading">Обновляем список...</div>}
      <div className="crm65-table-footer"><span className="crm65-muted">{page?.items.length ?? 0} строк на этой странице</span><div className="crm65-pagination"><button disabled={busy || stack.length === 0} onClick={() => { markLoading(); const copy = [...stack]; const prev = copy.pop() ?? null; markLoading(); setStack(copy); setCursor(prev); }}>← Назад</button><button disabled={busy || !page?.next_before_id} onClick={() => { setStack(v => [...v, cursor]); setCursor(page?.next_before_id ?? null); }}>Далее →</button></div></div>
    </section>
  </div>;
}
