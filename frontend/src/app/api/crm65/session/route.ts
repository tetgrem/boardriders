import { NextResponse } from "next/server";
import {
  allowedOrigin, clearSession, currentStaff, laravel, readSession, storeSession,
} from "@/lib/crm65/session";



function json(body: unknown, status: number) {
  return NextResponse.json(body, { status, headers: { "Cache-Control": "no-store" } });
}

export async function GET() {
  try {
    const staff = await currentStaff();
    return staff ? json(staff, 200) : json({ message: "Unauthorized" }, 401);
  } catch {
    return json({ message: "CRM session service unavailable" }, 503);
  }
}

export async function POST(request: Request) {
  if (!allowedOrigin(request)) return json({ message: "Invalid request origin" }, 403);
  if (!request.headers.get("content-type")?.startsWith("application/json")) {
    return json({ message: "JSON required" }, 415);
  }
  const raw = await request.text();
  if (raw.length > 1600) return json({ message: "Request too large" }, 413);
  let input: unknown;
  try { input = JSON.parse(raw); } catch { return json({ message: "Invalid JSON" }, 400); }
  const data = input as { email?: unknown; password?: unknown };
  if (!data || typeof data.email !== "string" || typeof data.password !== "string"
    || data.email.length > 254 || data.password.length > 1024) {
    return json({ message: "Invalid credentials" }, 422);
  }
  try {
    const response = await fetch(`${laravel}/api/v1/admin/crm65/session`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ email: data.email, password: data.password }),
      cache: "no-store",
      signal: AbortSignal.timeout(10_000),
    });
    if (!response.ok) {
      return json({ message: response.status === 429 ? "Слишком много попыток" : "Неверная почта или пароль" },
        response.status === 429 ? 429 : 401);
    }
    const result = (await response.json()) as {
      access_token?: string; expires_at?: string;
    };
    if (!result.access_token || !result.expires_at) {
      return json({ message: "Invalid authentication response" }, 502);
    }
    await storeSession(result.access_token, result.expires_at);
    return json({ authenticated: true }, 200);
  } catch {
    return json({ message: "Не удалось связаться с сервером CRM" }, 502);
  }
}

export async function DELETE(request: Request) {
  if (!allowedOrigin(request)) return json({ message: "Invalid request origin" }, 403);
  const session = await readSession();
  if (!session) {
    await clearSession();
    return json({ logged_out: true }, 200);
  }
  try {
    const response = await fetch(`${laravel}/api/v1/admin/crm65/session`, {
      method: "DELETE",
      headers: { Authorization: `Bearer ${session.token}`, Accept: "application/json" },
      cache: "no-store",
      signal: AbortSignal.timeout(10_000),
    });
    if (!response.ok && response.status !== 401 && response.status !== 403) {
      return json({ message: "Не удалось отозвать сессию" }, 502);
    }
    await clearSession();
    return json({ logged_out: true }, 200);
  } catch {
    return json({ message: "Не удалось отозвать сессию" }, 502);
  }
}
