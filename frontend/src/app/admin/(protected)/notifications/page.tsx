import { Suspense } from "react";
import { notFound, redirect } from "next/navigation";

import NotificationsClient70
    from "@/components/crm70/NotificationsClient70";

import { currentStaff }
    from "@/lib/crm65/session";

async function NotificationsContent() {
    const staff = await currentStaff();

    if (!staff) {
        redirect("/admin/login");
    }

    if (
        !staff.abilities.includes("crm64.orders.view")
    ) {
        notFound();
    }

    return <NotificationsClient70 />;
}

export default function NotificationsPage() {
    return (
        <Suspense
            fallback={
                <div className="crm65-loading">
                    Загружаем уведомления...
                </div>
            }
        >
            <NotificationsContent />
        </Suspense>
    );
}