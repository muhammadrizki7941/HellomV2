import { useEffect, useState } from 'react';
import { Plus, Edit2, Trash2, Settings, Gift, Target, Percent, DollarSign, Save } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  getPosLoyaltyRewardRules,
  createPosRewardRule,
  updatePosRewardRule,
  deletePosRewardRule,
  getPosLoyaltySettings,
  updatePosLoyaltySettings,
} from '@/lib/hellomApi';

type RewardRule = {
  id: number;
  name: string;
  trigger_type: 'points_threshold' | 'orders_threshold' | 'spend_threshold';
  trigger_value: number;
  reward_type: 'free_product' | 'discount_percent' | 'discount_fixed' | 'bonus_points';
  reward_value: number;
  reward_product_id?: number;
  is_active: boolean;
  description: string;
  created_at: string;
};

type RewardRuleForm = {
  name: string;
  trigger_type: RewardRule['trigger_type'];
  trigger_value: number;
  reward_type: RewardRule['reward_type'];
  reward_value: number;
  reward_product_id: number | null;
  description: string;
  is_active: boolean;
};

type LoyaltySettingsForm = {
  enabled: boolean;
  points_per_amount: number;
  min_spend_amount: number;
  max_points_per_order: string;
};

export default function PosLoyaltySettings() {
  const [rules, setRules] = useState<RewardRule[]>([]);
  const [loading, setLoading] = useState(true);
  const [settingsSaving, setSettingsSaving] = useState(false);
  const [showAddModal, setShowAddModal] = useState(false);
  const [editingRule, setEditingRule] = useState<RewardRule | null>(null);
  const [settingsForm, setSettingsForm] = useState<LoyaltySettingsForm>({
    enabled: true,
    points_per_amount: 1000,
    min_spend_amount: 0,
    max_points_per_order: '',
  });
  const [formData, setFormData] = useState<RewardRuleForm>({
    name: '',
    trigger_type: 'points_threshold',
    trigger_value: 0,
    reward_type: 'discount_percent',
    reward_value: 0,
    reward_product_id: null,
    description: '',
    is_active: true,
  });

  useEffect(() => {
    void loadRules();
  }, []);

  const loadRules = async () => {
    try {
      setLoading(true);
      const [rulesResponse, settingsResponse] = await Promise.all([
        getPosLoyaltyRewardRules(),
        getPosLoyaltySettings(),
      ]);

      setRules(rulesResponse || []);
      setSettingsForm({
        enabled: settingsResponse.enabled,
        points_per_amount: settingsResponse.points_per_amount,
        min_spend_amount: settingsResponse.min_spend_amount,
        max_points_per_order: settingsResponse.max_points_per_order ? String(settingsResponse.max_points_per_order) : '',
      });
    } catch (err) {
      console.error('Failed to load loyalty data:', err);
      setRules([]);
    } finally {
      setLoading(false);
    }
  };

  const getTriggerLabel = (rule: RewardRule) => {
    switch (rule.trigger_type) {
      case 'points_threshold':
        return `Kumpul ${rule.trigger_value} poin`;
      case 'orders_threshold':
        return `Beli ${rule.trigger_value}x`;
      case 'spend_threshold':
        return `Total belanja > Rp ${rule.trigger_value.toLocaleString('id-ID')}`;
      default:
        return rule.trigger_type;
    }
  };

  const getRewardLabel = (rule: RewardRule) => {
    switch (rule.reward_type) {
      case 'discount_percent':
        return `Diskon ${rule.reward_value}%`;
      case 'discount_fixed':
        return `Diskon Rp ${rule.reward_value.toLocaleString('id-ID')}`;
      case 'free_product':
        return 'Produk gratis';
      case 'bonus_points':
        return `Bonus ${rule.reward_value} poin`;
      default:
        return rule.reward_type;
    }
  };

  const getTriggerIcon = (type: string) => {
    switch (type) {
      case 'points_threshold':
        return <Target className="w-4 h-4" />;
      case 'orders_threshold':
        return <Gift className="w-4 h-4" />;
      case 'spend_threshold':
        return <DollarSign className="w-4 h-4" />;
      default:
        return <Settings className="w-4 h-4" />;
    }
  };

  const getRewardIcon = (type: string) => {
    switch (type) {
      case 'discount_percent':
      case 'discount_fixed':
        return <Percent className="w-4 h-4" />;
      case 'free_product':
        return <Gift className="w-4 h-4" />;
      case 'bonus_points':
        return <Target className="w-4 h-4" />;
      default:
        return <Settings className="w-4 h-4" />;
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      if (editingRule) {
        await updatePosRewardRule(editingRule.id, formData);
      } else {
        await createPosRewardRule(formData);
      }

      await loadRules();
      setShowAddModal(false);
      resetForm();
    } catch (err) {
      console.error('Failed to save rule:', err);
      alert('Gagal menyimpan rule');
    }
  };

  const handleSaveSettings = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSettingsSaving(true);
      await updatePosLoyaltySettings({
        enabled: settingsForm.enabled,
        points_per_amount: settingsForm.points_per_amount,
        min_spend_amount: settingsForm.min_spend_amount,
        max_points_per_order: settingsForm.max_points_per_order ? Number(settingsForm.max_points_per_order) : null,
      });
      alert('Setting poin berhasil disimpan');
      await loadRules();
    } catch (err) {
      console.error('Failed to save loyalty settings:', err);
      alert('Gagal menyimpan setting poin');
    } finally {
      setSettingsSaving(false);
    }
  };

  const handleDelete = async (ruleId: number) => {
    if (confirm('Yakin mau hapus reward rule ini?')) {
      try {
        await deletePosRewardRule(ruleId);
        await loadRules();
      } catch (err) {
        console.error('Failed to delete rule:', err);
        alert('Gagal menghapus rule');
      }
    }
  };

  const resetForm = () => {
    setFormData({
      name: '',
      trigger_type: 'points_threshold',
      trigger_value: 0,
      reward_type: 'discount_percent',
      reward_value: 0,
      reward_product_id: null,
      description: '',
      is_active: true,
    });
    setEditingRule(null);
  };

  const handleEdit = (rule: RewardRule) => {
    setEditingRule(rule);
    setFormData({
      name: rule.name,
      trigger_type: rule.trigger_type,
      trigger_value: rule.trigger_value,
      reward_type: rule.reward_type,
      reward_value: rule.reward_value,
      reward_product_id: rule.reward_product_id || null,
      description: rule.description,
      is_active: rule.is_active,
    });
    setShowAddModal(true);
  };

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-7xl mx-auto space-y-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Program Loyalitas</h1>
            <p className="mt-1 text-gray-600">Kelola aturan reward dan setting poin member POS.</p>
          </div>
          <button
            onClick={() => setShowAddModal(true)}
            className="flex items-center gap-2 rounded-lg bg-amber-400 px-4 py-2 text-[#111111] transition-colors hover:bg-amber-500"
          >
            <Plus className="w-4 h-4" />
            Tambah Reward Rule
          </button>
        </div>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100">
                <Target className="w-5 h-5 text-blue-600" />
              </div>
              <div>
                <p className="text-sm text-gray-500">Total Rules</p>
                <p className="text-xl font-bold text-gray-900">{rules.length}</p>
              </div>
            </div>
          </div>

          <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100">
                <Settings className="w-5 h-5 text-green-600" />
              </div>
              <div>
                <p className="text-sm text-gray-500">Rules Aktif</p>
                <p className="text-xl font-bold text-gray-900">{rules.filter((rule) => rule.is_active).length}</p>
              </div>
            </div>
          </div>

          <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100">
                <Gift className="w-5 h-5 text-purple-600" />
              </div>
              <div>
                <p className="text-sm text-gray-500">Jenis Reward</p>
                <p className="text-xl font-bold text-gray-900">{new Set(rules.map((rule) => rule.reward_type)).size}</p>
              </div>
            </div>
          </div>
        </div>

        <form onSubmit={handleSaveSettings} className="space-y-5 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
          <div className="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <h2 className="text-lg font-semibold text-gray-900">Setting Poin POS</h2>
              <p className="mt-1 text-sm text-gray-600">
                Atur nominal pembentuk poin, minimal transaksi, dan batas poin maksimal per order.
              </p>
            </div>
            <label className="inline-flex items-center gap-3 rounded-full bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900">
              <input
                type="checkbox"
                checked={settingsForm.enabled}
                onChange={(e) => setSettingsForm((current) => ({ ...current, enabled: e.target.checked }))}
                className="h-4 w-4 rounded border-gray-300 text-amber-500 focus:ring-amber-300"
              />
              Loyalty aktif
            </label>
          </div>

          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">1 poin per nominal</label>
              <input
                type="number"
                min="1"
                value={settingsForm.points_per_amount}
                onChange={(e) => setSettingsForm((current) => ({ ...current, points_per_amount: Number(e.target.value) || 0 }))}
                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
              />
              <p className="mt-1 text-xs text-gray-500">Contoh `1000` berarti 1 poin untuk setiap Rp 1.000.</p>
            </div>

            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Minimal belanja</label>
              <input
                type="number"
                min="0"
                value={settingsForm.min_spend_amount}
                onChange={(e) => setSettingsForm((current) => ({ ...current, min_spend_amount: Number(e.target.value) || 0 }))}
                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
              />
              <p className="mt-1 text-xs text-gray-500">Order di bawah nominal ini tidak akan mendapat poin.</p>
            </div>

            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Maksimal poin per order</label>
              <input
                type="number"
                min="1"
                value={settingsForm.max_points_per_order}
                onChange={(e) => setSettingsForm((current) => ({ ...current, max_points_per_order: e.target.value }))}
                placeholder="Kosongkan jika tanpa batas"
                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 placeholder-gray-400 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
              />
              <p className="mt-1 text-xs text-gray-500">Opsional untuk membatasi akumulasi poin dalam satu transaksi.</p>
            </div>
          </div>

          <div className="flex justify-end">
            <button
              type="submit"
              disabled={settingsSaving}
              className="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-gray-800 disabled:opacity-60"
            >
              <Save className="w-4 h-4" />
              {settingsSaving ? 'Menyimpan...' : 'Simpan Setting Poin'}
            </button>
          </div>
        </form>

        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
          {loading ? (
            <div className="p-8 text-center">
              <div className="mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-b-2 border-amber-400"></div>
              <p className="text-gray-600">Loading reward rules...</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Rule</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Trigger</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Reward</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {rules.map((rule) => (
                    <tr key={rule.id} className="hover:bg-gray-50">
                      <td className="px-4 py-4">
                        <div>
                          <div className="text-sm font-medium text-gray-900">{rule.name}</div>
                          <div className="mt-1 text-xs text-gray-500">{rule.description}</div>
                        </div>
                      </td>
                      <td className="px-4 py-4">
                        <div className="flex items-center gap-2">
                          <div className="flex h-6 w-6 items-center justify-center rounded bg-blue-100">
                            {getTriggerIcon(rule.trigger_type)}
                          </div>
                          <span className="text-sm text-gray-900">{getTriggerLabel(rule)}</span>
                        </div>
                      </td>
                      <td className="px-4 py-4">
                        <div className="flex items-center gap-2">
                          <div className="flex h-6 w-6 items-center justify-center rounded bg-green-100">
                            {getRewardIcon(rule.reward_type)}
                          </div>
                          <span className="text-sm text-gray-900">{getRewardLabel(rule)}</span>
                        </div>
                      </td>
                      <td className="px-4 py-4">
                        <span
                          className={cn(
                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                            rule.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                          )}
                        >
                          {rule.is_active ? 'Aktif' : 'Nonaktif'}
                        </span>
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-500">
                        <button onClick={() => handleEdit(rule)} className="mr-3 text-amber-600 hover:text-amber-900">
                          <Edit2 className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(rule.id)} className="text-red-600 hover:text-red-900">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {rules.length === 0 && !loading ? (
          <div className="py-12 text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100">
              <Gift className="w-6 h-6 text-gray-400" />
            </div>
            <p className="text-gray-500">Belum ada reward rule yang dibuat.</p>
            <button
              onClick={() => setShowAddModal(true)}
              className="mt-4 rounded-lg bg-amber-400 px-4 py-2 text-[#111111] transition-colors hover:bg-amber-500"
            >
              Buat Reward Rule Pertama
            </button>
          </div>
        ) : null}

        {showAddModal ? (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-white/20 p-4 backdrop-blur-md">
            <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
              <div className="mb-6 flex items-center justify-between">
                <h3 className="text-lg font-semibold text-gray-900">
                  {editingRule ? 'Edit Reward Rule' : 'Tambah Reward Rule Baru'}
                </h3>
                <button
                  onClick={() => {
                    setShowAddModal(false);
                    resetForm();
                  }}
                  className="text-gray-400 hover:text-gray-600"
                >
                  ×
                </button>
              </div>

              <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                  <label className="mb-1 block text-sm font-medium text-gray-700">Nama Reward *</label>
                  <input
                    type="text"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="misalnya: Diskon 100 Poin"
                    className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 placeholder-gray-400 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
                    required
                  />
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">Jenis Trigger *</label>
                    <select
                      value={formData.trigger_type}
                      onChange={(e) => setFormData({ ...formData, trigger_type: e.target.value as RewardRule['trigger_type'] })}
                      className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
                      required
                    >
                      <option value="points_threshold">Kumpul Poin</option>
                      <option value="orders_threshold">Number of Orders</option>
                      <option value="spend_threshold">Total Belanja</option>
                    </select>
                  </div>

                  <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">Nilai Trigger *</label>
                    <input
                      type="number"
                      value={formData.trigger_value}
                      onChange={(e) => setFormData({ ...formData, trigger_value: Number(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
                      min="0"
                      required
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">Jenis Reward *</label>
                    <select
                      value={formData.reward_type}
                      onChange={(e) => setFormData({ ...formData, reward_type: e.target.value as RewardRule['reward_type'] })}
                      className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
                      required
                    >
                      <option value="discount_percent">Diskon %</option>
                      <option value="discount_fixed">Diskon Nominal</option>
                      <option value="free_product">Produk Gratis</option>
                      <option value="bonus_points">Bonus Poin</option>
                    </select>
                  </div>

                  <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">Nilai Reward *</label>
                    <input
                      type="number"
                      value={formData.reward_value}
                      onChange={(e) => setFormData({ ...formData, reward_value: Number(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
                      min="0"
                      required
                    />
                  </div>
                </div>

                <div>
                  <label className="mb-1 block text-sm font-medium text-gray-700">Deskripsi</label>
                  <textarea
                    value={formData.description}
                    onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                    placeholder="Jelaskan reward ini..."
                    rows={3}
                    className="w-full resize-none rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 placeholder-gray-400 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
                  />
                </div>

                <div className="flex items-center">
                  <input
                    type="checkbox"
                    id="rule_active"
                    checked={formData.is_active}
                    onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                    className="h-4 w-4 rounded border-gray-300 text-amber-500 focus:ring-amber-300"
                  />
                  <label htmlFor="rule_active" className="ml-2 text-sm text-gray-700">
                    Rule aktif
                  </label>
                </div>

                <div className="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    onClick={() => {
                      setShowAddModal(false);
                      resetForm();
                    }}
                    className="rounded-lg bg-gray-100 px-4 py-2 text-gray-700 transition-colors hover:bg-gray-200"
                  >
                    Batal
                  </button>
                  <button type="submit" className="rounded-lg bg-amber-400 px-4 py-2 text-[#111111] transition-colors hover:bg-amber-500">
                    {editingRule ? 'Update Rule' : 'Simpan Rule'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        ) : null}
      </div>
    </div>
  );
}
