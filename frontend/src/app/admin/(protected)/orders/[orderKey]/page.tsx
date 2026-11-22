import { Suspense } from "react";
import {
  notFound,
  redirect,
} from "next/navigation";

import OrderClient from "@/components/crm65/OrderClient";
import { currentStaff } from "@/lib/crm65/session";

type Props = {
  params: Promise<{
    orderKey: string;
  }>;
};

export default function OrderPage({
                                    params,
                                  }: Props) {
  return (
      <Suspense
          fallback={
            <div className="crm65-loading">
              <h2>Загружаем заказ...</h2>
              <p className="crm65-muted">
                Проверяем доступ и данные.
              </p>
            </div>
          }
      >
        <OrderContent params={params} />
      </Suspense>
  );
}

async function OrderContent({
                              params,
                            }: Props) {
  const { orderKey } = await params;

  if (
      !/^[0-9a-f-]{36}$/i.test(orderKey)
  ) {
    notFound();
  }

  const staff = await currentStaff();

  if (!staff) {
    redirect("/admin/login");
  }

  return (
      <OrderClient
          orderKey={orderKey}
          staff={staff}
      />
  );
}