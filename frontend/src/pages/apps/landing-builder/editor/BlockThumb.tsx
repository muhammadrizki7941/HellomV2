import type { ReactNode } from 'react';

/**
 * Small drawings of each block for the "+ Tambah" gallery (Hellom's own, no outside assets).
 * Neutral greys with the Hellom yellow as accent; decorative (the card has a text label).
 */
const INK = '#3f3f46';
const SOFT = '#d4d4d8';
const FAINT = '#f4f4f5';
const ACCENT = '#facc15';

const line = (x: number, y: number, w: number, color = SOFT, h = 4) => <rect x={x} y={y} width={w} height={h} rx={h / 2} fill={color} />;
const pill = (x: number, y: number, w: number, h = 12, fill = INK) => <rect x={x} y={y} width={w} height={h} rx={h / 2} fill={fill} />;
const photo = (x: number, y: number, w: number, h: number, r = 6) => (
  <g>
    <rect x={x} y={y} width={w} height={h} rx={r} fill={SOFT} />
    <circle cx={x + w * 0.28} cy={y + h * 0.35} r={Math.min(w, h) * 0.1} fill="#fff" />
    <path d={`M${x + 4} ${y + h - 4} L${x + w * 0.42} ${y + h * 0.52} L${x + w * 0.62} ${y + h * 0.72} L${x + w * 0.75} ${y + h * 0.6} L${x + w - 4} ${y + h - 4} Z`} fill="#a1a1aa" />
  </g>
);

