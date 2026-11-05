type Health = {
  service?: string;
  status?: string;
  database?: string;
};

async function getHealth(): Promise<Health | null> {
  try {
    const url = `${process.env.API_INTERNAL_URL ?? "http://127.0.0.1:8000"}/api/health`;
    const response = await fetch(url, { cache: "no-store" });
    if (!response.ok) return null;
    return (await response.json()) as Health;
  } catch {
    return null;
  }
}

const modules = [
  { name: "Catalog", label: "Каталог и категории", index: "01" },
  { name: "Inventory", label: "Склады и остатки", index: "02" },
  { name: "Suppliers", label: "Поставщики и закупки", index: "03" },
  { name: "Orders", label: "Заказы и оплаты", index: "04" },
];

export default async function Home() {
  const health = await getHealth();
  const connected = health?.status === "ok" && health.database === "connected";

  return (
    <main className="min-h-screen bg-[#111216] px-6 py-10 text-white sm:px-12 lg:px-20">
      <div className="mx-auto max-w-6xl">
        <header className="flex flex-wrap items-center justify-between gap-4 border-b border-white/15 pb-7">
          <span className="text-2xl font-black tracking-[0.16em]">BOARDRIDERS</span>
          <span className="rounded-full border border-white/20 px-4 py-2 text-xs uppercase tracking-[0.2em] text-white/65">
            Development environment
          </span>
        </header>

        <div className="max-w-3xl py-20">
          <p className="mb-4 text-sm font-semibold uppercase tracking-[0.25em] text-amber-300">
            Platform / Foundation
          </p>
          <h1 className="text-5xl font-bold tracking-tight sm:text-7xl">
            Начало большой системы.
          </h1>
          <p className="mt-6 max-w-2xl text-lg leading-8 text-white/60">
            Технический экран первого этапа. На следующем этапе заменим его
            витриной по утверждённому Figma-макету.
          </p>
        </div>

        <div className="grid gap-4 md:grid-cols-3">
          <section className="rounded-2xl border border-white/15 bg-white/5 p-6">
            <p className="text-sm text-white/50">Next.js frontend</p>
            <p className="mt-3 text-2xl font-semibold">Работает</p>
          </section>
          <section className="rounded-2xl border border-white/15 bg-white/5 p-6">
            <p className="text-sm text-white/50">Laravel API</p>
            <p className={`mt-3 text-2xl font-semibold ${connected ? "text-emerald-300" : "text-amber-300"}`}>
              {connected ? "Подключено" : "Нет соединения"}
            </p>
          </section>
          <section className="rounded-2xl border border-white/15 bg-white/5 p-6">
            <p className="text-sm text-white/50">PostgreSQL</p>
            <p className={`mt-3 text-2xl font-semibold ${connected ? "text-emerald-300" : "text-amber-300"}`}>
              {connected ? "Подключена" : "Не проверена"}
            </p>
          </section>
        </div>

        <div className="mt-14">
          <h2 className="mb-5 text-xl font-semibold">Запланированные модули</h2>
          <div className="grid gap-3 md:grid-cols-2">
            {modules.map((module) => (
              <div key={module.index} className="flex items-center gap-5 rounded-xl border border-white/10 p-5">
                <span className="text-2xl font-semibold text-white/30">{module.index}</span>
                <div>
                  <p className="font-semibold">{module.name}</p>
                  <p className="mt-1 text-sm text-white/50">{module.label}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </main>
  );
}
