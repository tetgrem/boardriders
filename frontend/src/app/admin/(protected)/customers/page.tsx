import { Suspense } from "react";
import { notFound, redirect } from "next/navigation";

import CustomersClient74
    from "@/components/crm74/CustomersClient74";

import { currentStaff }
    from "@/lib/crm65/session";

async function CustomersContent() {
    const staff = await currentStaff();

    if (!staff) {
        redirect("/admin/login");
    }

    if (!staff.abilities.includes("crm74.customers.view")) {
        notFound();
    }

    return <CustomersClient74 />;
}

export default function CustomersPage() {
    return (
        <Suspense fallback={
            <div className="crm65-loading">
                Загружаем покупателей...
            </div>
        }>
            <CustomersContent />
        </Suspense>
    );
}