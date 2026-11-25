"use client";

import { useEffect, useState } from "react";
import { crm } from "@/lib/crm65/client";

export default function NotificationBadge70() {
    const [count, setCount] =
        useState<number | null>(null);

    useEffect(() => {
        let active = true;
        let requestId = 0;

        async function refresh() {
            const current = ++requestId;

            try {
                const response = await crm<{
                    unread: number;
                }>("notifications/count");

                if (
                    active
                    && current === requestId
                ) {
                    setCount(response.unread);
                }
            } catch {
                if (
                    active
                    && current === requestId
                ) {
                    setCount(null);
                }
            }
        }

        function refreshVisible() {
            if (
                document.visibilityState === "visible"
            ) {
                void refresh();
            }
        }

        void refresh();

        const timer = window.setInterval(
            refreshVisible,
            30000
        );

        window.addEventListener(
            "crm70:changed",
            refreshVisible
        );

        document.addEventListener(
            "visibilitychange",
            refreshVisible
        );

        return () => {
            active = false;

            window.clearInterval(timer);

            window.removeEventListener(
                "crm70:changed",
                refreshVisible
            );

            document.removeEventListener(
                "visibilitychange",
                refreshVisible
            );
        };
    }, []);

    if (count === null || count === 0) {
        return null;
    }

    return (
        <span
            className="crm70-nav-counter"
            aria-label={`Непрочитанных уведомлений: ${count}`}
        >
            {count > 99 ? "99+" : count}
        </span>
    );
}