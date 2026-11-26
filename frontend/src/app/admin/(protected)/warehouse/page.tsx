import { Suspense } from "react";
import { notFound, redirect } from "next/navigation";

import WarehouseClient72
    from "@/components/warehouse72/WarehouseClient72";

import { currentStaff }
    from "@/lib/crm65/session";

async function WarehouseContent() {
    const staff = await currentStaff();

    if (!staff) {
        redirect("/admin/login");
    }

    if (!staff.abilities.includes("warehouse72.view")) {
        notFound();
    }

    return (
        <WarehouseClient72
            canManage={
                staff.abilities.includes("warehouse72.manage")
            }
        />
    );
}

export default function WarehousePage() {
    return (
        <Suspense
            fallback={
                <div className="crm65-loading">
                    Загружаем складское управление...
                </div>
            }
        >
            <WarehouseContent />
        </Suspense>
    );
}