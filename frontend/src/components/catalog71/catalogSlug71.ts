const letters: Record<string, string> = {
    а: "a", б: "b", в: "v",
    г: "h", ґ: "g", д: "d",
    е: "e", є: "ye", ж: "zh",
    з: "z", и: "y", і: "i",
    ї: "yi", й: "y", к: "k",
    л: "l", м: "m", н: "n",
    о: "o", п: "p", р: "r",
    с: "s", т: "t", у: "u",
    ф: "f", х: "kh", ц: "ts",
    ч: "ch", ш: "sh", щ: "shch",
    ь: "", ю: "yu", я: "ya",
    ы: "y", э: "e", ъ: "",
    ё: "yo",
    "'": "", "’": "", "ʼ": "",
};

export function catalogSlug71(value: string): string {
    return Array.from(value.toLowerCase().trim())
        .map((letter) => letters[letter] ?? letter)
        .join("")
        .normalize("NFKD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-+|-+$/g, "")
        .slice(0, 220)
        .replace(/-+$/g, "");
}