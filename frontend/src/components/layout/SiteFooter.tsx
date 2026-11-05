import Image from "next/image";
import Link from "next/link";

type Locale = "uk" | "ru";

const Icon = ({ kind }: { kind: "mail" | "phone" | "arrow" }) => {
    if (kind === "mail") {
        return (
            <svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.6"
                className="h-5 w-5 shrink-0"
            >
                <rect x="2.8" y="5" width="18.4" height="14" rx="1.5" />
                <path d="m3.5 6 8.5 7 8.5-7" />
            </svg>
        );
    }

    if (kind === "phone") {
        return (
            <svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.7"
                className="h-5 w-5 shrink-0"
            >
                <path d="M5 3.5 8.5 3l2.1 4.8-2.2 1.7a16 16 0 0 0 6.1 6.1l1.7-2.2 4.8 2.1-.5 3.5a2 2 0 0 1-2.2 1.7C10 19.9 4.1 14 3.3 5.7A2 2 0 0 1 5 3.5Z" />
            </svg>
        );
    }

    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            className="h-5 w-5 shrink-0"
        >
            <path d="M4 12h15m-6-6 6 6-6 6" />
        </svg>
    );
};

function InactiveLinks({ items }: { items: string[] }) {
    return (
        <ul className="mt-5 space-y-3 text-[13px] leading-5 text-white/65">
            {items.map((name) => (
                <li key={name}>{name}</li>
            ))}
        </ul>
    );
}

function StoreBadge({
                        store,
                        soon,
                    }: {
    store: "apple" | "google";
    soon: string;
}) {
    return (
        <div
            aria-label={`${store === "apple" ? "App Store" : "Google Play"}: ${soon}`}
            title={soon}
            className="flex h-[44px] w-[144px] items-center gap-2 rounded-[5px] border border-white/30 bg-[#0B0B0B] px-3 text-white/80"
        >
      <span aria-hidden="true" className="text-[22px] leading-none">
        {store === "apple" ? "●" : "▷"}
      </span>

            <span className="leading-tight">
        <span className="block text-[9px] text-white/50">
          {soon}
        </span>

        <span className="block text-[15px] font-semibold">
          {store === "apple" ? "App Store" : "Google Play"}
        </span>
      </span>
        </div>
    );
}

