import { Suspense, type ReactNode } from "react";
import { redirect } from "next/navigation";
import CrmFrame from "@/components/crm65/CrmFrame";
import { currentStaff } from "@/lib/crm65/session";

async function Guard({ children }: { children: ReactNode }) {
  const staff = await currentStaff();
  if (!staff) redirect("/admin/login");
  return <CrmFrame staff={staff}>{children}</CrmFrame>;
}

export default function ProtectedLayout({ children }: { children: ReactNode }) {
  return <Suspense fallback={<div className="crm65-loading-screen">Авторизация CRM...</div>}><Guard>{children}</Guard></Suspense>;
}