/** Keyed by gallery card (editor/blockMeta GALLERY_ITEMS key). */
const DRAWINGS: Record<string, ReactNode> = {
  profile: (
    <g>
      <circle cx="60" cy="26" r="13" fill={SOFT} />
      <circle cx="60" cy="22" r="5" fill="#a1a1aa" /><path d="M51 34a9 7 0 0 1 18 0" fill="#a1a1aa" />
      {line(40, 46, 40, INK, 5)}{line(32, 57, 56)}{line(44, 65, 32)}
    </g>
  ),
  button: <g>{pill(18, 18, 84, 14)}{pill(18, 38, 84, 14, ACCENT)}{pill(18, 58, 84, 14)}</g>,
  social: (
    <g>
      {[30, 50, 70, 90].map((cx, i) => <circle key={cx} cx={cx} cy="40" r="8" fill={i === 1 ? ACCENT : INK} />)}
    </g>
  ),
  text: <g>{line(16, 20, 70, INK, 6)}{line(16, 34, 88)}{line(16, 43, 82)}{line(16, 52, 88)}{line(16, 61, 54)}</g>,
  divider: <g>{line(16, 26, 60)}<rect x="16" y="39" width="88" height="2" fill={INK} />{line(16, 50, 74)}</g>,
  product: (
    <g>
      {photo(22, 10, 76, 36)}{line(22, 52, 50, INK, 5)}{line(22, 61, 28, '#a16207', 5)}{pill(68, 58, 30, 12, ACCENT)}
    </g>
  ),
  catalog: (
    <g>
      {[[14, 8], [62, 8], [14, 44], [62, 44]].map(([x, y]) => <g key={`${x}${y}`}>{photo(x, y, 44, 24, 4)}{line(x, y + 28, 30, INK, 3)}</g>)}
    </g>
  ),
  pdf: (
    <g>
      <path d="M40 10h28l12 12v40H40z" fill={FAINT} stroke={INK} strokeWidth="2" />
      <path d="M68 10v12h12" fill="none" stroke={INK} strokeWidth="2" />
      {line(46, 32, 26)}{line(46, 40, 22)}<rect x="44" y="48" width="18" height="8" rx="2" fill="#dc2626" />
      {pill(36, 66, 48, 10, ACCENT)}
    </g>
  ),
  form: (
    <g>
      {[12, 30, 48].map((y) => <rect key={y} x="18" y={y} width="84" height="12" rx="4" fill="#fff" stroke={SOFT} strokeWidth="2" />)}
      {pill(18, 64, 84, 12, INK)}
    </g>
  ),
  countdown: (
    <g>
      {[18, 46, 74].map((x) => <g key={x}><rect x={x} y="20" width="26" height="30" rx="6" fill={INK} />{line(x + 6, 33, 14, '#fff', 5)}</g>)}
      {line(30, 60, 60)}
    </g>
  ),
  testimonials: (
    <g>
      <rect x="14" y="12" width="92" height="46" rx="10" fill={FAINT} stroke={SOFT} strokeWidth="2" />
      <text x="22" y="34" fontSize="22" fill={ACCENT} fontWeight="700">“</text>
      {line(38, 24, 58)}{line(38, 33, 50)}
      {[0, 1, 2, 3, 4].map((i) => <path key={i} transform={`translate(${38 + i * 11} 42)`} d="M4 0l1.2 2.6 2.8.3-2.1 1.9.6 2.8L4 6.2 1.5 7.6l.6-2.8L0 2.9l2.8-.3z" fill={ACCENT} />)}
      <circle cx="26" cy="68" r="6" fill={SOFT} />{line(36, 66, 30, INK, 4)}
    </g>
  ),
  image: photo(22, 10, 76, 60, 8),
  banner: (
    <g>
      <rect x="8" y="14" width="104" height="52" rx="8" fill="#52525b" />
      {line(28, 32, 64, '#fff', 6)}{line(36, 44, 48, '#d4d4d8')}
    </g>
  ),
  slider: (
    <g>
      <rect x="6" y="16" width="14" height="44" rx="4" fill={SOFT} />{photo(24, 10, 72, 54, 6)}<rect x="100" y="16" width="14" height="44" rx="4" fill={SOFT} />
      {[52, 60, 68].map((cx, i) => <circle key={cx} cx={cx} cy="72" r="2.5" fill={i === 0 ? INK : SOFT} />)}
    </g>
  ),
  gallery: <g>{[[14, 10], [44, 10], [74, 10], [14, 42], [44, 42], [74, 42]].map(([x, y]) => <g key={`${x}${y}`}>{photo(x, y, 28, 28, 4)}</g>)}</g>,
  video: (
    <g>
      <rect x="14" y="12" width="92" height="52" rx="8" fill={INK} />
      <rect x="49" y="28" width="22" height="20" rx="5" fill="#dc2626" /><path d="M57 33l8 5-8 5z" fill="#fff" />
    </g>
  ),
  product_physical: (
    <g>
      <path d="M40 30l20-10 20 10v26l-20 10-20-10z" fill="#d6a76c" stroke="#92673a" strokeWidth="2" strokeLinejoin="round" />
      <path d="M40 30l20 10 20-10M60 40v26" fill="none" stroke="#92673a" strokeWidth="2" strokeLinejoin="round" />
      <path d="M50 25l20 10" stroke="#f5deb3" strokeWidth="3" />
      {pill(30, 68, 60, 9, ACCENT)}
    </g>
  ),
  spacer: (
    <g>
      {line(16, 14, 88, INK, 6)}
      <path d="M60 26v28M54 32l6-6 6 6M54 48l6 6 6-6" fill="none" stroke="#a1a1aa" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
      {line(16, 62, 88, INK, 6)}
    </g>
  ),
  whatsapp: (
    <g>
      <rect x="18" y="28" width="84" height="22" rx="11" fill="#25d366" />
      <circle cx="34" cy="39" r="6" fill="none" stroke="#fff" strokeWidth="2" />
      {line(46, 37, 44, '#fff')}
    </g>
  ),
  embed: (
    <g>
      <rect x="14" y="14" width="92" height="52" rx="8" fill={INK} />
      <circle cx="34" cy="40" r="11" fill="#1db954" />
      <path d="M28 37c5-2 10-1 13 1M29 41c4-1 8-1 11 1M30 45c3-1 6-1 8 0" stroke={INK} strokeWidth="1.6" fill="none" strokeLinecap="round" />
      {line(52, 32, 44, '#fff')}{line(52, 42, 32, '#a1a1aa')}{line(52, 52, 38, '#52525b')}
    </g>
  ),
  faq: (
    <g>
      {[12, 34, 56].map((y, i) => (
        <g key={y}>
          <rect x="14" y={y} width="92" height="16" rx="5" fill={i === 0 ? FAINT : '#fff'} stroke={SOFT} strokeWidth="2" />
          {line(22, y + 6, 50, INK)}<path d={`M94 ${y + 6}l4 4 4-4`} fill="none" stroke={INK} strokeWidth="2" />
        </g>
      ))}
    </g>
  ),
};

export default function BlockThumb({ kind }: { kind: string }) {
  return (
    <svg viewBox="0 0 120 80" className="h-full w-full" aria-hidden="true" focusable="false">
      <rect width="120" height="80" fill="#fafafa" />
      {DRAWINGS[kind] ?? line(30, 38, 60)}
    </svg>
  );
}
