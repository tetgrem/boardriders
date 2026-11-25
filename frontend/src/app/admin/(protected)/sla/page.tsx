import { Suspense } from "react";
import { notFound, redirect } from "next/navigation";
import SlaClient69 from "@/components/crm69/SlaClient69";
import { currentStaff } from "@/lib/crm65/session";

async function SlaContent() {
    const staff = await currentStaff();
    if (!staff) redirect("/admin/login");
    if (!staff.abilities.includes("crm66.tasks.manage")) notFound();
    return <SlaClient69 staff={staff} />;
}

export default function SlaPage() {
    return <Suspense fallback={<div className="crm65-loading">Загружаем SLA...</div>}>
        <SlaContent />
    </Suspense>;
}