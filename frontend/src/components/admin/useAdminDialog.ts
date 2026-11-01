import { useEffect, useRef } from 'react';

/** Keep keyboard focus and page scrolling inside an open admin dialog. */
export function useAdminDialog(open: boolean, onClose: () => void) {
  const ref = useRef<HTMLDivElement>(null);
  const closeRef = useRef(onClose);
  useEffect(() => { closeRef.current = onClose; }, [onClose]);
  useEffect(() => {
    if (!open) return;
    const dialog = ref.current;
    if (!dialog) return;
    const previousFocus = document.activeElement as HTMLElement | null;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const focusable = () => Array.from(dialog.querySelectorAll<HTMLElement>('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]'))
      .filter(element => element.getClientRects().length > 0);
    (dialog.querySelector<HTMLElement>('[aria-label="Tutup"]') ?? focusable()[0] ?? dialog).focus();
    const keydown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeRef.current(); }
      if (event.key !== 'Tab') return;
      const items = focusable(), first = items[0], last = items[items.length - 1];
      if (!first) { event.preventDefault(); dialog.focus(); return; }
      if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    };
    const focusin = (event: FocusEvent) => {
      if (event.target instanceof Node && !dialog.contains(event.target)) (focusable()[0] ?? dialog).focus();
    };
    document.addEventListener('keydown', keydown);
    document.addEventListener('focusin', focusin);
    return () => {
      document.body.style.overflow = overflow;
      document.removeEventListener('keydown', keydown);
      document.removeEventListener('focusin', focusin);
      if (previousFocus?.isConnected) previousFocus.focus();
    };
  }, [open]);
  return ref;
}
