import { useMemo } from 'react';
import { AlertTriangle, CheckCircle2, Sparkles, XCircle } from 'lucide-react';

interface SeoAssistantProps {
  title: string;
  slug: string;
  metaTitle: string;
  metaDescription: string;
  metaKeywords: string;
  excerpt: string;
  content: string;
  thumbnail: string;
}

type Status = 'pass' | 'warn' | 'fail';

interface Check {
  status: Status;
  label: string;
  hint?: string;
}

const stripHtml = (html: string) => html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

export default function SeoAssistant(props: SeoAssistantProps) {
  const { checks, score, words } = useMemo(() => {
    const plain = stripHtml(props.content);
    const wordCount = plain ? plain.split(/\s+/).filter(Boolean).length : 0;
    const effectiveMetaTitle = props.metaTitle || props.title;
    const firstKeyword = props.metaKeywords.split(',')[0]?.trim().toLowerCase() || '';
    const titleLower = props.title.toLowerCase();
    const contentLower = plain.toLowerCase();
    const h2count = (props.content.match(/<h2/gi) || []).length;

    const list: Check[] = [];

    // Title
    if (props.title.length === 0) list.push({ status: 'fail', label: 'Judul belum diisi' });
    else if (props.title.length < 30) list.push({ status: 'warn', label: 'Judul agak pendek', hint: 'Idealnya 40–65 karakter.' });
    else if (props.title.length > 70) list.push({ status: 'warn', label: 'Judul agak panjang', hint: 'Idealnya di bawah 65 karakter.' });
    else list.push({ status: 'pass', label: 'Panjang judul ideal' });

    // Slug
    if (!props.slug) list.push({ status: 'warn', label: 'Slug kosong', hint: 'Akan dibuat otomatis dari judul.' });
    else if (!/^[a-z0-9-]+$/.test(props.slug)) list.push({ status: 'warn', label: 'Slug sebaiknya huruf kecil & tanda hubung' });
    else list.push({ status: 'pass', label: 'Slug rapi & ramah URL' });

    // Meta title
    if (!effectiveMetaTitle) list.push({ status: 'fail', label: 'Meta title kosong' });
    else if (effectiveMetaTitle.length > 60) list.push({ status: 'warn', label: 'Meta title > 60 karakter', hint: 'Bisa terpotong di Google.' });
    else list.push({ status: 'pass', label: 'Meta title panjang aman' });

    // Meta description
    const md = props.metaDescription.length;
    if (md === 0) list.push({ status: 'fail', label: 'Meta description kosong', hint: 'Sangat penting untuk klik dari Google.' });
    else if (md < 120) list.push({ status: 'warn', label: 'Meta description agak pendek', hint: 'Idealnya 120–160 karakter.' });
    else if (md > 160) list.push({ status: 'warn', label: 'Meta description > 160 karakter', hint: 'Bisa terpotong.' });
    else list.push({ status: 'pass', label: 'Meta description ideal' });

    // Keyword in title
    if (!firstKeyword) list.push({ status: 'warn', label: 'Belum ada kata kunci target', hint: 'Isi Meta Keywords.' });
    else if (titleLower.includes(firstKeyword)) list.push({ status: 'pass', label: 'Kata kunci ada di judul' });
    else list.push({ status: 'warn', label: 'Kata kunci utama belum ada di judul' });

    // Keyword in content
    if (firstKeyword && contentLower.includes(firstKeyword)) list.push({ status: 'pass', label: 'Kata kunci muncul di konten' });
    else if (firstKeyword) list.push({ status: 'warn', label: 'Kata kunci belum muncul di konten' });

    // Content length
    if (wordCount === 0) list.push({ status: 'fail', label: 'Konten kosong' });
    else if (wordCount < 300) list.push({ status: 'warn', label: `Konten pendek (${wordCount} kata)`, hint: 'Idealnya 300+ kata.' });
    else list.push({ status: 'pass', label: `Panjang konten bagus (${wordCount} kata)` });

    // Headings
    if (wordCount > 250 && h2count === 0) list.push({ status: 'warn', label: 'Belum ada sub-judul (H2)', hint: 'Pecah konten dengan sub-judul.' });
    else if (h2count > 0) list.push({ status: 'pass', label: 'Punya sub-judul (struktur baik)' });

    // Excerpt
    if (!props.excerpt) list.push({ status: 'warn', label: 'Excerpt/ringkasan kosong' });
    else list.push({ status: 'pass', label: 'Ringkasan terisi' });

    // Image
    if (!props.thumbnail) list.push({ status: 'warn', label: 'Thumbnail belum diatur', hint: 'Penting untuk share & kartu artikel.' });
    else list.push({ status: 'pass', label: 'Thumbnail terpasang' });

    const passed = list.filter((c) => c.status === 'pass').length;
    const computedScore = list.length ? Math.round((passed / list.length) * 100) : 0;

    return { checks: list, score: computedScore, words: wordCount };
  }, [props]);

  const scoreColor = score >= 80 ? 'text-emerald-600' : score >= 50 ? 'text-amber-600' : 'text-rose-600';
  const barColor = score >= 80 ? 'bg-emerald-500' : score >= 50 ? 'bg-amber-500' : 'bg-rose-500';

  const icon = (status: Status) =>
    status === 'pass' ? (
      <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />
    ) : status === 'warn' ? (
      <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
    ) : (
      <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-rose-500" />
    );

  return (
    <div className="rounded-xl border border-zinc-200 bg-white p-4">
      <div className="flex items-center justify-between">
        <p className="flex items-center gap-2 text-sm font-bold text-zinc-800">
          <Sparkles className="h-4 w-4 text-yellow-500" /> Skor SEO
        </p>
        <span className={`text-xl font-black ${scoreColor}`}>{score}</span>
      </div>
      <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-100">
        <div className={`h-full rounded-full transition-all ${barColor}`} style={{ width: `${score}%` }} />
      </div>
      <p className="mt-1 text-xs text-zinc-500">~{Math.max(1, Math.round(words / 200))} menit baca · {words} kata</p>

      <ul className="mt-4 space-y-2">
        {checks.map((check, index) => (
          <li key={index} className="flex items-start gap-2 text-sm">
            {icon(check.status)}
            <span className="text-zinc-700">
              {check.label}
              {check.hint ? <span className="block text-xs text-zinc-400">{check.hint}</span> : null}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
