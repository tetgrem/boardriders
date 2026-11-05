"use client";

import Image from "next/image";
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from "react";

import type {
    MouseEvent as ReactMouseEvent,
    PointerEvent as ReactPointerEvent,
} from "react";

import { productImageUrl } from "@/lib/media";

type Locale = "uk" | "ru";

type ImageRecord = {
    url?: unknown;
    src?: unknown;
    image_url?: unknown;
    alt?: unknown;
    alt_uk?: unknown;
    alt_ru?: unknown;
};

type Photo = {
    src: string;
    alt: string;
};

type Props = {
    cover?: unknown;
    images?: readonly unknown[] | null;
    productName: string;
    locale: Locale;
};

type Point = {
    x: number;
    y: number;
};

type Gesture = {
    id: number;
    start: Point;
    initialOffset: Point;
    moved: boolean;
};

type SlideState = {
    selected: number;
    previous: number | null;
    direction: 1 | -1;
    sequence: number;
};

const SWIPE_DISTANCE = 50;
const MOVE_TOLERANCE = 8;
const SLIDE_DURATION = 320;

// ========================================
// ПІДГОТОВКА ФОТОГРАФІЙ
// ========================================

function parsePhoto(
    value: unknown,
    fallbackAlt: string,
    locale: Locale
): Photo | null {
    if (typeof value === "string") {
        return value.trim()
            ? { src: value.trim(), alt: fallbackAlt }
            : null;
    }

    if (!value || typeof value !== "object") {
        return null;
    }

    const record = value as ImageRecord;

    const src = [
        record.url,
        record.src,
        record.image_url,
    ].find(
        (candidate): candidate is string =>
            typeof candidate === "string" &&
            candidate.trim().length > 0
    );

    if (!src) return null;

    const localizedAlt =
        locale === "uk" ? record.alt_uk : record.alt_ru;

    const alt =
        [
            localizedAlt,
            record.alt,
            fallbackAlt,
        ].find(
            (candidate): candidate is string =>
                typeof candidate === "string" &&
                candidate.trim().length > 0
        ) ?? fallbackAlt;

    return {
        src: productImageUrl(src.trim()),
        alt: alt.trim(),
    };
}

function isSwipe(dx: number, dy: number): boolean {
    return (
        Math.abs(dx) >= SWIPE_DISTANCE &&
        Math.abs(dx) > Math.abs(dy) * 1.25
    );
}

// ========================================
// КОМПОНЕНТ АНІМАЦІЇ ПЕРЕМИКАННЯ
// ========================================

function SlidingImages({
                           photos,
                           slide,
                           sizes,
                           padding,
                       }: {
    photos: Photo[];
    slide: SlideState;
    sizes: string;
    padding: string;
}) {
    const current =
        photos[Math.min(slide.selected, photos.length - 1)];

    const previous =
        slide.previous === null
            ? null
            : photos[slide.previous];

    const incoming =
        slide.direction === 1
            ? "br-enter-next"
            : "br-enter-prev";

    const outgoing =
        slide.direction === 1
            ? "br-leave-next"
            : "br-leave-prev";

    if (!current) return null;

    return (
        <div className="pointer-events-none absolute inset-0 overflow-hidden select-none">

            {/* ПОПЕРЕДНЯ ФОТОГРАФІЯ ВИЇЖДЖАЄ */}
            {previous && (
                <div
                    key={`previous-${slide.sequence}`}
                    className={`absolute inset-0 ${outgoing}`}
                >
                    <Image
                        unoptimized
                        draggable={false}
                        src={previous.src}
                        loading="eager"
                        alt={previous.alt}
                        fill
                        sizes={sizes}
                        className={`pointer-events-none object-contain select-none ${padding}`}
                    />
                </div>
            )}

            {/* НОВА ФОТОГРАФІЯ ЗАЇЖДЖАЄ */}
            <div
                key={`current-${slide.sequence}`}
                className={`absolute inset-0 ${
                    previous ? incoming : ""
                }`}
            >
                <Image
                    unoptimized
                    draggable={false}
                    src={current.src}
                    alt={current.alt}
                    fill
                    priority={slide.selected === 0}
                    sizes={sizes}
                    className={`pointer-events-none object-contain select-none ${padding}`}
                />
            </div>

        </div>
    );
}

