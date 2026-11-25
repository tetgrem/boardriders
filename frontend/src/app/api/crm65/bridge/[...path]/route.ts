import { NextResponse } from "next/server";
import { allowedOrigin, laravel, readSession } from "@/lib/crm65/session";



type Context = { params: Promise<{ path: string[] }> };
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const allowedFilters = new Set([
  "limit", "before_id", "order_key", "payment_status", "has_rma", "has_fiscal_issue", "scope", "status", "priority", "filter", "category", "unread", "page"
]);

function route(path: string[], method: "GET" | "POST"): string | null {
  if (path[0] === "notifications") {
    if (method === "GET" && path.length === 1)
      return "crm70/notifications";

    if (method === "GET" && path.length === 2 && path[1] === "count")
      return "crm70/count";

    if (
        method === "POST"
        && path.length === 2
        && ["read", "unread", "preference"].includes(path[1])
    ) {
      return `crm70/${path[1]}`;
    }

    return null;
  }
  if (path[0] === "sla") {
    if (method === "GET" && path.length === 1)
      return "crm69/overview";

    if (method === "GET" && path.length === 2 && path[1] === "assignees")
      return "crm69/assignees";

    if (path.length === 4 && path[1] === "tasks" && /^[1-9]\d{0,15}$/.test(path[2])) {
      if (method === "GET" && path[3] === "history")
        return `crm69/tasks/${path[2]}/history`;

      if (method === "POST" && ["deadline", "comment", "assign"].includes(path[3]))
        return `crm69/tasks/${path[2]}/${path[3]}`;
    }

    return null;
  }
  if (path[0] === "staff") {
    if (path.length === 1 && (method === "GET" || method === "POST"))
      return "crm68/staff";

    if (path.length === 3 && /^[1-9]\d{0,15}$/.test(path[1])) {
      if (method === "GET" && path[2] === "history")
        return `crm68/staff/${path[1]}/history`;

      if (method === "POST" && path[2] === "change")
        return `crm68/staff/${path[1]}/change`;
    }

    return null;
  }
  if (method === "GET" && path.length === 1 && path[0] === "inbox")
    return "crm67/inbox";
  if (method === "GET" && path.length === 1 && path[0] === "dashboard") return "dashboard";
  if (method === "GET" && path.length === 1 && path[0] === "orders") return "orders";
  if (path[0] !== "orders" || path.length < 2 || !uuid.test(path[1])) return null;
  if (path.length >= 3 && path[2] === "tasks") {
    if (path.length === 3 && (method === "GET" || method === "POST")) {
      return `crm66/orders/${path[1]}/tasks`;
    }
    if (path.length === 5 && /^[1-9]\d{0,15}$/.test(path[3])) {
      if (method === "GET" && path[4] === "history") {
        return `crm66/orders/${path[1]}/tasks/${path[3]}/history`;
      }
      if (method === "POST" && path[4] === "action") {
        return `crm66/orders/${path[1]}/tasks/${path[3]}/action`;
      }
    }
    return null;
  }
  if (method === "GET" && path.length === 2) return `orders/${path[1]}`;
  if (method === "GET" && path.length === 3 &&
      ["finance", "timeline", "access"].includes(path[2])) {
    return `orders/${path[1]}/${path[2]}`;
  }
  if (method === "POST" && path.length === 3 && path[2] === "notes") {
    return `orders/${path[1]}/notes`;
  }
  return null;
}

async function proxy(request: Request, context: Context, method: "GET" | "POST") {
  if (method === "POST" && !allowedOrigin(request)) {
    return NextResponse.json({ message: "Origin rejected" }, { status: 403 });
  }
  const path = route((await context.params).path, method);
  if (!path) return NextResponse.json({ message: "Not found" }, { status: 404 });
  const session = await readSession();
  if (!session) return NextResponse.json({ message: "Unauthorized" }, { status: 401 });

  const incoming = new URL(request.url);
  const query = new URLSearchParams();
  if (method === "GET") {
    for (const [key, value] of incoming.searchParams) {
      if (!allowedFilters.has(key) || query.has(key) || value.length > 64) {
        return NextResponse.json({ message: "Invalid filter" }, { status: 422 });
      }
      query.set(key, value);
    }
  }
  let body: string | undefined;
  if (method === "POST") {
    if (!request.headers.get("content-type")?.startsWith("application/json")) {
      return NextResponse.json({ message: "JSON required" }, { status: 415 });
    }
    body = await request.text();
    if (body.length > 1600) return NextResponse.json({ message: "Too large" }, { status: 413 });
  }
  const suffix = query.size ? `?${query}` : "";
  const apiRoute =
      path.startsWith("crm66/")
      || path.startsWith("crm67/")
      || path.startsWith("crm68/")
      || path.startsWith("crm69/")
      || path.startsWith("crm70/")
          ? path
          : `crm64/${path}`;
  try {
    const response = await fetch(`${laravel}/api/v1/admin/${apiRoute}${suffix}`, {
      method,
      headers: {
        Authorization: `Bearer ${session.token}`,
        Accept: "application/json",
        ...(method === "POST" ? { "Content-Type": "application/json" } : {}),
      },
      ...(method === "POST" ? { body } : {}),
      cache: "no-store",
      signal: AbortSignal.timeout(12_000),
    });
    if (!response.ok) {
      const status = [400, 401, 403, 404, 409, 422, 429].includes(response.status)
        ? response.status : 502;
      return NextResponse.json({ message: status === 401 ? "Сессия истекла" : "Операция CRM отклонена" },
        { status, headers: { "Cache-Control": "no-store" } });
    }
    const text = await response.text();
    if (text.length > 1_500_000) {
      return NextResponse.json({ message: "Ответ слишком большой" }, { status: 502 });
    }
    const data = JSON.parse(text) as unknown;
    return NextResponse.json(data, { headers: { "Cache-Control": "no-store" } });
  } catch {
    return NextResponse.json({ message: "CRM временно недоступна" }, { status: 502 });
  }
}

export async function GET(req: Request, ctx: Context) { return proxy(req, ctx, "GET"); }
export async function POST(req: Request, ctx: Context) { return proxy(req, ctx, "POST"); }
