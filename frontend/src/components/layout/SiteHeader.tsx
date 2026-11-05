"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useState } from "react";
import { useCartCount } from "@/lib/cart";

type Locale = "uk" | "ru";
type IconName = "user" | "heart" | "cart" | "search" | "menu" | "close";
type Audience = "men" | "women" | "kids";

const navigation = [
    ["Новинки", "Новинки"],
    ["Сноуборд", "Сноуборд"],
    ["Лижі", "Лыжи"],
    ["Скейт", "Скейт"],
    ["Лонгборд", "Лонгборд"],
    ["Вейкборд", "Вейкборд"],
    ["Серф", "Серф"],
    ["SUP", "SUP"],
    ["Одяг", "Одежда"],
    ["Взуття", "Обувь"],
    ["Аксесуари", "Аксессуары"],
    ["Бренди", "Бренды"],
    ["Розпродаж", "Распродажа"],
] as const;

const audiences: { key: Audience; uk: string; ru: string }[] = [
    { key: "men", uk: "Чоловікам", ru: "Мужчинам" },
    { key: "women", uk: "Жінкам", ru: "Женщинам" },
    { key: "kids", uk: "Дітям", ru: "Детям" },
];

function Icon({ name, className = "h-6 w-6" }: { name: IconName; className?: string }) {
    const paths = {
        user: <><circle cx="12" cy="8" r="4" /><path d="M4 21v-2a8 8 0 0 1 16 0v2" /></>,
        heart: <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0l-1 1-1-1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z" />,
        cart: <><path d="M3 4h2l2.2 11h11.6L21 7H6" /><circle cx="9" cy="20" r="1" /><circle cx="18" cy="20" r="1" /></>,
        search: <><circle cx="10.8" cy="10.8" r="7" /><path d="m16 16 5 5" /></>,
        menu: <path d="M3 6h18M3 12h18M3 18h18" />,
        close: <path d="M5 5l14 14M19 5 5 19" />,
    };
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7"
             strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}>
            {paths[name]}
        </svg>
    );
}

function ComingSoon({
                        icon,
                        label,
                        compact = false,
                        badge = 0,
                    }: {
    icon: IconName;
    label: string;
    compact?: boolean;
    badge?: number;
}) {
    return (
        <button type="button" disabled title={`${label} — скоро`}
                aria-label={`${label} — скоро`}
                className="relative flex min-w-[52px] cursor-not-allowed flex-col items-center justify-center gap-1 opacity-60">
            <Icon name={icon} className="h-[23px] w-[23px]" />
                {badge > 0 && (
                    <span
                        className="
          absolute -right-1 -top-2
          flex min-h-5 min-w-5
          items-center justify-center
          rounded-full bg-red-600
          px-1 text-[10px]
          font-bold text-white
        "
                    >
        {badge > 99 ? "99+" : badge}
      </span>
                )}
            {!compact && <span className="text-[10px] leading-3">{label}</span>}
        </button>
    );
}

