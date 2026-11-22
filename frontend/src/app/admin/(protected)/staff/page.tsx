import { Suspense } from "react";
import { notFound, redirect } from "next/navigation";
import StaffClient68 from "@/components/crm68/StaffClient68";
import { currentStaff } from "@/lib/crm65/session";

async function StaffContent() {
    const staff = await currentStaff();
    if (!staff) redirect("/admin/login");
    if (!staff.abilities.includes("crm68.staff.manage")) notFound();
    return <StaffClient68 staff={staff} />;
}

export default function StaffPage() {
    return <Suspense fallback={<div className="crm65-loading">Проверяем права CRM...</div>}>
        <StaffContent />
    </Suspense>;
}