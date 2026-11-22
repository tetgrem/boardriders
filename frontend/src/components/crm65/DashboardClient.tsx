"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { CrmError, crm, type Dashboard, statusLabel } from "@/lib/crm65/client";
import { useRouter } from "next/navigation";

export default function DashboardClient() {
  const router = useRouter();
  const [data, setData] = useState<Dashboard | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    let active = true;

    crm<Dashboard>("dashboard")
        .then((data) => {
          if (active) {
            setData(data);
          }
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
                  : "Ошибка"
          );
        });

    return () => {
      active = false;
    };
  }, [revision, router]);

  const paid = data?.orders_by_payment_status.paid ?? 0;
  const pending = data?.orders_by_payment_status.pending ?? 0;
  const review = data?.orders_by_payment_status.review ?? 0;
  const cards = [
    { label: "Всего заказов", number: data?.orders_total, mark: "01", emphasis: true },
    { label: "Оплачено", number: paid, mark: "02" },
    { label: "Ожидают оплаты", number: pending, mark: "03" },
    { label: "На проверке", number: review, mark: "04" },
    { label: "Возвраты RMA", number: data?.rma_cases, mark: "05" },
    { label: "Проблемы ПРРО", number: data?.fiscal_issues, mark: "06" },
  ];

  return (
    <div>
      <div className="crm65-pagehead"><div><p className="crm65-overline">ОПЕРАЦИОННЫЙ ЦЕНТР</p><h1>Обзор магазина</h1><p>Текущая картина по заказам и связанным операциям.</p></div><button className="crm65-secondary" onClick={() => { setError(null); setRevision(v => v + 1); }}>↻ Обновить</button></div>
      {error && <div className="crm65-error" role="alert">{error}</div>}
      <div className="crm65-stat-grid">{cards.map(c => <div key={c.mark} className={`crm65-stat ${c.emphasis ? "crm65-stat-accent" : ""}`}><div className="crm65-stat-top"><span>{c.label}</span><span className="crm65-stat-number">{c.mark}</span></div><strong>{data ? (c.number ?? "—") : "· · ·"}</strong><span className="crm65-stat-bottom">{c.number === null ? "Нет доступа" : "Данные CRM"}</span></div>)}</div>
      <div className="crm65-two-columns">
        <section className="crm65-panel"><div className="crm65-panel-head"><h2>Состояния оплаты</h2><span className="crm65-muted">Вся база</span></div><div className="crm65-bars">{data ? Object.entries(data.orders_by_payment_status).map(([state, amount]) => <div key={state} className="crm65-bar-item"><div><strong>{statusLabel(state)}</strong><span>{amount}</span></div><div className="crm65-track"><i style={{ width: `${Math.max(3, Math.round(amount / Math.max(1, data.orders_total) * 100))}%` }} /></div></div>) : <p className="crm65-muted">Загрузка...</p>}</div></section>
        <section className="crm65-panel crm65-action-panel"><p className="crm65-overline">БЫСТРЫЙ ДОСТУП</p><h2>Рабочая очередь</h2><p>Перейди к заказам, открой карточку, проверь возврат или добавь внутреннюю заметку.</p><Link href="/admin/orders" className="crm65-primary crm65-action-link">Открыть заказы <span>↗</span></Link><p className="crm65-muted crm65-small">Данные оплаты и фискализации отображаются только при наличии роли.</p></section>
      </div>
    </div>
  );
}