export default function SiteFooter({
                                       locale,
                                   }: {
    locale: Locale;
}) {
    const ru = locale === "ru";

    const c = ru
        ? {
            newsletter: "Новости, акции и новые коллекции",
            soon: "Скоро",
            app: "Доступ к разным разделам в приложении",
            appSoon: "Приложение скоро",
            qr: "QR приложения",
            contacts: "Контакты",
            contactLater: "Контакты поддержки добавим перед запуском",
            help: "Помощь",
            company: "Компания",
            brands: "Boardriders Inc.",
            delivery: "Доставка",
            payment: "Оплата",
            returns: "Возврат",
            track: "Статус заказа",
            discount: "Акции и скидки",
            about: "О компании",
            careers: "Вакансии",
            terms: "Публичная оферта",
            privacy: "Конфиденциальность",
            copyright: "Все права защищены.",
            demo: "Демо-версия магазина",
        }
        : {
            newsletter: "Новини, акції та нові колекції",
            soon: "Незабаром",
            app: "Доступ до різних розділів у застосунку",
            appSoon: "Застосунок незабаром",
            qr: "QR застосунку",
            contacts: "Контакти",
            contactLater: "Контакти підтримки додамо перед запуском",
            help: "Допомога",
            company: "Компанія",
            brands: "Boardriders Inc.",
            delivery: "Доставка",
            payment: "Оплата",
            returns: "Повернення",
            track: "Статус замовлення",
            discount: "Акції та знижки",
            about: "Про компанію",
            careers: "Вакансії",
            terms: "Публічна оферта",
            privacy: "Конфіденційність",
            copyright: "Усі права захищені.",
            demo: "Демо-версія магазину",
        };

    return (
        <footer className="w-full bg-white text-white">
            {/* Двойной зигзаг сверху — как в Figma */}
            <svg
                className="block h-[70px] w-full sm:h-[98px]"
                viewBox="0 0 1440 112"
                preserveAspectRatio="none"
                aria-hidden="true"
            >
                <rect width="1440" height="112" fill="white" />

                <path
                    d="M0 56 350 105 680 28 1090 99 1440 63V112H0Z"
                    fill="#050505"
                />

                <path
                    d="M0 30 350 84 680 7 1090 78 1440 42"
                    fill="none"
                    stroke="#050505"
                    strokeWidth="6"
                    strokeLinejoin="round"
                />
            </svg>

            <div className="bg-[#050505] pb-8">
                <div className="mx-auto max-w-[1360px] px-5 sm:px-8 xl:px-5">

                    {/* Верх футера: логотип и рассылка */}
                    <div className="flex flex-col justify-between gap-8 border-b border-white/20 pb-7 pt-6 md:flex-row md:items-center md:pt-3">

                        <Link
                            href={`/${locale}`}
                            className="inline-flex w-fit items-center"
                            aria-label="BOARDRIDERS — головна"
                        >
                            <Image
                                src="/brand/boardriders-logo.png"
                                width={270}
                                height={56}
                                alt="BOARDRIDERS"
                                className="h-auto w-[205px] invert sm:w-[250px]"
                            />
                        </Link>

                        {/* Пока визуальный блок, без реальной подписки */}
                        <div
                            aria-label={`${c.newsletter}. ${c.soon}`}
                            className="flex min-h-[50px] w-full max-w-[550px] items-center gap-3 border border-white/25 px-4 text-white/75"
                        >
                            <Icon kind="mail" />

                            <span className="min-w-0 flex-1 text-[12px] sm:text-sm">
                {c.newsletter}
              </span>

                            <span className="whitespace-nowrap text-xs text-[#EB5757]">
                {c.soon}
              </span>

                            <Icon kind="arrow" />
                        </div>
                    </div>

                    {/* Основные колонки */}
                    <div className="grid gap-x-7 gap-y-10 border-b border-white/20 py-10 sm:grid-cols-2 lg:grid-cols-[1.65fr_1.15fr_.8fr_.8fr_.9fr] lg:py-12">

                        {/* Приложение */}
                        <section>
                            <h2 className="text-[13px] font-semibold leading-5">
                                {c.app}
                            </h2>

                            <div className="mt-5 flex flex-wrap items-start gap-4">
                                <div className="flex flex-col gap-2">
                                    <StoreBadge
                                        store="apple"
                                        soon={c.appSoon}
                                    />

                                    <StoreBadge
                                        store="google"
                                        soon={c.appSoon}
                                    />
                                </div>

                                {/* Заглушка QR до появления приложения */}
                                <div className="flex h-[96px] w-[96px] shrink-0 flex-col items-center justify-center border border-white/60 bg-white text-center text-black">
                  <span className="text-xl font-bold">
                    QR
                  </span>

                                    <span className="mt-1 px-1 text-[9px] leading-3">
                    {c.soon}
                  </span>
                                </div>
                            </div>
                        </section>

                        {/* Контакты */}
                        <section>
                            <h2 className="text-[13px] font-semibold">
                                {c.contacts}
                            </h2>

                            <div className="mt-5 flex gap-3 text-white/70">
                                <Icon kind="phone" />

                                <p className="max-w-[220px] text-[12px] leading-5">
                                    {c.contactLater}
                                </p>
                            </div>

                            <div className="mt-5 flex gap-3 text-white/70">
                                <Icon kind="mail" />

                                <Link
                                    href={`/${locale}/products`}
                                    className="text-[12px] underline-offset-4 hover:underline"
                                >
                                    {ru ? "Каталог товаров" : "Каталог товарів"}
                                </Link>
                            </div>
                        </section>

                        {/* Помощь */}
                        <section>
                            <h2 className="text-[13px] font-semibold">
                                {c.help}
                            </h2>

                            <InactiveLinks
                                items={[
                                    c.delivery,
                                    c.payment,
                                    c.returns,
                                    c.track,
                                    c.discount,
                                ]}
                            />
                        </section>

                        {/* Компания */}
                        <section>
                            <h2 className="text-[13px] font-semibold">
                                {c.company}
                            </h2>

                            <InactiveLinks
                                items={[
                                    c.about,
                                    c.careers,
                                    c.terms,
                                    c.privacy,
                                ]}
                            />
                        </section>

                        {/* Бренды */}
                        <section>
                            <h2 className="text-[13px] font-semibold">
                                {c.brands}
                            </h2>

                            <InactiveLinks
                                items={[
                                    "Quiksilver",
                                    "ROXY",
                                    "DC Shoes",
                                    "Billabong",
                                    "RVCA",
                                ]}
                            />
                        </section>
                    </div>

                    {/* Нижняя часть: соцсети и копирайт */}
                    <div className="flex flex-col gap-6 pt-6 text-white/65 sm:flex-row sm:items-center sm:justify-between">

                        <div
                            className="flex items-center gap-5 text-lg font-bold text-white/90"
                            aria-label={
                                ru
                                    ? "Социальные сети (скоро)"
                                    : "Соціальні мережі (незабаром)"
                            }
                        >
                            {/* Пока декоративные значки */}
                            <span title="Facebook">f</span>
                            <span title="YouTube">▶</span>
                            <span title="Instagram">◎</span>
                            <span title="TikTok">♪</span>
                        </div>

                        <p className="text-xs leading-5 sm:text-right">
                            © 2026 BOARDRIDERS. {c.copyright}
                            <br />

                            <span className="text-white/40">
                {c.demo}
              </span>
                        </p>
                    </div>

                </div>
            </div>
        </footer>
    );
}