import { ChevronDown, Globe2, Link2, Lock, MoreVertical, Share2 } from 'lucide-react';

// Short illustrated guide: share a Google Drive file/folder as "Anyone with the link"
// so buyers can open it. The pictures are simplified mockups of Drive's own screens.
export default function DriveGuide() {
  return (
    <details className="group rounded-2xl border border-sky-200 bg-sky-50 text-sm text-sky-950">
      <summary className="flex min-h-12 cursor-pointer list-none items-center justify-between px-4 font-semibold">
        Cara mengatur akses Google Drive (1 menit)
        <ChevronDown className="h-4 w-4 transition group-open:rotate-180" />
      </summary>
      <ol className="space-y-4 px-4 pb-4">
        <li>
          <p><span className="font-semibold">1.</span> Di Google Drive, klik titik tiga <MoreVertical className="inline h-4 w-4" /> di file/folder → <span className="font-semibold">Bagikan</span>.</p>
          <div className="mt-2 flex items-center gap-2 rounded-xl border border-zinc-200 bg-white p-3 text-zinc-700 shadow-sm" aria-hidden="true">
            <div className="h-8 w-8 rounded bg-rose-100" />
            <span className="flex-1 truncate">E-book Jualan.pdf</span>
            <span className="flex items-center gap-1 rounded-full bg-sky-100 px-2 py-1 text-xs font-semibold text-sky-800"><Share2 className="h-3 w-3" /> Bagikan</span>
          </div>
        </li>
        <li>
          <p><span className="font-semibold">2.</span> Di "Akses umum", ubah <span className="font-semibold">Dibatasi</span> menjadi <span className="font-semibold">Siapa saja yang memiliki link</span>, peran <span className="font-semibold">Pelihat</span>.</p>
          <div className="mt-2 space-y-2 rounded-xl border border-zinc-200 bg-white p-3 text-zinc-700 shadow-sm" aria-hidden="true">
            <p className="text-xs font-semibold text-zinc-500">Akses umum</p>
            <div className="flex items-center gap-2 opacity-50 line-through"><Lock className="h-4 w-4" /> Dibatasi</div>
            <div className="flex items-center gap-2 rounded-lg bg-emerald-50 p-2 font-semibold text-emerald-800">
              <Globe2 className="h-4 w-4" /> Siapa saja yang memiliki link <span className="ml-auto text-xs font-normal">Pelihat ▾</span>
            </div>
          </div>
        </li>
        <li>
          <p><span className="font-semibold">3.</span> Klik <span className="font-semibold">Salin link</span>, lalu tempel di kolom di atas.</p>
          <div className="mt-2 flex items-center justify-end rounded-xl border border-zinc-200 bg-white p-3 shadow-sm" aria-hidden="true">
            <span className="flex items-center gap-1 rounded-full border border-sky-300 px-3 py-1 text-xs font-semibold text-sky-800"><Link2 className="h-3 w-3" /> Salin link</span>
          </div>
        </li>
      </ol>
      <p className="border-t border-sky-200 px-4 py-3 text-xs leading-5 text-sky-900">
        Link Drive kamu tidak pernah tampil di halaman publik. Pembeli hanya menerima link akses Hellom setelah bayar, dan kamu bisa mengganti link Drive kapan saja.
        Catatan: link "siapa saja yang memiliki link" tetap bisa diteruskan pembeli ke orang lain; batasi jumlah buka di bawah kalau perlu.
      </p>
    </details>
  );
}
