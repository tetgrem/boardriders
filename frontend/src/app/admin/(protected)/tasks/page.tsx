import { Suspense } from "react";
import { notFound, redirect } from "next/navigation";
import InboxClient67 from "@/components/crm65/InboxClient67";
import { currentStaff } from "@/lib/crm65/session";

async function TasksContent() {
  const staff = await currentStaff();
  if (!staff) redirect("/admin/login");
  if (!staff.abilities.includes("crm66.tasks.manage")) notFound();
  return <InboxClient67 staff={staff} />;
}

export default function TasksPage() {
  return (
    <Suspense fallback={<div className="crm65-loading">Загружаем очередь CRM...</div>}>
      <TasksContent />
    </Suspense>
  );
}
