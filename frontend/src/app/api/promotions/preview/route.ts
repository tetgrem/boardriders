import { NextResponse } from "next/server";

const API_URL =
    process.env.API_INTERNAL_URL ??
    "http://127.0.0.1:8000";

export async function POST(request: Request) {
    let body: unknown;

    try {
        body = await request.json();
    } catch {
        return NextResponse.json(
            { error: "invalid_json" },
            { status: 400 }
        );
    }

    try {
        const response = await fetch(
            `${API_URL.replace(/\/+$/, "")}/api/v1/promotions/preview`,
            {
                method: "POST",

                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                },

                body: JSON.stringify(body),

                cache: "no-store",

                signal: AbortSignal.timeout(10000),
            }
        );

        const result: unknown = await response
            .json()
            .catch(() => null);

        if (!response.ok) {
            return NextResponse.json(
                { error: "promotion_preview_failed" },
                {
                    status:
                        response.status === 422
                            ? 422
                            : 502,
                }
            );
        }

        return NextResponse.json(result, {
            headers: {
                "Cache-Control": "no-store",
            },
        });
    } catch {
        return NextResponse.json(
            { error: "promotion_service_unavailable" },
            { status: 503 }
        );
    }
}