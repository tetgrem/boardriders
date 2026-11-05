// ========================================
// BOARDRIDERS — MEDIA URL
// ========================================

/**
 * Перетворює абсолютні URL фотографій
 * Laravel на внутрішні URL Next.js.
 *
 * Це дозволяє відкривати фотографії
 * з комп'ютера, телефона та планшета
 * без прямого доступу до Laravel.
 */

export function productImageUrl(
    value: string
): string {
    if (!value) return value;

    // Внутрішній URL уже правильний.
    if (value.startsWith("/storage/products/")) {
        return value;
    }

    try {
        const url = new URL(value);

        // Фотографії з публічного сховища Laravel.
        if (
            url.pathname.startsWith("/storage/products/")
        ) {
            return `${url.pathname}${url.search}`;
        }

        // Інші адреси, наприклад CDN,
        // залишаємо без змін.
        return value;
    } catch {
        return value;
    }
}