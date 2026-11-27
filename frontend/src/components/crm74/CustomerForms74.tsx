"use client";

import { useState, type FormEvent } from "react";

import {
    channelLabels,
    type Contact,
    type WriteCommand,
} from "./types";

type Props = {
    locked: boolean;
    submit: (command: WriteCommand) => void;
    onDirty: (dirty: boolean) => void;
};

function text(form: FormData, key: string): string | null {
    return String(form.get(key) ?? "").trim() || null;
}

export function ContactForm74({
                                  contact,
                                  locked,
                                  submit,
                                  onDirty,
                              }: Props & {
    contact: Contact;
}) {
    const [dirty, setDirty] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);

        if (locked || !dirty) return;

        const form = new FormData(event.currentTarget);

        const phone = text(form, "phone")
            ?.replace(/[\s()-]/g, "") ?? null;

        if (phone !== null && !/^\+[1-9][0-9]{7,14}$/.test(phone)) {
            setError(
                "Введите номер в международном формате, например +380501234567."
            );
            return;
        }

        submit({
            kind: "contact",
            expected_contact_id: contact.contact_id,

            phone,
            city: text(form, "city"),
            address: text(form, "address"),

            preferred_channel:
                text(form, "preferred_channel") ?? "none",
        });
    }

    return (
        <form
            className="crm74-stack"
            onSubmit={save}
            onChange={() => {
                setDirty(true);
                onDirty(true);
            }}
        >
            <fieldset className="crm74-fieldset" disabled={locked}>
                <div className="crm74-form-grid">
                    <label>
                        Телефон
                        <input
                            name="phone"
                            type="tel"
                            maxLength={24}
                            defaultValue={contact.phone ?? ""}
                            placeholder="+380..."
                        />
                    </label>

                    <label>
                        Город
                        <input
                            name="city"
                            maxLength={120}
                            defaultValue={contact.city ?? ""}
                        />
                    </label>

                    <label>
                        Предпочтительный способ связи
                        <select
                            name="preferred_channel"
                            defaultValue={contact.preferred_channel}
                        >
                            {Object.entries(channelLabels).map(
                                ([key, label]) => (
                                    <option value={key} key={key}>
                                        {label}
                                    </option>
                                )
                            )}
                        </select>
                    </label>
                </div>

                <label>
                    Адрес / отделение доставки
                    <textarea
                        name="address"
                        maxLength={300}
                        rows={3}
                        defaultValue={contact.address ?? ""}
                        placeholder="Адрес, номер отделения, комментарий к доставке"
                    />
                </label>
            </fieldset>

            <p className="crm65-muted">
                Контакты внесены сотрудником и не считаются подтверждёнными
                покупателем. Старые заказы не изменяются.
            </p>

            {error && <p className="crm65-error">{error}</p>}

            <div className="crm74-actions">
                <button
                    className="crm65-primary"
                    disabled={locked || !dirty}
                >
                    Сохранить все контакты
                </button>

                <button
                    type="reset"
                    className="crm65-secondary"
                    disabled={locked || !dirty}
                    onClick={() => {
                        setDirty(false);
                        onDirty(false);
                        setError(null);
                    }}
                >
                    Отменить
                </button>
            </div>
        </form>
    );
}

export function InteractionForm74({
                                      locked,
                                      submit,
                                      onDirty,
                                  }: Props) {
    const [kind, setKind] = useState("note");
    const [body, setBody] = useState("");
    const [followUp, setFollowUp] = useState("");
    const [error, setError] = useState<string | null>(null);

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError(null);

        if (locked) return;

        if (body.trim().length < 2) {
            setError("Введите текст записи.");
            return;
        }

        const date = followUp ? new Date(followUp) : null;

        if (date && !Number.isFinite(date.getTime())) {
            setError("Некорректная дата следующего контакта.");
            return;
        }

        submit({
            kind,
            body: body.trim(),

            follow_up_at: date
                ? date.toISOString().replace(/\.\d{3}Z$/, "Z")
                : null,
        });
    }

    return (
        <form
            className="crm74-stack"
            onSubmit={save}
            onChange={() => onDirty(true)}
        >
            <fieldset className="crm74-fieldset" disabled={locked}>
                <div className="crm74-form-grid">
                    <label>
                        Тип взаимодействия
                        <select
                            value={kind}
                            onChange={(event) => setKind(event.target.value)}
                        >
                            <option value="note">Внутренняя заметка</option>
                            <option value="call">Телефонный звонок</option>
                            <option value="message">Переписка</option>
                            <option value="complaint">Обращение / жалоба</option>
                            <option value="offer">Персональное предложение</option>
                        </select>
                    </label>

                    <label>
                        Следующий контакт (необязательно)
                        <input
                            type="datetime-local"
                            value={followUp}
                            onChange={(event) =>
                                setFollowUp(event.target.value)
                            }
                        />
                    </label>
                </div>

                <label>
                    {kind === "offer"
                        ? "Условия персонального предложения"
                        : "Описание взаимодействия"}

                    <textarea
                        rows={5}
                        required
                        minLength={2}
                        maxLength={1000}
                        value={body}
                        onChange={(event) => setBody(event.target.value)}
                    />
                </label>

                <small className="crm65-muted">
                    {body.length}/1000
                </small>
            </fieldset>

            {kind === "offer" && (
                <p className="crm74-info">
                    Предложение фиксируется в CRM. Оно не создаёт промокод,
                    не изменяет цены и не отправляется покупателю автоматически.
                </p>
            )}

            {error && <p className="crm65-error">{error}</p>}

            <div className="crm74-actions">
                <button
                    className="crm65-primary"
                    disabled={locked || body.trim().length < 2}
                >
                    Сохранить запись
                </button>

                <button
                    type="button"
                    className="crm65-secondary"
                    disabled={locked}
                    onClick={() => {
                        setKind("note");
                        setBody("");
                        setFollowUp("");
                        setError(null);
                        onDirty(false);
                    }}
                >
                    Очистить
                </button>
            </div>
        </form>
    );
}