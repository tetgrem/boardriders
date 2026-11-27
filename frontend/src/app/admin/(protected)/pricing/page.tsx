import { Suspense } from "react";
import { notFound, redirect } from "next/navigation";

import PricingClient73
    from "@/components/pricing73/PricingClient73";

import { currentStaff }
    from "@/lib/crm65/session";

async function PricingContent() {
    const staff = await currentStaff();

    if (!staff) {
        redirect("/admin/login");
    }

    if (!staff.abilities.includes("pricing73.view")) {
        notFound();
    }

    return (
        <PricingClient73
            canManage={
                staff.abilities.includes("pricing73.manage")
            }
        />
    );
}

export default function PricingPage() {
    return (
        <Suspense fallback={
            <div className="crm65-loading">
                Загружаем цены и маркетинг...
            </div>
        }>
            <PricingContent />
        </Suspense>
    );
}