// ========================================
// ОСНОВНА ГАЛЕРЕЯ
// ========================================

export default function ProductGallery({
                                           cover,
                                           images,
                                           productName,
                                           locale,
                                       }: Props) {
    const uk = locale === "uk";

    // Стан анімованого перемикання.
    const [slide, setSlide] = useState<SlideState>({
        selected: 0,
        previous: null,
        direction: 1,
        sequence: 0,
    });

    const [viewerOpen, setViewerOpen] = useState(false);

    // Масштабування.
    const [zoom, setZoom] = useState(1);

    const [offset, setOffset] = useState<Point>({
        x: 0,
        y: 0,
    });

    // Посилання на елементи.
    const openButton = useRef<HTMLButtonElement>(null);
    const closeButton = useRef<HTMLButtonElement>(null);
    const dialog = useRef<HTMLDivElement>(null);

    // Жести.
    const mainGesture = useRef<Gesture | null>(null);
    const viewerGesture = useRef<Gesture | null>(null);

    const suppressMainClick = useRef(false);

    // ========================================
    // ФОРМУВАННЯ МАСИВУ ФОТОГРАФІЙ
    // ========================================

    const photos = useMemo(() => {
        const items: Photo[] = [];
        const seen = new Set<string>();

        for (const item of [cover, ...(images ?? [])]) {
            const photo = parsePhoto(
                item,
                productName,
                locale
            );

            if (!photo || seen.has(photo.src)) {
                continue;
            }

            items.push(photo);
            seen.add(photo.src);
        }

        return items;
    }, [cover, images, productName, locale]);

    const index = Math.min(
        slide.selected,
        Math.max(photos.length - 1, 0)
    );

    const current = photos[index];

    // ========================================
    // ЗАВЕРШЕННЯ АНІМАЦІЇ
    // ========================================

    useEffect(() => {
        if (slide.previous === null) return;

        const timer = window.setTimeout(() => {
            setSlide((value) => {
                if (value.sequence !== slide.sequence) {
                    return value;
                }

                return {
                    ...value,
                    previous: null,
                };
            });
        }, SLIDE_DURATION + 30);

        return () => {
            window.clearTimeout(timer);
        };
    }, [slide.sequence, slide.previous]);

    // ========================================
    // ПЕРЕМИКАННЯ ВПЕРЕД / НАЗАД
    // ========================================

    const change = useCallback(
        (direction: number) => {
            if (photos.length < 2) return;

            setSlide((value) => {
                const from = Math.min(
                    value.selected,
                    photos.length - 1
                );

                const to =
                    (from + direction + photos.length) %
                    photos.length;

                return {
                    selected: to,
                    previous: from,
                    direction: direction >= 0 ? 1 : -1,
                    sequence: value.sequence + 1,
                };
            });

            // При зміні фото повертаємо масштаб.
            setZoom(1);
            setOffset({ x: 0, y: 0 });
        },
        [photos.length]
    );

    // ========================================
    // ВИБІР ФОТО З МІНІАТЮР
    // ========================================

    function selectPhoto(next: number) {
        setSlide((value) => {
            const from = Math.min(
                value.selected,
                photos.length - 1
            );

            if (next === from) {
                return value;
            }

            return {
                selected: next,
                previous: from,
                direction: next > from ? 1 : -1,
                sequence: value.sequence + 1,
            };
        });

        setZoom(1);
        setOffset({ x: 0, y: 0 });
    }

    // ========================================
    // ВІДКРИТТЯ ТА ЗАКРИТТЯ
    // ========================================

    const closeViewer = useCallback(() => {
        setViewerOpen(false);
        setZoom(1);
        setOffset({ x: 0, y: 0 });
    }, []);

    // ========================================
    // МАСШТАБУВАННЯ
    // ========================================

    function setMagnification(value: number) {
        setZoom(
            Math.min(3, Math.max(1, value))
        );

        setOffset({ x: 0, y: 0 });
    }

    // ========================================
    // КЕРУВАННЯ КЛАВІАТУРОЮ
    // ========================================

    useEffect(() => {
        if (!viewerOpen) return;

        const previousOverflow =
            document.body.style.overflow;

        const opener = openButton.current;

        document.body.style.overflow = "hidden";

        closeButton.current?.focus();

        function onKeyDown(event: KeyboardEvent) {
            if (event.key === "Escape") {
                event.preventDefault();
                closeViewer();
            }

            else if (event.key === "ArrowRight") {
                event.preventDefault();
                change(1);
            }

            else if (event.key === "ArrowLeft") {
                event.preventDefault();
                change(-1);
            }

            else if (event.key === "Tab") {
                const controls =
                    dialog.current?.querySelectorAll<HTMLButtonElement>(
                        "button:not(:disabled)"
                    );

                if (!controls?.length) return;

                const first = controls[0];
                const last = controls[controls.length - 1];

                if (
                    event.shiftKey &&
                    document.activeElement === first
                ) {
                    event.preventDefault();
                    last.focus();
                }

                else if (
                    !event.shiftKey &&
                    document.activeElement === last
                ) {
                    event.preventDefault();
                    first.focus();
                }
            }
        }

        document.addEventListener(
            "keydown",
            onKeyDown
        );

        return () => {
            document.body.style.overflow =
                previousOverflow;

            document.removeEventListener(
                "keydown",
                onKeyDown
            );

            opener?.focus();
        };
    }, [viewerOpen, change, closeViewer]);

    // ========================================
    // СВАЙПИ НА ЗВИЧАЙНІЙ СТОРІНЦІ
    // ========================================

    function onMainPointerDown(
        event: ReactPointerEvent<HTMLButtonElement>
    ) {
        if (
            !event.isPrimary ||
            (event.pointerType === "mouse" && event.button !== 0)
        ) {
            return;
        }

        mainGesture.current = {
            id: event.pointerId,
            start: {
                x: event.clientX,
                y: event.clientY,
            },
            initialOffset: { x: 0, y: 0 },
            moved: false,
        };

        event.currentTarget.setPointerCapture(
            event.pointerId
        );
    }

    function onMainPointerMove(
        event: ReactPointerEvent<HTMLButtonElement>
    ) {
        const gesture = mainGesture.current;

        if (
            !gesture ||
            gesture.id !== event.pointerId
        ) {
            return;
        }

        const dx = event.clientX - gesture.start.x;
        const dy = event.clientY - gesture.start.y;

        if (
            Math.hypot(dx, dy) > MOVE_TOLERANCE
        ) {
            gesture.moved = true;
        }
    }

    function onMainPointerUp(
        event: ReactPointerEvent<HTMLButtonElement>
    ) {
        const gesture = mainGesture.current;

        if (
            !gesture ||
            gesture.id !== event.pointerId
        ) {
            return;
        }

        mainGesture.current = null;

        const dx = event.clientX - gesture.start.x;
        const dy = event.clientY - gesture.start.y;

        if (gesture.moved) {
            suppressMainClick.current = true;

            window.setTimeout(() => {
                suppressMainClick.current = false;
            }, 250);
        }

        if (isSwipe(dx, dy)) {
            change(dx < 0 ? 1 : -1);
        }
    }

    function onMainPointerCancel() {
        mainGesture.current = null;
    }

    function onMainClick() {
        if (suppressMainClick.current) {
            suppressMainClick.current = false;
            return;
        }

        setViewerOpen(true);
    }

    // ========================================
    // СВАЙПИ У ПОВНОЕКРАННОМУ РЕЖИМІ
    // ========================================

    function onViewerPointerDown(
        event: ReactPointerEvent<HTMLDivElement>
    ) {
        if (
            !event.isPrimary ||
            (event.pointerType === "mouse" && event.button !== 0)
        ) {
            return;
        }

        viewerGesture.current = {
            id: event.pointerId,
            start: {
                x: event.clientX,
                y: event.clientY,
            },
            initialOffset: offset,
            moved: false,
        };

        event.currentTarget.setPointerCapture(
            event.pointerId
        );
    }

    function onViewerPointerMove(
        event: ReactPointerEvent<HTMLDivElement>
    ) {
        const gesture = viewerGesture.current;

        if (
            !gesture ||
            gesture.id !== event.pointerId
        ) {
            return;
        }

        const dx = event.clientX - gesture.start.x;
        const dy = event.clientY - gesture.start.y;

        if (
            Math.hypot(dx, dy) > MOVE_TOLERANCE
        ) {
            gesture.moved = true;
        }

        // При збільшенні переміщуємо фото.
        if (zoom > 1) {
            const area =
                event.currentTarget.getBoundingClientRect();

            const maxX =
                (area.width * (zoom - 1)) / 2;

            const maxY =
                (area.height * (zoom - 1)) / 2;

            setOffset({
                x: Math.max(
                    -maxX,
                    Math.min(
                        maxX,
                        gesture.initialOffset.x + dx
                    )
                ),

                y: Math.max(
                    -maxY,
                    Math.min(
                        maxY,
                        gesture.initialOffset.y + dy
                    )
                ),
            });
        }
    }

    function onViewerPointerUp(
        event: ReactPointerEvent<HTMLDivElement>
    ) {
        const gesture = viewerGesture.current;

        if (
            !gesture ||
            gesture.id !== event.pointerId
        ) {
            return;
        }

        viewerGesture.current = null;

        // При збільшенні свайп не перемикає фото.
        if (zoom !== 1) return;

        const dx = event.clientX - gesture.start.x;
        const dy = event.clientY - gesture.start.y;

        if (isSwipe(dx, dy)) {
            change(dx < 0 ? 1 : -1);
        }
    }

    function onViewerPointerCancel() {
        viewerGesture.current = null;
    }

    // ========================================
    // ПОДВІЙНИЙ КЛІК — МАСШТАБ
    // ========================================

    function onViewerDoubleClick(
        event: ReactMouseEvent<HTMLDivElement>
    ) {
        event.preventDefault();

        window.getSelection()?.removeAllRanges();

        setMagnification(
            zoom === 1 ? 2 : 1
        );
    }

    // ========================================
    // ЯКЩО ФОТО НЕМАЄ
    // ========================================

    if (!current) {
        return (
            <div className="flex h-[390px] items-center justify-center bg-[#F7F7F7] text-center text-sm text-[#828B8D] sm:h-[520px] lg:h-[600px]">
                {uk
                    ? "Фото товару поки немає"
                    : "Фото товара пока нет"}
            </div>
        );
    }

    // ========================================
    // ІНТЕРФЕЙС ГАЛЕРЕЇ
    // ========================================

    return (
        <section
            aria-label={
                uk
                    ? "Фотографії товару"
                    : "Фотографии товара"
            }
            className="min-w-0 select-none"
            onDragStart={(event) =>
                event.preventDefault()
            }
        >
            {/* АНІМАЦІЯ ГОРИЗОНТАЛЬНОГО СВАЙПА */}

            <style>{`
        @keyframes br-slide-enter-next {
          from {
            transform: translate3d(100%, 0, 0);
          }
          to {
            transform: translate3d(0, 0, 0);
          }
        }

        @keyframes br-slide-leave-next {
          from {
            transform: translate3d(0, 0, 0);
          }
          to {
            transform: translate3d(-100%, 0, 0);
          }
        }

        @keyframes br-slide-enter-prev {
          from {
            transform: translate3d(-100%, 0, 0);
          }
          to {
            transform: translate3d(0, 0, 0);
          }
        }

        @keyframes br-slide-leave-prev {
          from {
            transform: translate3d(0, 0, 0);
          }
          to {
            transform: translate3d(100%, 0, 0);
          }
        }

        .br-enter-next {
          animation:
            br-slide-enter-next
            ${SLIDE_DURATION}ms ease-in-out both;
        }

        .br-leave-next {
          animation:
            br-slide-leave-next
            ${SLIDE_DURATION}ms ease-in-out both;
        }

        .br-enter-prev {
          animation:
            br-slide-enter-prev
            ${SLIDE_DURATION}ms ease-in-out both;
        }

        .br-leave-prev {
          animation:
            br-slide-leave-prev
            ${SLIDE_DURATION}ms ease-in-out both;
        }

        @media (prefers-reduced-motion: reduce) {
          .br-enter-next,
          .br-leave-next,
          .br-enter-prev,
          .br-leave-prev {
            animation: none;
          }
        }
      `}</style>

            {/* ОСНОВНЕ ФОТО */}

            <div className="relative">

                <button
                    ref={openButton}
                    type="button"
                    aria-label={
                        uk
                            ? "Відкрити фото на весь екран"
                            : "Открыть фото на весь экран"
                    }
                    onPointerDown={onMainPointerDown}
                    onPointerMove={onMainPointerMove}
                    onPointerUp={onMainPointerUp}
                    onPointerCancel={onMainPointerCancel}
                    onClick={onMainClick}
                    style={{
                        touchAction: "pan-y",
                        WebkitUserSelect: "none",
                    }}
                    className="relative block h-[390px] w-full cursor-grab overflow-hidden bg-[#F7F7F7] select-none active:cursor-grabbing focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black sm:h-[520px] lg:h-[600px]"
                >
                    <SlidingImages
                        photos={photos}
                        slide={slide}
                        sizes="(max-width: 1024px) 100vw, 650px"
                        padding="p-3"
                    />

                    <span className="pointer-events-none absolute bottom-4 right-4 rounded bg-white/95 px-3 py-2 text-xs font-semibold text-black shadow-sm">
            ⤢ {uk ? "Збільшити" : "Увеличить"}
          </span>
                </button>

                {/* СТРІЛКИ */}

                {photos.length > 1 && (
                    <>
                        <button
                            type="button"
                            aria-label={
                                uk
                                    ? "Попереднє фото"
                                    : "Предыдущее фото"
                            }
                            onClick={() => change(-1)}
                            className="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-white/95 px-3 py-2 text-2xl text-black shadow hover:bg-white focus-visible:outline-2 sm:left-4"
                        >
                            ‹
                        </button>

                        <button
                            type="button"
                            aria-label={
                                uk
                                    ? "Наступне фото"
                                    : "Следующее фото"
                            }
                            onClick={() => change(1)}
                            className="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-white/95 px-3 py-2 text-2xl text-black shadow hover:bg-white focus-visible:outline-2 sm:right-4"
                        >
                            ›
                        </button>
                    </>
                )}
            </div>

            {/* МІНІАТЮРИ */}

            {photos.length > 1 && (
                <div
                    className="mt-3 flex gap-3 overflow-x-auto pb-2"
                    aria-label={
                        uk
                            ? "Вибір фотографії"
                            : "Выбор фотографии"
                    }
                >
                    {photos.map((photo, photoIndex) => (
                        <button
                            key={photo.src}
                            type="button"
                            onClick={() =>
                                selectPhoto(photoIndex)
                            }
                            aria-label={`Фото ${photoIndex + 1} ${
                                uk ? "з" : "из"
                            } ${photos.length}`}
                            aria-pressed={photoIndex === index}
                            className={`relative h-[92px] w-[76px] shrink-0 border bg-[#F7F7F7] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black ${
                                photoIndex === index
                                    ? "border-black"
                                    : "border-[#E5E5E5] hover:border-[#828B8D]"
                            }`}
                        >
                            <Image
                                unoptimized
                                draggable={false}
                                src={photo.src}
                                alt={photo.alt}
                                fill
                                sizes="76px"
                                className="pointer-events-none object-contain p-1 select-none"
                            />
                        </button>
                    ))}
                </div>
            )}

            {/* =====================================
          ПОВНОЕКРАННИЙ РЕЖИМ
      ===================================== */}

            {viewerOpen && (
                <div
                    ref={dialog}
                    role="dialog"
                    aria-modal="true"
                    aria-label={
                        uk
                            ? "Повноекранний перегляд фото"
                            : "Полноэкранный просмотр фото"
                    }
                    className="fixed inset-0 z-[100] flex flex-col bg-white select-none"
                    style={{
                        WebkitUserSelect: "none",
                    }}
                >
                    {/* ВЕРХНЯ ПАНЕЛЬ */}

                    <div className="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-[#E5E5E5] px-3 sm:px-8">

            <span className="min-w-0 flex-1 truncate text-xs font-semibold text-black sm:text-sm">
              {productName} · {index + 1}/{photos.length}
            </span>

                        {/* МАСШТАБ */}

                        <div
                            className="flex shrink-0 items-center gap-1"
                            aria-label={
                                uk ? "Масштаб" : "Масштаб"
                            }
                        >
                            <button
                                type="button"
                                onClick={() =>
                                    setMagnification(zoom - 0.5)
                                }
                                disabled={zoom <= 1}
                                aria-label={
                                    uk ? "Зменшити" : "Уменьшить"
                                }
                                className="rounded border px-2 py-1 text-lg disabled:opacity-30 sm:px-3"
                            >
                                −
                            </button>

                            <span className="w-12 text-center text-xs text-black">
                {Math.round(zoom * 100)}%
              </span>

                            <button
                                type="button"
                                onClick={() =>
                                    setMagnification(zoom + 0.5)
                                }
                                disabled={zoom >= 3}
                                aria-label={
                                    uk ? "Збільшити" : "Увеличить"
                                }
                                className="rounded border px-2 py-1 text-lg disabled:opacity-30 sm:px-3"
                            >
                                +
                            </button>
                        </div>

                        {/* ЗАКРИТТЯ */}

                        <button
                            ref={closeButton}
                            type="button"
                            onClick={closeViewer}
                            aria-label={
                                uk ? "Закрити" : "Закрыть"
                            }
                            className="rounded border border-[#E5E5E5] px-3 py-2 text-sm font-semibold text-black hover:bg-[#F7F7F7]"
                        >
                            ✕{" "}
                            <span className="hidden sm:inline">
                {uk ? "Закрити" : "Закрыть"}
              </span>
                        </button>
                    </div>

                    {/* ФОТОГРАФІЯ */}

                    <div className="relative min-h-0 flex-1 overflow-hidden bg-[#FAFAFA]">

                        <div
                            className="absolute inset-0 cursor-grab select-none active:cursor-grabbing"
                            style={{
                                touchAction:
                                    zoom > 1 ? "none" : "pan-y",
                                WebkitUserSelect: "none",
                            }}
                            onPointerDown={onViewerPointerDown}
                            onPointerMove={onViewerPointerMove}
                            onPointerUp={onViewerPointerUp}
                            onPointerCancel={onViewerPointerCancel}
                            onDoubleClick={onViewerDoubleClick}
                        >
                            <div
                                className="pointer-events-none absolute inset-0 select-none"
                                style={{
                                    transform: `translate(${offset.x}px, ${offset.y}px) scale(${zoom})`,
                                }}
                            >
                                <SlidingImages
                                    photos={photos}
                                    slide={slide}
                                    sizes="100vw"
                                    padding="p-5 sm:p-12"
                                />
                            </div>
                        </div>

                        {/* СТРІЛКИ У ПОВНОМУ РЕЖИМІ */}

                        {photos.length > 1 && zoom === 1 && (
                            <>
                                <button
                                    type="button"
                                    aria-label={
                                        uk
                                            ? "Попереднє фото"
                                            : "Предыдущее фото"
                                    }
                                    onClick={() => change(-1)}
                                    className="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-white/95 px-4 py-3 text-2xl text-black shadow sm:left-5"
                                >
                                    ‹
                                </button>

                                <button
                                    type="button"
                                    aria-label={
                                        uk
                                            ? "Наступне фото"
                                            : "Следующее фото"
                                    }
                                    onClick={() => change(1)}
                                    className="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-white/95 px-4 py-3 text-2xl text-black shadow sm:right-5"
                                >
                                    ›
                                </button>
                            </>
                        )}
                    </div>

                    {/* ПІДКАЗКА */}

                    <p className="shrink-0 py-2 text-center text-xs text-neutral-500">
                        {uk
                            ? "Перетягування — інше фото · Подвійний клік — масштаб"
                            : "Перетаскивание — другое фото · Двойной клик — масштаб"}
                    </p>
                </div>
            )}
        </section>
    );
}