import { useState } from 'react';

/** Founder portrait, AVIF → WebP → PNG, with explicit size so it never shifts layout. */
export default function HeroPortrait({ className = '' }: { className?: string }) {
  const [failed, setFailed] = useState(false);

  if (failed) {
    return (
      <div className={`${className} flex items-center justify-center bg-[radial-gradient(circle_at_45%_20%,rgba(246,180,0,.24),transparent_34%),linear-gradient(135deg,#17130a,#050505_68%)]`}>
        <p className="font-display text-3xl text-[#F5F5F2]">Muhammad Rizki</p>
      </div>
    );
  }

  return (
    <picture>
      <source srcSet="/assets/profile/hero-clean.avif" type="image/avif" />
      <source srcSet="/assets/profile/hero-clean.webp" type="image/webp" />
      <img
        src="/assets/profile/hero-clean.png"
        alt="Muhammad Rizki, pendiri Hellom"
        width={592}
        height={478}
        loading="lazy"
        decoding="async"
        onError={() => setFailed(true)}
        className={className}
      />
    </picture>
  );
}

export function WorkspaceImage({ className = '' }: { className?: string }) {
  return (
    <picture>
      <source srcSet="/assets/profile/workspace.avif" type="image/avif" />
      <source srcSet="/assets/profile/workspace.webp" type="image/webp" />
      <img
        src="/assets/profile/workspace.png"
        alt="Ruang kerja Hellom"
        width={430}
        height={250}
        loading="lazy"
        decoding="async"
        className={className}
      />
    </picture>
  );
}
