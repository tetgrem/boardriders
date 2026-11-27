"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { crm, CrmError, when } from "@/lib/crm65/client";

import Prices73 from "./Prices73";
import Promotions73 from "./Promotions73";
import Loyalty73 from "./Loyalty73";
import Campaigns731 from "./Campaigns731";

import {
    actionLabels,
    type AuditRow,
    type Command,
    type CommandResult,
    type Overview,
    type Page,
} from "./types";

type Tab =
    | "prices"
    | "promotions"
    | "campaigns"
    | "loyalty"
    | "history";

type Pending = Command & {
    operation_key: string;
};

function History73() {
    const [data, setData] = useState<Page<AuditRow> | null>(null);
    const [cursor, setCursor] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        let live = true;

        setLoading(true);

        const params = new URLSearchParams({ limit: "25" });

        if (cursor !== null) {
            params.set("before_id", String(cursor));
        }

        crm<Page<AuditRow>>(`pricing/history?${params}`)
            .then((response) => {
                if (live) setData(response);
            })
            .catch((caught) => {
                if (live) setError(
                    caught instanceof Error
                        ? caught.message : "Ошибка истории"
                );
            })
            .finally(() => {
                if (live) setLoading(false);
            });

        return () => {
            live = false;
        };
    }, [cursor]);

    return (
        <section className="crm65-panel pricing73-panel">
            <h2>История коммерческих операций</h2>

            {loading && <p>Загрузка...</p>}

            {error && <p className="crm65-error">{error}</p>}

            {data?.items.length === 0 && (
                <p className="crm65-muted">История пока пуста.</p>
            )}

            {data?.items.map((item) => (
                <details className="pricing73-audit" key={item.id}>
                    <summary>
                        #{item.id}
                        {" · "}{actionLabels[item.action] ?? item.action}
                        {" · "}{when(item.created_at)}
                    </summary>

                    <small>Сотрудник #{item.actor_id}</small>

                    <pre>{JSON.stringify(item.result, null, 2)}</pre>
                </details>
            ))}

            <div className="pricing73-actions">
                <button
                    type="button"
                    disabled={loading || cursor === null}
                    onClick={() => setCursor(null)}
                >
                    В начало
                </button>

                <button
                    type="button"
                    disabled={loading || !data?.next_before_id}
                    onClick={() => setCursor(data?.next_before_id ?? null)}
                >
                    Далее
                </button>
            </div>
        </section>
    );
}

