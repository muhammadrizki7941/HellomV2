import { useEffect, useMemo, useState } from 'react';
import { BookOpen, Grid, Link as LinkIcon, User, Users, X } from 'lucide-react';
import { dismissOnboarding, getOnboardingTips } from '@/lib/hellomApi';

const iconMap = {
  user: User,
  grid: Grid,
  book: BookOpen,
  link: LinkIcon,
  users: Users,
} as const;

type Tip = {
  id: number;
  title: string;
  body: string;
  icon?: keyof typeof iconMap | null;
  action_url?: string | null;
  action_text?: string | null;
};

export default function OnboardingTips() {
  const [tips, setTips] = useState<Tip[]>([]);
  const [dismissed, setDismissed] = useState(false);
  const [loading, setLoading] = useState(true);

  const loadTips = async () => {
    setLoading(true);
    try {
      const payload = await getOnboardingTips();
      setDismissed(Boolean(payload.dismissed));
      setTips((payload.tips || []) as Tip[]);
    } catch {
      setTips([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadTips();
  }, []);

  const activeTips = useMemo(() => tips.filter((tip) => tip.title && tip.body), [tips]);

  if (loading || dismissed || activeTips.length === 0) {
    return null;
  }

  const handleDismiss = async () => {
    try {
      await dismissOnboarding();
    } finally {
      setDismissed(true);
    }
  };

  return (
    <section className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 className="text-lg font-bold text-zinc-900">Selamat datang di Hellom!</h2>
          <p className="text-sm text-zinc-600 mt-1">Ikuti langkah berikut untuk memulai</p>
        </div>
        <button
          onClick={() => void handleDismiss()}
          className="text-xs text-zinc-500 hover:text-zinc-700 inline-flex items-center gap-1"
        >
          <X className="w-4 h-4" /> Tutup
        </button>
      </div>

      <div className="mt-5 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        {activeTips.map((tip) => {
          const Icon = tip.icon ? iconMap[tip.icon] : Grid;
          return (
            <div key={tip.id} className="border border-zinc-200 rounded-xl p-4 bg-zinc-50">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 rounded-lg bg-white border border-zinc-200 flex items-center justify-center text-zinc-600">
                  <Icon className="w-4 h-4" />
                </div>
                <h3 className="font-semibold text-zinc-900 text-sm">{tip.title}</h3>
              </div>
              <p className="text-sm text-zinc-600 mt-3 leading-relaxed">{tip.body}</p>
              {tip.action_url && (
                <a
                  href={tip.action_url}
                  className="mt-4 inline-flex items-center text-sm font-semibold text-zinc-900 hover:text-zinc-700"
                >
                  {tip.action_text || 'Buka'}
                </a>
              )}
            </div>
          );
        })}
      </div>

      <button
        onClick={() => void handleDismiss()}
        className="mt-5 text-xs text-zinc-500 hover:text-zinc-700"
      >
        Sembunyikan panduan ini
      </button>
    </section>
  );
}