export default function SiteHeader({
                                       locale,
                                   }: {
    locale: Locale;
}) {
    const ru = locale === "ru";
    const cartCount = useCartCount();
    const [audience, setAudience] = useState<Audience>("men");
    const [menuOpen, setMenuOpen] = useState(false);
    const catalogHref = `/${locale}/products`;
    const homeHref = `/${locale}`;

    useEffect(() => {
        if (!menuOpen) return;
        const original = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === "Escape") setMenuOpen(false);
        };
        document.addEventListener("keydown", closeOnEscape);
        return () => {
            document.body.style.overflow = original;
            document.removeEventListener("keydown", closeOnEscape);
        };
    }, [menuOpen]);

    const audienceTabs = audiences.map((item) => (
        <button key={item.key} type="button" onClick={() => setAudience(item.key)}
                aria-pressed={audience === item.key}
                className={`relative pb-1 text-[14px] font-medium transition-colors ${
                    audience === item.key ? "text-black after:absolute after:inset-x-0 after:-bottom-2 after:h-[2px] after:bg-black" : "text-[#828B8D] hover:text-black"
                }`}>
            {ru ? item.ru : item.uk}
        </button>
    ));

    return (
        <header className="relative z-40 w-full bg-white text-black">
            {/* Figma: desktop Menus = 50px + 94px + 60px */}
            <div className="hidden h-[50px] bg-black text-white xl:block">
                <div className="mx-auto flex h-full max-w-[1360px] items-center justify-between gap-4 px-5 text-[12px]">
                    <div className="flex items-center gap-10">
                        <span>{ru ? "Регион доставки: Украина" : "Регіон доставки: Україна"}</span>
                        <span className="text-white/80">{ru ? "Магазины · Помощь · Блог" : "Магазини · Допомога · Блог"}</span>
                    </div>
                    <span className="text-white/80">{ru ? "Информация о доставке и возврате" : "Інформація про доставку та повернення"}</span>
                </div>
            </div>

            <div className="relative mx-auto hidden h-[94px] max-w-[1360px] items-center justify-between px-5 xl:flex">
                <div className="flex min-w-[310px] gap-[25px]">{audienceTabs}</div>
                <Link href={homeHref} aria-label="BOARDRIDERS — каталог"
                      className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2">
                    <Image src="/brand/boardriders-logo.png" alt="BOARDRIDERS"
                           width={243} height={50} loading="eager" className="h-[50px] w-[243px] object-contain" />
                </Link>
                <div className="flex items-center gap-4">
                    <ComingSoon icon="user" label={ru ? "Войти" : "Увійти"} />
                    <ComingSoon icon="heart" label={ru ? "Избранное" : "Обране"} />
                    <Link
                        href={`/${locale}/cart`}
                        aria-label={ru ? "Корзина" : "Кошик"}
                        className="relative flex min-w-[52px] flex-col items-center justify-center gap-1 transition-colors hover:text-[#EB5757]"
                    >
                        <Icon
                            name="cart"
                            className="h-[23px] w-[23px]"
                        />

                        {cartCount > 0 && (
                            <span className="absolute -right-1 -top-2 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-[#EB5757] px-1 text-[10px] font-bold text-white">
      {cartCount > 99 ? "99+" : cartCount}
    </span>
                        )}

                        <span className="text-[10px] leading-3">
    {ru ? "Корзина" : "Кошик"}
  </span>
                    </Link>
                    <ComingSoon icon="search" label={ru ? "Поиск" : "Пошук"} />
                </div>
            </div>

            <nav aria-label={ru ? "Категории товаров" : "Категорії товарів"}
                 className="hidden h-[60px] bg-[#F9F9F9] xl:block">
                <div className="mx-auto flex h-full max-w-[1360px] items-center justify-between gap-3 px-5">
                    {navigation.map(([uk, ruLabel], index) => (
                        <Link key={uk} href={catalogHref}
                              className={`whitespace-nowrap text-[13px] font-medium hover:underline ${index === navigation.length - 1 ? "text-[#EB5757]" : "text-black"}`}>
                            {ru ? ruLabel : uk}
                        </Link>
                    ))}
                </div>
            </nav>

            {/* Mobile/tablet: compact version of the Figma menu. */}
            <div className="flex h-[70px] items-center justify-between border-b border-[#CED4D7] px-4 xl:hidden">
                <button type="button" onClick={() => setMenuOpen(true)} aria-label={ru ? "Открыть меню" : "Відкрити меню"}
                        aria-expanded={menuOpen} aria-controls="boardriders-mobile-menu" className="p-2">
                    <Icon name="menu" />
                </button>
                <Link
                    href={homeHref}
                    aria-label="BOARDRIDERS — головна"
                >
                    <Image src="/brand/boardriders-logo.png" alt="BOARDRIDERS"
                           width={181} height={38} priority className="h-[38px] w-[181px] object-contain" />
                </Link>
                <Link
                    href={`/${locale}/cart`}
                    aria-label={ru ? "Корзина" : "Кошик"}
                    className="relative flex min-w-[44px] items-center justify-center p-2 transition-colors hover:text-[#EB5757]"
                >
                    <Icon
                        name="cart"
                        className="h-[23px] w-[23px]"
                    />

                    {cartCount > 0 && (
                        <span className="absolute -right-1 -top-1 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-[#EB5757] px-1 text-[10px] font-bold text-white">
      {cartCount > 99 ? "99+" : cartCount}
    </span>
                    )}
                </Link>
            </div>
            <div className="flex h-[45px] items-center justify-around bg-[#F9F9F9] xl:hidden">
                {audienceTabs}
            </div>

            {menuOpen && (
                <div id="boardriders-mobile-menu" role="dialog" aria-modal="true"
                     aria-label={ru ? "Меню магазина" : "Меню магазину"}
                     className="fixed inset-0 z-[70] overflow-y-auto bg-white xl:hidden">
                    <div className="flex h-[70px] items-center justify-between border-b border-[#CED4D7] px-5">
                        <Image src="/brand/boardriders-logo.png" alt="BOARDRIDERS" width={181} height={38}
                               className="h-[38px] w-[181px] object-contain" />
                        <button type="button" aria-label={ru ? "Закрыть меню" : "Закрити меню"}
                                onClick={() => setMenuOpen(false)} className="p-2"><Icon name="close" /></button>
                    </div>
                    <div className="flex justify-around border-b border-[#CED4D7] px-4 py-5">{audienceTabs}</div>
                    <nav aria-label={ru ? "Категории" : "Категорії"} className="px-5 py-3">
                        {navigation.map(([uk, ruLabel], index) => (
                            <Link key={uk} href={catalogHref} onClick={() => setMenuOpen(false)}
                                  className={`flex min-h-[47px] items-center justify-between border-b border-[#F1F1F1] text-[15px] ${
                                      index === navigation.length - 1 ? "font-semibold text-[#EB5757]" : "text-black"
                                  }`}>
                                {ru ? ruLabel : uk}<span aria-hidden="true" className="text-[#828B8D]">›</span>
                            </Link>
                        ))}
                        <div className="mt-7 flex gap-5 text-sm">
                            <Link onClick={() => setMenuOpen(false)} href="/uk/products" className={!ru ? "font-bold underline" : "text-[#828B8D]"}>Українська</Link>
                            <Link onClick={() => setMenuOpen(false)} href="/ru/products" className={ru ? "font-bold underline" : "text-[#828B8D]"}>Русский</Link>
                        </div>
                        <p className="mt-8 text-xs text-[#828B8D]">{ru ? "Регион доставки: Украина" : "Регіон доставки: Україна"}</p>
                    </nav>
                </div>
            )}
        </header>
    );
}
