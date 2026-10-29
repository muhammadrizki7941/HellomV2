import './hellom-mascot.css';

export type MascotMood = 'trolley' | 'welcome' | 'loading' | 'maintenance' | 'error' | 'success';

/** Static WebP artwork, animated only with CSS transforms; no GIF/video player. */
export default function HellomMascot({ mood = 'trolley', className = '' }: { mood?: MascotMood; className?: string }) {
  return (
    <span className={`hellom-mascot hellom-mascot--${mood} ${className}`} aria-hidden="true">
      {mood === 'trolley' ? <img src="/images/mascot/trolley.webp" width={384} height={384} alt="" draggable={false} /> : <span className="hellom-mascot__pose" />}
    </span>
  );
}
