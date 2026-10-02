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
    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 px-6 py-3 text-sm text-zinc-600">
      <span>Halaman {page} dari {lastPage} · {total.toLocaleString('id-ID')} {unit}</span>
      <div className="flex gap-2">
        <button onClick={() => onPage(page - 1)} disabled={page <= 1 || loading} className="rounded-lg border border-zinc-200 px-3 py-1 disabled:opacity-50">Sebelumnya</button>
        <button onClick={() => onPage(page + 1)} disabled={page >= lastPage || loading} className="rounded-lg border border-zinc-200 px-3 py-1 disabled:opacity-50">Berikutnya</button>
      </div>
    </div>
  );
}
