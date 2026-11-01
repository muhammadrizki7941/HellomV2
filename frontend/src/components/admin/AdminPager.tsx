import type { AdminPagination } from '@/lib/hellomApi';

/** Previous/next footer for server-paginated admin tables (hidden when everything fits on one page). */
export default function AdminPager({ pagination, loading, unit, onPage }: {
  pagination: AdminPagination | null;
  loading?: boolean;
  unit: string;
  onPage: (page: number) => void;
}) {
  if (!pagination || pagination.last_page <= 1) return null;
  const { current_page: page, last_page: lastPage, total } = pagination;

  return (
    <nav aria-label="Navigasi halaman data" className="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-4 py-4 text-xs text-zinc-600 sm:px-6">
      <span>Halaman {page} dari {lastPage} · {total.toLocaleString('id-ID')} {unit}</span>
      <div className="flex w-full gap-2 sm:w-auto">
        <button onClick={() => onPage(page - 1)} disabled={page <= 1 || loading} className="min-h-11 flex-1 rounded-lg border border-zinc-200 bg-white px-4 py-2 disabled:opacity-50">Sebelumnya</button>
        <button onClick={() => onPage(page + 1)} disabled={page >= lastPage || loading} className="min-h-11 flex-1 rounded-lg border border-zinc-200 bg-white px-4 py-2 disabled:opacity-50">Berikutnya</button>
      </div>
    </nav>
  );
}
