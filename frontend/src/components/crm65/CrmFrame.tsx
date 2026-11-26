"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState, type ReactNode } from "react";
import type { StaffIdentity } from "@/lib/crm65/session";
import NotificationBadge70 from "@/components/crm70/NotificationBadge70";

export default function CrmFrame({ staff, children }: {
  staff: StaffIdentity; children: ReactNode;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const [busy, setBusy] = useState(false);
  const [menu, setMenu] = useState(false);
  const canReport = staff.abilities.includes("crm64.reporting.view");

  async function logout() {
    setBusy(true);
    try {
      const response = await fetch("/api/crm65/session", { method: "DELETE", credentials: "same-origin" });
      if (!response.ok) throw new Error("Не удалось завершить сессию. Попробуйте ещё раз.");
      router.replace("/admin/login");
      router.refresh();
    } catch (error) {
      alert(error instanceof Error ? error.message : "Ошибка выхода");
    } finally { setBusy(false); }
  }

  return (
    <div className="crm65-shell">
      <aside className={`crm65-sidebar ${menu ? "crm65-menu-open" : ""}`}>
        <div className="crm65-brand"><span className="crm65-brand-mark">B</span><span>BOARD<span className="crm65-orange">RIDERS</span><small>WORKSPACE / CRM</small></span></div>
        <p className="crm65-nav-heading">Рабочее пространство</p>
        <nav className="crm65-nav" aria-label="Навигация CRM">
          {canReport && <Link href="/admin" onClick={() => setMenu(false)} className={pathname === "/admin" ? "active" : ""}>◫ <span>Обзор</span></Link>}
          <Link href="/admin/orders" onClick={() => setMenu(false)} className={pathname.startsWith("/admin/orders") ? "active" : ""}>▤ <span>Заказы</span></Link>

          {staff.abilities.includes("crm66.tasks.manage") && (
              <Link
                  href="/admin/tasks"
                  onClick={() => setMenu(false)}
                  className={pathname.startsWith("/admin/tasks") ? "active" : ""}
              >
                ◉ <span>Задачи</span>
              </Link>
          )}
          {staff.abilities.includes("crm68.staff.manage") && (
              <Link
                  href="/admin/staff"
                  onClick={() => setMenu(false)}
                  className={pathname.startsWith("/admin/staff") ? "active" : ""}
              >
                ♙ <span>Сотрудники</span>
              </Link>
          )}
          {staff.abilities.includes("crm66.tasks.manage") && (
              <Link
                  href="/admin/sla"
                  onClick={() => setMenu(false)}
                  className={pathname.startsWith("/admin/sla") ? "active" : ""}
              >
                ◷ <span>SLA и сроки</span>
              </Link>
          )}
          {staff.abilities.includes("crm64.orders.view") && (
              <Link
                  href="/admin/notifications"
                  onClick={() => setMenu(false)}
                  className={
                    pathname.startsWith("/admin/notifications")
                        ? "active"
                        : ""
                  }
              >
                ♧ <span>Уведомления</span>
                <NotificationBadge70 />
              </Link>
          )}
          {staff.abilities.includes("catalog71.manage") && (
              <Link
                  href="/admin/catalog"
                  onClick={() => setMenu(false)}
                  className={
                    pathname.startsWith("/admin/catalog")
                        ? "active"
                        : ""
                  }
              >
                ▦ <span>Каталог товаров</span>
              </Link>
          )}
        </nav>
        <div className="crm65-sidebar-bottom">
          <p className="crm65-sidebar-hint">Финансовые и фискальные операции доступны только на чтение. Все действия учитываются сервером.</p>
          <div className="crm65-user"><div className="crm65-avatar">{staff.name.slice(0, 1).toUpperCase()}</div><div className="crm65-user-text"><strong>{staff.name}</strong><span>{staff.role} · {staff.email}</span></div></div>
          <button className="crm65-logout" onClick={logout} disabled={busy}>{busy ? "Завершаем..." : "Выйти из CRM"}</button>
        </div>
      </aside>
      <div className="crm65-work">
        <header className="crm65-topbar">
          <button type="button" className="crm65-hamburger" aria-label="Открыть меню" onClick={() => setMenu(v => !v)}>☰</button>
          <span className="crm65-online"><i /> Внутренняя панель</span>
          <span className="crm65-topbar-right">{staff.name} <span className="crm65-muted">/ {staff.role}</span></span>
        </header>
        <main className="crm65-main">{children}</main>
      </div>
    </div>
  );
}
