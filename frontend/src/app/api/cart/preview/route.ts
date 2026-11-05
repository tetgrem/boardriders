import { NextResponse } from "next/server";

export async function POST(request: Request) {
    const body = await request.text();

    if (body.length > 12000) {
        return NextResponse.json(
            { message: "Too many items" },
            { status: 413 }
        );
    }

    const api = (
        process.env.API_INTERNAL_URL ??
        "http://127.0.0.1:8000"
    ).replace(/\/+$/, "");

    try {
        const response = await fetch(
            `${api}/api/v1/cart/preview`,
            {
                method: "POST",

                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                },

                body,
                cache: "no-store",

                signal: AbortSignal.timeout(10000),
            }
        );

        const result = await response.json();

        return NextResponse.json(result, {
            status: response.status,
        });
    } catch {
        return NextResponse.json(
            {
                message: "Не вдалося перевірити кошик",
            },
            {
                status: 502,
            }
        );
    }
}