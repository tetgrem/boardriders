"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

export default function LoginClient() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function login(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true); setError(null);
    try {
      const response = await fetch("/api/crm65/session", {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, password }),
      });
      const data = (await response.json()) as { message?: string };
      if (!response.ok) throw new Error(data.message ?? "Не удалось войти");
      setPassword("");
      router.replace("/admin/orders");
      router.refresh();
    } catch (e) { setError(e instanceof Error ? e.message : "Ошибка входа"); }
    finally { setBusy(false); }
  }

  return (
    <main className="crm65-login">
      <div className="crm65-login-hero"><div className="crm65-login-logo">B</div><p className="crm65-overline">BOARD<span className="crm65-orange">RIDERS</span> / ADMIN</p><h1>Все процессы.<br /><em>Под контролем.</em></h1><p>Заказы, возвраты, финансы, логистика и фискальные документы — в едином рабочем пространстве.</p><span className="crm65-login-footnote">Закрытая система для сотрудников магазина</span></div>
      <div className="crm65-login-panel"><form onSubmit={login} className="crm65-login-form"><p className="crm65-overline">SECURE ACCESS</p><h2>Вход для сотрудников</h2><p className="crm65-muted">Используй учётную запись, выданную администратором.</p>
        <label>Рабочая почта<input type="email" autoComplete="username" required maxLength={254} value={email} onChange={e => setEmail(e.target.value)} placeholder="you@boardriders.ua" /></label>
        <label>Пароль<input type="password" autoComplete="current-password" required value={password} onChange={e => setPassword(e.target.value)} /></label>
        {error && <p role="alert" className="crm65-error">{error}</p>}
        <button type="submit" className="crm65-primary" disabled={busy}>{busy ? "Проверка..." : "Войти в CRM"}</button>
        <p className="crm65-muted crm65-small">Доступ к CRM фиксируется. Не передавай пароль другим сотрудникам.</p>
      </form></div>
    </main>
  );
}
