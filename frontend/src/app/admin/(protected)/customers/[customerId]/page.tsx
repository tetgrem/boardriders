import { Suspense } from "react";
import { notFound, redirect } from "next/navigation";

import CustomerClient74
    from "@/components/crm74/CustomerClient74";

import { currentStaff }
    from "@/lib/crm65/session";

type Props = {
    params: Promise<{
        customerId: string;
    }>;
};

async function CustomerContent({ params }: Props) {
    const staff = await currentStaff();

    if (!staff) {
        redirect("/admin/login");
    }

    if (!staff.abilities.includes("crm74.customers.view")) {
        notFound();
    }

    const { customerId } = await params;

    if (!/^[1-9]\d{0,15}$/.test(customerId)) {
        notFound();
    }

    return <CustomerClient74 customerId={customerId} />;
}

export default function CustomerPage(props: Props) {
    return (
        <Suspense fallback={
            <div className="crm65-loading">
                Загружаем карточку покупателя...
            </div>
        }>
            <CustomerContent {...props} />
        </Suspense>
    );
}