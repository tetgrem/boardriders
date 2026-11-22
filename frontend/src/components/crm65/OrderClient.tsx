"use client";

import Link from "next/link";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import {CrmError, crm, money, statusLabel, when, type OrderCard, type TimelineItem } from "@/lib/crm65/client";
import type { StaffIdentity } from "@/lib/crm65/session";
import { useRouter } from "next/navigation";

type Section = "overview" | "finance" | "returns" | "delivery" | "fiscal" | "timeline" | "notes" | "audit";
type Financial = {
  financial_snapshot: null | { goods_payable_minor: number; currency_code: string; lines: unknown[] };
  accepted_capture_total_minor: number;
  provider_confirmed_refund_minor: number;
  journaled_refund_minor: number;
  card_settlement_confirmed: boolean;
  captures: Array<{ receipt_id: number; amount_minor: number; currency_code: string; accepted_as_paid: boolean; provider_code: string }>;
  refunds: Array<{ execution_id: number; amount_minor: number; status: string; provider_refund_confirmed: boolean; journal_id: number | null }>;
};
type AuditItem = { audit_id: number; actor_id: number; operation: string; created_at: string };

export default function OrderClient({ orderKey, staff }: { orderKey: string; staff: StaffIdentity }) {
  const router = useRouter();
  const [card, setCard] = useState<OrderCard | null>(null);
  const [section, setSection] = useState<Section>("overview");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(true);
  const [revision, setRevision] = useState(0);
  const [note, setNote] = useState("");
  const [category, setCategory] = useState("service");
  const [saving, setSaving] = useState(false);
  const [audit, setAudit] = useState<AuditItem[] | null>(null);
  const [timeline, setTimeline] = useState<TimelineItem[] | null>(null);
  const [noteError, setNoteError] = useState<string | null>(null);
  const canFinance = staff.abilities.includes("crm64.finance.view");
  const canFiscal = staff.abilities.includes("crm64.fiscal.view");
  const canNotes = staff.abilities.includes("crm64.notes.write");
  const canReport = staff.abilities.includes("crm64.reporting.view");

  const reload = useCallback(() => crm<OrderCard>(`orders/${orderKey}`), [orderKey]);
  useEffect(() => {
    let active = true;

    reload()
        .then((data) => {
          if (!active) return;

          setCard(data);
          setError(null);
        })
        .catch((error: unknown) => {
          if (!active) return;

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
                  : "Ошибка карточки"
          );
        })
        .finally(() => {
          if (active) {
            setBusy(false);
          }
        });

    return () => {
      active = false;
    };
  }, [reload, revision, router]);

  useEffect(() => {
    if (section === "timeline") {
      crm<{ items: TimelineItem[] }>(`orders/${orderKey}/timeline?limit=100`)
        .then(r => setTimeline(r.items)).catch(() => setTimeline([]));
    }
    if (section === "audit" && canReport) {
      crm<AuditItem[]>(`orders/${orderKey}/access?limit=100`)
        .then(setAudit).catch(() => setAudit([]));
    }
  }, [section, orderKey, revision, canReport]);

  async function saveNote(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!note.trim()) return;
    setSaving(true); setNoteError(null);
    try {
      await crm(`orders/${orderKey}/notes`, {
        method: "POST",
        body: { operation_key: crypto.randomUUID(), category, body: note.trim() },
      });
      setNote("");
      setBusy(true);
      setError(null);
      setRevision((v) => v + 1);
    } catch (e) { setNoteError(e instanceof Error ? e.message : "Не удалось добавить заметку"); }
    finally { setSaving(false); }
  }

  const tabs: Array<[Section, string, boolean]> = [
    ["overview", "Обзор", true], ["finance", "Финансы", canFinance],
    ["returns", "Возвраты", true], ["delivery", "Доставка", true],
    ["fiscal", "Фискализация", canFiscal], ["timeline", "Хронология", true],
    ["notes", "Заметки", true], ["audit", "История доступа", canReport],
  ];
  const finance = card?.finance as unknown as Financial | undefined;

  return <div>
    <Link href="/admin/orders" className="crm65-back">← Вернуться к списку</Link>
    <div className="crm65-pagehead"><div><p className="crm65-overline">КАРТОЧКА ЗАКАЗА</p><h1>Заказ <span className="crm65-orange">{orderKey.slice(0, 8)}</span></h1><p className="crm65-code crm65-small">{orderKey}</p></div><div className="crm65-head-actions">{card && <span className={`crm65-pill ${card.payment_status === "paid" ? "is-ok" : ""}`}>{statusLabel(card.payment_status)}</span>}<button onClick={() => {
      setBusy(true);
      setError(null);
      setRevision((v) => v + 1);
    }} className="crm65-secondary">↻ Обновить</button></div></div>
    {error && <div className="crm65-error" role="alert">{error}</div>}
    {busy && !card && <div className="crm65-panel crm65-loading">Загружаем заказ...</div>}
    {card && <><nav className="crm65-tabs" role="tablist" aria-label="Разделы заказа">{tabs.filter(t => t[2]).map(([id, text]) => <button key={id} type="button" role="tab" aria-selected={section === id} className={section === id ? "active" : ""} onClick={() => setSection(id)}>{text}</button>)}</nav>
      <section className="crm65-panel crm65-detail-panel">
        {section === "overview" && <><h2>Информация о заказе</h2><div className="crm65-facts"><Fact label="Номер заказа" value={card.order_key} /><Fact label="Статус оплаты" value={statusLabel(card.payment_status)} /><Fact label="Возвратов RMA" value={String(card.returns.case_count)} /><Fact label="ТТН" value={String(card.delivery.tracking_count)} /><Fact label="Финансы" value={canFinance ? "Доступны" : "Ограничен доступ"} /><Fact label="Фискализация" value={canFiscal ? "Доступна" : "Ограничен доступ"} /></div><div className="crm65-soft-note">Это операционная карточка. Наличие события ТТН не означает вручения посылки, а одобрение RMA не означает возврат денег.</div></>}
        {section === "finance" && canFinance && finance && <><h2>Финансовая история</h2>{finance.financial_snapshot ? <div className="crm65-money-grid"><Metric title="Стоимость товаров" amount={money(finance.financial_snapshot.goods_payable_minor, finance.financial_snapshot.currency_code)} /><Metric title="Подтверждённая оплата" amount={money(finance.accepted_capture_total_minor)} /><Metric title="Возврат подтверждён провайдером" amount={money(finance.provider_confirmed_refund_minor)} /><Metric title="Возврат в журнале" amount={money(finance.journaled_refund_minor)} /></div> : <p className="crm65-muted">Финансовый snapshot отсутствует.</p>}<h3>Зачисления</h3>{finance.captures.length === 0 ? <Empty /> : finance.captures.map(x => <div className="crm65-listrow" key={x.receipt_id}><div><strong>Capture #{x.receipt_id}</strong><small>{x.provider_code}</small></div><span>{money(x.amount_minor, x.currency_code)} · {x.accepted_as_paid ? "Принят" : "Ожидает проверки"}</span></div>)}<h3>Возвраты</h3>{finance.refunds.length === 0 ? <Empty /> : finance.refunds.map(x => <div className="crm65-listrow" key={x.execution_id}><div><strong>Возврат #{x.execution_id}</strong><small>Журнал: {x.journal_id ?? "пока нет"}</small></div><span>{money(x.amount_minor)} · {statusLabel(x.status)}</span></div>)}<p className="crm65-soft-note">Подтверждение провайдера не доказывает окончательное зачисление на карту клиента.</p></>}
        {section === "returns" && <><h2>Возвраты (RMA)</h2>{card.returns.restricted ? <Restricted /> : card.returns.items.length === 0 ? <Empty /> : card.returns.items.map(r => <div className="crm65-listrow" key={r.case_id}><div><strong>RMA #{r.case_id}</strong><small>Причина: {r.reason_code}; количество: {r.requested_qty}</small></div><span className="crm65-pill">{statusLabel(r.workflow)}</span></div>)}<p className="crm65-soft-note">Решение по возврату не подтверждает фактическую выплату.</p></>}
        {section === "delivery" && <><h2>Доставка / Новая почта</h2>{card.delivery.restricted ? <Restricted /> : card.delivery.items.length === 0 ? <Empty /> : card.delivery.items.map(d => <div className="crm65-listrow" key={d.tracking_key}><div><strong>{d.tracking_key}</strong><small>Действие: {statusLabel(d.action_code)}</small></div><span className={`crm65-pill ${d.needs_review ? "is-warning" : ""}`}>{statusLabel(d.observed_state)}</span></div>)}<p className="crm65-soft-note">Данные являются наблюдениями перевозчика; вручение и наложенный платёж отдельно не подтверждены.</p></>}
        {section === "fiscal" && canFiscal && <><h2>Checkbox / ПРРО</h2>{card.fiscal.restricted ? <Restricted /> : card.fiscal.documents.length === 0 ? <Empty /> : card.fiscal.documents.map(d => <div className="crm65-fiscal-card" key={d.document_id}><div className="crm65-listrow"><div><strong>Документ #{d.document_id} · {d.kind === "sale" ? "Продажа" : "Возврат"}</strong><small>Сумма: {money(d.amount_minor, d.currency_code)}</small></div><span className="crm65-pill">{d.ticket ? statusLabel(d.ticket.status) : "Черновик"}</span></div>{d.ticket && <p>Фискальный номер: <strong>{d.ticket.provider_done_evidence ? d.ticket.fiscal_code : "Не подтверждён"}</strong></p>}{d.incidents.map(i => <p className="crm65-error" key={i.case_id}>Инцидент #{i.case_id}: {statusLabel(i.status)} — {i.reason_code}</p>)}</div>)}<p className="crm65-soft-note">ПРРО: только просмотр подтверждённых событий. Отправка чеков здесь не выполняется.</p></>}
        {section === "timeline" && <><h2>Хронология</h2>{(timeline ?? card.timeline.items).length === 0 ? <Empty /> : (timeline ?? card.timeline.items).map((e, index) => <div className="crm65-event" key={`${e.source}-${e.source_id}-${index}`}><span className="crm65-event-dot"/><div><strong>{statusLabel(e.event)}</strong><p>{e.source} {e.detail ? `· ${e.detail}` : ""}</p></div><time>{when(e.created_at)}</time></div>)}</>}
        {section === "notes" && <><h2>Внутренние заметки</h2>{canNotes && <form className="crm65-noteform" onSubmit={saveNote}><label>Категория<select value={category} onChange={e => setCategory(e.target.value)}><option value="service">Обслуживание</option><option value="delivery">Доставка</option><option value="return">Возврат</option>{canFinance && <option value="finance">Финансы</option>}{canFiscal && <option value="fiscal">Фискализация</option>}</select></label><label>Текст заметки<textarea minLength={4} maxLength={1000} required value={note} onChange={e => setNote(e.target.value)} placeholder="Что произошло, что нужно сделать дальше..." /></label><button type="submit" className="crm65-primary" disabled={saving}>{saving ? "Сохранение..." : "Добавить заметку"}</button>{noteError && <p role="alert" className="crm65-error">{noteError}</p>}</form>}{card.notes.length === 0 ? <Empty /> : card.notes.map(n => <div className="crm65-listrow crm65-note" key={n.note_id}><div><small>{n.category} · сотрудник #{n.written_by} · {when(n.created_at)}</small><p>{n.body}</p></div></div>)}</>}
        {section === "audit" && canReport && <><h2>История просмотров</h2>{audit === null ? <p className="crm65-muted">Загрузка...</p> : audit.length === 0 ? <Empty /> : audit.map(a => <div className="crm65-listrow" key={a.audit_id}><div><strong>{statusLabel(a.operation)}</strong><small>Сотрудник #{a.actor_id}</small></div><time>{when(a.created_at)}</time></div>)}</>}
      </section></>}
  </div>;
}

function Fact({ label, value }: { label: string; value: string }) { return <div className="crm65-fact"><span>{label}</span><strong>{value}</strong></div>; }
function Metric({ title, amount }: { title: string; amount: string }) { return <div className="crm65-money-metric"><span>{title}</span><strong>{amount}</strong></div>; }
function Empty() { return <p className="crm65-empty">Пока нет записей.</p>; }
function Restricted() { return <p className="crm65-empty">Для просмотра этого раздела нужны дополнительные права.</p>; }
