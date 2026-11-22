import { Suspense } from "react";
import { redirect } from "next/navigation";

import DashboardClient from "@/components/crm65/DashboardClient";
import { currentStaff } from "@/lib/crm65/session";

export default function AdminHome() {
  return (
      <Suspense
          fallback={
            <div className="crm65-loading">
              <h2>Загружаем CRM...</h2>
              <p className="crm65-muted">
                Проверяем доступ к панели.
              </p>
            </div>
          }
      >
        <DashboardContent />
      </Suspense>
  );
}

async function DashboardContent() {
  const staff = await currentStaff();

  if (!staff) {
    redirect("/admin/login");
  }

  if (
      !staff.abilities.includes(
          "crm64.reporting.view"
      )
  ) {
    redirect("/admin/orders");
  }

  return <DashboardClient />;
}