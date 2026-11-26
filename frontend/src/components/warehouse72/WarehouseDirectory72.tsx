"use client";

import { useState, type FormEvent } from "react";

import type {
    DocumentBody,
    Warehouse,
} from "./types";

export default function WarehouseDirectory72({
                                                 warehouses,
                                                 canManage,
                                                 disabled,
                                                 onSubmit,
                                                 onDirty,
                                             }: {
    warehouses: Warehouse[];
    canManage: boolean;
    disabled: boolean;
    onSubmit: (body: DocumentBody) => void;
    onDirty: (value: boolean) => void;
}) {
    const [selected, setSelected] = useState("");
    const [dirty, setDirty] = useState(false);

    const warehouse = warehouses.find(
        (item) => String(item.id) === selected
    );

    function select(next: string) {
        if (disabled) return;

        if (dirty && !window.confirm(
            "Отменить несохранённые изменения склада?"
        )) {
            return;
        }

        setSelected(next);
        setDirty(false);
        onDirty(false);
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (disabled || !canManage) return;

        const form = new FormData(event.currentTarget);

        const name = String(form.get("name") ?? "").trim();

        const country = warehouse?.country_code
            ?? String(form.get("country") ?? "UA").trim().toUpperCase();

        const city = String(form.get("city") ?? "").trim() || null;
        const address = String(form.get("address") ?? "").trim() || null;

        onSubmit({
            kind: warehouse ? "warehouse_update" : "warehouse_create",

            name,
            country_code: country,
            city,
            address,

            ...(warehouse ? {
                warehouse_id: warehouse.id,
                expected_revision: warehouse.admin_revision,
            } : {}),
        });
    }

    return (
        <div className="warehouse72-directory">

            <section className="crm65-panel warehouse72-panel">
                <h2>Склады</h2>

                {canManage && (
                    <button
                        type="button"
                        className="crm65-primary"
                        disabled={disabled}
                        onClick={() => select("")}
                    >
                        + Новый склад
                    </button>
                )}

                {warehouses.map((item) => (
                    <button
                        type="button"
                        key={item.id}
                        className={[
                            "warehouse72-directory-item",
                            selected === String(item.id) ? "active" : "",
                        ].join(" ")}
                        disabled={disabled}
                        onClick={() => select(String(item.id))}
                    >
                        <strong>{item.name}</strong>

                        <small>
                            {item.kind === "owned"
                                ? "Собственный"
                                : "Партнёрский"}
                            {" · "}{item.country_code}
                            {" · "}{item.city ?? "Без города"}
                        </small>

                        <small>
                            {item.is_active ? "Активный" : "Неактивный"}
                        </small>
                    </button>
                ))}
            </section>

            <section className="crm65-panel warehouse72-panel">
                <h2>
                    {warehouse
                        ? `Склад: ${warehouse.name}`
                        : "Создание склада"}
                </h2>

                {warehouse && (
                    <p className="crm65-muted">
                        Код: {warehouse.code}
                        {" · "}Ревизия: {warehouse.admin_revision}
                    </p>
                )}

                {!canManage && !warehouse ? (
                    <p className="crm65-muted">
                        Выберите склад для просмотра информации.
                    </p>
                ) : warehouse?.kind === "partner" || !canManage ? (
                    <div className="warehouse72-readonly">
                        <p>Страна: {warehouse?.country_code}</p>
                        <p>Город: {warehouse?.city ?? "—"}</p>
                        <p>Адрес: {warehouse?.address ?? "—"}</p>
                        <p className="crm65-muted">
                            Склад доступен только для просмотра.
                        </p>
                    </div>
                ) : (
                    <form
                        key={`${selected}-${warehouse?.admin_revision ?? 0}`}
                        className="warehouse72-form"
                        onSubmit={submit}
                        onChange={() => {
                            setDirty(true);
                            onDirty(true);
                        }}
                    >
                        <label>
                            Название склада
                            <input
                                name="name"
                                required
                                maxLength={255}
                                defaultValue={warehouse?.name ?? ""}
                                disabled={disabled}
                            />
                        </label>

                        <label>
                            Страна
                            <input
                                name="country"
                                required
                                maxLength={2}
                                pattern="[A-Za-z]{2}"
                                defaultValue={warehouse?.country_code ?? "UA"}
                                readOnly={Boolean(warehouse)}
                                disabled={disabled}
                            />
                        </label>

                        <label>
                            Город
                            <input
                                name="city"
                                maxLength={150}
                                defaultValue={warehouse?.city ?? ""}
                                disabled={disabled}
                            />
                        </label>

                        <label>
                            Адрес
                            <textarea
                                name="address"
                                rows={3}
                                maxLength={500}
                                defaultValue={warehouse?.address ?? ""}
                                disabled={disabled}
                            />
                        </label>

                        <p className="crm65-muted">
                            {warehouse
                                ? "Код и страна существующего склада не меняются."
                                : "Код нового склада создаётся автоматически."}
                        </p>

                        <button
                            className="crm65-primary"
                            disabled={disabled || (Boolean(warehouse) && !dirty)}
                        >
                            {warehouse ? "Сохранить склад" : "Создать склад"}
                        </button>
                    </form>
                )}
            </section>
        </div>
    );
}