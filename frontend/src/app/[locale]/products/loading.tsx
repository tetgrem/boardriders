export default function Loading() {
    return (
        <main className="min-h-screen bg-neutral-50">
            <div className="mx-auto max-w-7xl px-4 py-10 sm:px-8">
                <div className="mb-10">
                    <div className="mb-4 h-4 w-28 animate-pulse rounded bg-neutral-200" />
                    <div className="h-10 w-64 animate-pulse rounded bg-neutral-200" />
                </div>

                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                    {Array.from({ length: 8 }, (_, index) => (
                        <div key={index} className="animate-pulse">
                            <div className="aspect-[4/5] rounded-lg bg-neutral-200" />
                            <div className="mt-3 h-4 w-24 rounded bg-neutral-200" />
                            <div className="mt-2 h-5 w-full rounded bg-neutral-200" />
                            <div className="mt-2 h-5 w-20 rounded bg-neutral-200" />
                        </div>
                    ))}
                </div>
            </div>
        </main>
    );
}