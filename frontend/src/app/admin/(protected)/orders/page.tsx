import { Suspense } from "react";
import { redirect } from "next/navigation";

import OrdersClient from "@/components/crm65/OrdersClient";
import { currentStaff } from "@/lib/crm65/session";

export default function OrdersPage() {
  return (
      <Suspense
          fallback={
            <div className="crm65-loading">
              <h2>Загружаем заказы...</h2>
              <p className="crm65-muted">
                Проверяем права сотрудника.
              </p>
            </div>
          }
      >
        <OrdersContent />
      </Suspense>
  );
}

async function OrdersContent() {
  const staff = await currentStaff();

  if (!staff) {
    redirect("/admin/login");
  }

  return <OrdersClient staff={staff} />;
}