import { useEffect, useState } from 'react';
import { Store, Plus, Pencil, Trash2, X, Check, AlertTriangle, Crown } from 'lucide-react';
import {
  getPosOutlets,
  createPosOutlet,
  updatePosOutlet,
  deletePosOutlet,
  getActiveOutletId,
  setActiveOutletId,
  type PosOutlet,
} from '@/lib/hellomApi';

type FormState = { name: string; address: string; phone: string; email: string; description: string };
const emptyForm: FormState = { name: '', address: '', phone: '', email: '', description: '' };

export default function PosOutlets() {
  const [outlets, setOutlets] = useState<PosOutlet[]>([]);
  const [meta, setMeta] = useState({ used: 0, max_outlets: 1, can_add: false });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<PosOutlet | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const activeId = getActiveOutletId();

  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await getPosOutlets();
      setOutlets(res.outlets || []);
      setMeta(res.meta || { used: 0, max_outlets: 1, can_add: false });
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Gagal memuat outlet');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, []);

  const openCreate = () => { setEditing(null); setForm(emptyForm); setError(null); setModalOpen(true); };
  const openEdit = (o: PosOutlet) => {
    setEditing(o);
    setForm({ name: o.name || '', address: o.address || '', phone: o.phone || '', email: o.email || '', description: o.description || '' });
    setError(null);
    setModalOpen(true);
  };

  const submit = async () => {
    if (!form.name.trim()) { setError('Nama outlet wajib diisi.'); return; }
    setSaving(true);
    setError(null);
    try {
      if (editing) {
        await updatePosOutlet(editing.id, form);
      } else {
        await createPosOutlet(form);
      }
      setModalOpen(false);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Gagal menyimpan outlet');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (o: PosOutlet) => {
    if (!window.confirm(`Hapus outlet "${o.name}"? Data outlet ini tidak akan tampil lagi.`)) return;
    try {
      await deletePosOutlet(o.id);
      await load();
    } catch (e) {
      window.alert(e instanceof Error ? e.message : 'Gagal menghapus outlet');
    }
  };

  const switchTo = (o: PosOutlet) => {
    if (String(o.id) === activeId) return;
    setActiveOutletId(o.id);
    window.location.reload();
  };

  return (
    <div className="mx-auto max-w-4xl">
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold text-[#111111]">
            <Store className="h-6 w-6 text-amber-500" /> Outlet
          </h1>
          <p className="mt-1 text-sm text-[#6b7280]">Kelola cabang/outlet bisnis Anda. Setiap outlet punya produk, order, dan laporannya sendiri.</p>
        </div>
        <div className="text-right">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">
            {meta.used} / {meta.max_outlets} outlet
          </span>
        </div>
      </div>

      {!meta.can_add && (
        <div className="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
          <div>
            Batas outlet paket Anda sudah tercapai ({meta.max_outlets}). Upgrade paket POS untuk menambah outlet lagi.
          </div>
        </div>
      )}

      <div className="mb-4">
        <button
          type="button"
          onClick={openCreate}
          disabled={!meta.can_add}
          className="inline-flex items-center gap-2 rounded-xl bg-amber-400 px-4 py-2.5 text-sm font-bold text-[#111111] transition hover:bg-amber-500 disabled:cursor-not-allowed disabled:opacity-50"
        >
          <Plus className="h-4 w-4" /> Tambah Outlet
        </button>
      </div>

      {error && !modalOpen && (
        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
      )}

      {loading ? (
        <div className="rounded-xl border border-[#eadfbe] bg-white p-8 text-center text-[#8a7d63]">Memuat…</div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {outlets.map((o) => {
            const isActive = String(o.id) === activeId;
            return (
              <div key={o.id} className="rounded-xl border border-[#eadfbe] bg-white p-4 shadow-sm">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <h3 className="flex items-center gap-1.5 truncate font-semibold text-[#111111]">
                      {o.name}
                      {o.is_primary && <Crown className="h-3.5 w-3.5 text-amber-500" />}
                    </h3>
                    {o.address && <p className="mt-1 text-xs text-[#6b7280]">{o.address}</p>}
                    {o.phone && <p className="text-xs text-[#6b7280]">{o.phone}</p>}
                  </div>
                  {isActive ? (
                    <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-semibold text-green-700">
                      <Check className="h-3 w-3" /> Aktif
                    </span>
                  ) : (
                    <button
                      type="button"
                      onClick={() => switchTo(o)}
                      className="shrink-0 rounded-lg border border-[#eadfbe] px-2.5 py-1 text-xs font-medium text-[#4b5563] hover:bg-[#fff7db]"
                    >
                      Pakai
                    </button>
                  )}
                </div>
                <div className="mt-4 flex items-center gap-2 border-t border-[#f1e7c9] pt-3">
                  <button
                    type="button"
                    onClick={() => openEdit(o)}
                    className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-[#4b5563] hover:bg-[#fff7db]"
                  >
                    <Pencil className="h-3.5 w-3.5" /> Edit
                  </button>
                  {!o.is_primary && (
                    <button
                      type="button"
                      onClick={() => remove(o)}
                      className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50"
                    >
                      <Trash2 className="h-3.5 w-3.5" /> Hapus
                    </button>
                  )}
                  {!o.is_active && <span className="ml-auto text-[11px] text-[#8a7d63]">Nonaktif</span>}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {modalOpen && (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4">
          <div className="w-full max-w-md rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl">
            <div className="mb-4 flex items-center justify-between">
              <h3 className="text-lg font-semibold text-[#111111]">{editing ? 'Edit Outlet' : 'Tambah Outlet'}</h3>
              <button type="button" onClick={() => setModalOpen(false)} className="rounded-lg p-1 text-[#8a7d63] hover:bg-[#fff7db]">
                <X className="h-5 w-5" />
              </button>
            </div>

            {error && <div className="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <div className="space-y-3">
              {([
                { key: 'name', label: 'Nama Outlet *', type: 'text', ph: 'Cabang Bandung' },
                { key: 'address', label: 'Alamat', type: 'textarea', ph: 'Jl. ...' },
                { key: 'phone', label: 'Telepon / WhatsApp', type: 'text', ph: '628...' },
                { key: 'email', label: 'Email', type: 'text', ph: 'cabang@email.com' },
              ] as const).map((f) => (
                <div key={f.key}>
                  <label className="mb-1 block text-xs font-semibold text-[#4b5563]">{f.label}</label>
                  {f.type === 'textarea' ? (
                    <textarea
                      value={form[f.key]}
                      onChange={(e) => setForm({ ...form, [f.key]: e.target.value })}
                      rows={2}
                      placeholder={f.ph}
                      className="w-full rounded-lg border border-[#eadfbe] bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-100"
                    />
                  ) : (
                    <input
                      type="text"
                      value={form[f.key]}
                      onChange={(e) => setForm({ ...form, [f.key]: e.target.value })}
                      placeholder={f.ph}
                      className="w-full rounded-lg border border-[#eadfbe] bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-100"
                    />
                  )}
                </div>
              ))}
            </div>

            <div className="mt-5 flex gap-2">
              <button type="button" onClick={() => setModalOpen(false)} className="flex-1 rounded-xl border border-[#eadfbe] py-2.5 text-sm font-semibold text-[#4b5563] hover:bg-[#fff7db]">
                Batal
              </button>
              <button type="button" onClick={() => void submit()} disabled={saving} className="flex-1 rounded-xl bg-amber-400 py-2.5 text-sm font-bold text-[#111111] hover:bg-amber-500 disabled:opacity-60">
                {saving ? 'Menyimpan…' : 'Simpan'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
