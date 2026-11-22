import "server-only";

import { createCipheriv, createDecipheriv, createHash, randomBytes } from "node:crypto";
import { cookies } from "next/headers";
import { connection } from "next/server";

export type StaffIdentity = {
  id: number;
  name: string;
  email: string;
  role: "owner" | "manager" | "support" | "finance" | "fiscal" | "viewer";
  abilities: string[];
};

type Sealed = { token: string; until: number };

const IS_PROD = process.env.NODE_ENV === "production";
const COOKIE = IS_PROD ? "__Host-br-crm65" : "br-crm65";

export const laravel = (
  process.env.API_INTERNAL_URL ?? "http://127.0.0.1:8000"
).replace(/\/+$/, "");

function encryptionKey(): Buffer {
  const secret = process.env.CRM65_SESSION_SECRET;
  if (!secret || secret.length < 32) {
    throw new Error("CRM65_SESSION_SECRET must be at least 32 characters");
  }
  return createHash("sha256").update(secret, "utf8").digest();
}

function encrypt(payload: Sealed): string {
  const nonce = randomBytes(12);
  const cipher = createCipheriv("aes-256-gcm", encryptionKey(), nonce);
  const bytes = Buffer.concat([
    cipher.update(JSON.stringify(payload), "utf8"),
    cipher.final(),
  ]);
  return Buffer.concat([nonce, cipher.getAuthTag(), bytes]).toString("base64url");
}

function decrypt(encoded: string): Sealed | null {
  try {
    const raw = Buffer.from(encoded, "base64url");
    if (raw.length < 29 || raw.length > 4096) return null;
    const nonce = raw.subarray(0, 12);
    const tag = raw.subarray(12, 28);
    const decipher = createDecipheriv("aes-256-gcm", encryptionKey(), nonce);
    decipher.setAuthTag(tag);
    const data = Buffer.concat([
      decipher.update(raw.subarray(28)),
      decipher.final(),
    ]);
    const parsed: unknown = JSON.parse(data.toString("utf8"));
    if (!parsed || typeof parsed !== "object") return null;
    const obj = parsed as Partial<Sealed>;
    if (
      typeof obj.token !== "string" || obj.token.length < 35 ||
      typeof obj.until !== "number" || obj.until <= Date.now()
    ) return null;
    return { token: obj.token, until: obj.until };
  } catch {
    return null;
  }
}

export async function readSession():
    Promise<Sealed | null> {
  // CRM session is request-specific.
  // Never validate its expiration during prerendering.
  await connection();

  encryptionKey();

  const entry = (await cookies())
      .get(COOKIE)?.value;

  return entry ? decrypt(entry) : null;
}

export async function storeSession(token: string, expiresAt: string): Promise<void> {
  const parsed = Date.parse(expiresAt);
  if (!Number.isFinite(parsed) || parsed <= Date.now() || token.length < 35) {
    throw new Error("Invalid CRM session token response");
  }
  const until = Math.min(parsed, Date.now() + 8 * 3600_000);
  (await cookies()).set(COOKIE, encrypt({ token, until }), {
    httpOnly: true,
    secure: IS_PROD,
    sameSite: "strict",
    path: "/",
    maxAge: Math.max(1, Math.floor((until - Date.now()) / 1000)),
    priority: "high",
  });
}

export async function clearSession(): Promise<void> {
  (await cookies()).delete(COOKIE);
}

export function allowedOrigin(request: Request): boolean {
  const origin = request.headers.get("origin");
  const configured = process.env.CRM65_PUBLIC_ORIGIN;
  // Production deployment must explicitly declare its public origin.
  if (IS_PROD && !configured) return false;
  const target = (configured ?? new URL(request.url).origin).replace(/\/$/, "");
  return origin !== null && origin === target;
}

export async function staffFromToken(token: string): Promise<StaffIdentity | null> {
  try {
    const response = await fetch(`${laravel}/api/v1/admin/crm65/me`, {
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
      cache: "no-store",
      signal: AbortSignal.timeout(10_000),
    });
    if (!response.ok) return null;
    const data = (await response.json()) as StaffIdentity;
    if (!data || typeof data.id !== "number" || !Array.isArray(data.abilities)) {
      return null;
    }
    return data;
  } catch {
    return null;
  }
}

export async function currentStaff(): Promise<StaffIdentity | null> {
  const session = await readSession();
  return session ? staffFromToken(session.token) : null;
}
