import { Suspense } from "react";
import { notFound, redirect } from "next/navigation";
import CatalogClient71 from "@/components/catalog71/CatalogClient71";
import { currentStaff } from "@/lib/crm65/session";

async function CatalogContent() {
    const staff = await currentStaff();

    if (!staff) redirect("/admin/login");

    if (!staff.abilities.includes("catalog71.manage")) {
        notFound();
    }

    return <CatalogClient71 />;
}

export default function CatalogPage() {
    return (
        <Suspense fallback={
            <div className="crm65-loading">
                Загружаем каталог...
            </div>
        }>
            <CatalogContent />
        </Suspense>
    );
}