export default function PricingClient73({
                                            canManage,
                                        }: {
    canManage: boolean;
}) {
    const router = useRouter();

    const [tab, setTab] = useState<Tab>("prices");
    const [overview, setOverview] = useState<Overview | null>(null);

    const [refresh, setRefresh] = useState(0);
    const [editorVersion, setEditorVersion] = useState(0);

    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);

    const [pending, setPending] = useState<Pending | null>(null);

    const pendingRef = useRef<Pending | null>(null);
    const busyRef = useRef(false);

    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    useEffect(() => {
        let live = true;

        crm<Overview>("pricing/overview")
            .then((result) => {
                if (live) setOverview(result);
            })
            .catch((caught) => {
                if (!live) return;

                if (caught instanceof CrmError && caught.status === 401) {
                    router.replace("/admin/login");
                    return;
                }

                setError(
                    caught instanceof Error
                        ? caught.message : "Ошибка коммерческого раздела"
                );
            });

        return () => {
            live = false;
        };
    }, [refresh, router]);

    useEffect(() => {
        if (!dirty && !pending) return;

        function beforeUnload(event: BeforeUnloadEvent) {
            event.preventDefault();
            event.returnValue = "";
        }

        function handleLink(event: MouseEvent) {
            if (!(event.target instanceof Element)) return;
            if (!event.target.closest("a[href]")) return;

            if (!window.confirm(
                "Есть несохранённые коммерческие изменения. Покинуть страницу?"
            )) {
                event.preventDefault();
                event.stopPropagation();
            }
        }

        window.addEventListener("beforeunload", beforeUnload);
        document.addEventListener("click", handleLink, true);

        return () => {
            window.removeEventListener("beforeunload", beforeUnload);
            document.removeEventListener("click", handleLink, true);
        };
    }, [dirty, pending]);

    function resetEditors() {
        setDirty(false);
        setOverview(null);
        setEditorVersion((value) => value + 1);
        setRefresh((value) => value + 1);
    }

    async function send(body: Pending) {
        if (busyRef.current) return;

        busyRef.current = true;
        setBusy(true);
        setError(null);
        setNotice(null);

        try {
            const endpoint =
                body.action === "campaign_create"
                    ? "pricing/campaigns/create"
                    : body.action === "campaign_toggle"
                        ? "pricing/campaigns/toggle"
                        : "pricing/submit";

            const result = await crm<CommandResult>(
                endpoint,
                {
                    method: "POST",
                    body,
                }
            );

            pendingRef.current = null;
            setPending(null);

            const code = typeof result.result.code === "string"
                ? ` · Код: ${result.result.code}`
                : "";

            setNotice(
                `${result.replayed ? "Операция уже была выполнена" : "Изменения сохранены"}`
                + ` · Запись #${result.event_id}${code}`
            );

            resetEditors();

        } catch (caught) {
            if (
                caught instanceof CrmError
                && [400, 403, 404, 409, 422].includes(caught.status)
            ) {
                // An explicit rejection lets the employee
                // correct the original form and submit again.
                pendingRef.current = null;
                setPending(null);
            }

            setError(
                caught instanceof Error
                    ? caught.message : "Не удалось сохранить изменения"
            );

            // For unknown network results, retain the same UUID
            // and exact payload for retry.
        } finally {
            busyRef.current = false;
            setBusy(false);
        }
    }

    function submit(command: Command) {
        if (!canManage
            || busyRef.current
            || pendingRef.current) return;

        const body: Pending = {
            ...command,
            operation_key: crypto.randomUUID(),
        };

        pendingRef.current = body;
        setPending(body);

        void send(body);
    }

    function chooseTab(next: Tab) {
        if (busy || pendingRef.current || tab === next) return;

        if (dirty && !window.confirm(
            "Отменить несохранённые изменения?"
        )) return;

        setTab(next);
        setDirty(false);
        setError(null);
        setNotice(null);
        setEditorVersion((value) => value + 1);
    }

    const locked = busy || pending !== null;

    const tabs: Array<[Tab, string]> = [
        ["prices", "Цены товаров"],
        ["promotions", "Промокоды"],
        ["campaigns", "Автоматические акции"],
        ["loyalty", "Программа лояльности"],
        ["history", "История"],
    ];

    return (
        <div className="pricing73-page">
            <div className="crm65-pagehead">
                <div>
                    <p className="crm65-overline">
                        COMMERCE / PRICING
                    </p>

                    <h1>Цены и маркетинг</h1>

                    <p className="crm65-muted">
                        Розничные цены, промокоды и бонусные политики.
                    </p>
                </div>

                <button
                    type="button"
                    className="crm65-secondary"
                    disabled={locked}
                    onClick={() => {
                        if (dirty && !window.confirm(
                            "Отменить изменения и загрузить актуальные данные?"
                        )) return;

                        setError(null);
                        resetEditors();
                    }}
                >
                    ↻ Обновить
                </button>
            </div>

            {error && <p className="crm65-error" role="alert">{error}</p>}

            {notice && (
                <p className="pricing73-success" role="status">
                    {notice}
                </p>
            )}

            {!canManage && (
                <p className="crm65-muted">
                    Раздел доступен только для просмотра.
                </p>
            )}

            {overview && (
                <div className="pricing73-summary">
                    {[
                        ["Назначено цен", overview.summary.prices],
                        ["Всего акций", overview.summary.promotions],
                        ["Включено акций", overview.summary.enabled_promotions],
                        ["Бонусных кошельков", overview.summary.wallets],
                    ].map(([label, value]) => (
                        <div key={String(label)} className="pricing73-metric">
                            <small>{label}</small>
                            <strong>
                                {Number(value).toLocaleString("uk-UA")}
                            </strong>
                        </div>
                    ))}
                </div>
            )}

            <div className="pricing73-tabs">
                {tabs.map(([id, label]) => (
                    <button
                        key={id}
                        type="button"
                        className={tab === id ? "active" : ""}
                        disabled={locked}
                        onClick={() => chooseTab(id)}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {pending && (
                <section className="crm65-panel pricing73-panel">
                    <h2>
                        {busy
                            ? "Сохраняем изменения..."
                            : "Результат операции не подтверждён"}
                    </h2>

                    <p className="crm65-muted">
                        Повторная отправка использует тот же ключ операции.
                    </p>

                    <small>{pending.operation_key}</small>

                    {!busy && (
                        <div className="pricing73-actions">
                            <button
                                type="button"
                                className="crm65-primary"
                                onClick={() => void send(pending)}
                            >
                                Повторить запрос
                            </button>

                            <button
                                type="button"
                                className="crm65-secondary"
                                onClick={() => {
                                    if (!window.confirm(
                                        "Операция могла выполниться. Перед новым сохранением проверьте историю. Очистить редактор?"
                                    )) return;

                                    pendingRef.current = null;
                                    setPending(null);
                                    setTab("history");
                                    resetEditors();
                                }}
                            >
                                Проверить историю
                            </button>
                        </div>
                    )}
                </section>
            )}

            {!overview && (
                <p className="crm65-muted">Загрузка данных...</p>
            )}

            {overview && tab === "prices" && (
                <Prices73
                    key={editorVersion}
                    lists={overview.price_lists}
                    canManage={canManage}
                    locked={locked}
                    submit={submit}
                    onDirty={setDirty}
                />
            )}

            {overview && tab === "promotions" && (
                <Promotions73
                    key={editorVersion}
                    lists={overview.price_lists}
                    canManage={canManage}
                    locked={locked}
                    submit={submit}
                    onDirty={setDirty}
                />
            )}
            {overview && tab === "campaigns" && (
                <Campaigns731
                    key={editorVersion}
                    lists={overview.price_lists}
                    canManage={canManage}
                    locked={locked}
                    submit={submit}
                    onDirty={setDirty}
                />
            )}
            {overview && tab === "loyalty" && (
                <Loyalty73
                    key={editorVersion}
                    markets={overview.markets}
                    lists={overview.price_lists}
                    canManage={canManage}
                    locked={locked}
                    submit={submit}
                    onDirty={setDirty}
                />
            )}

            {tab === "history" && (
                <History73 key={editorVersion}/>
            )}
        </div>
    );
}