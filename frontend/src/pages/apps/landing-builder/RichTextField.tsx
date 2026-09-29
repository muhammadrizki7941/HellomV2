import { useEffect, useRef } from 'react';
import { Bold, Italic, List, ListOrdered } from 'lucide-react';
import { safeHtml } from '@/lib/safeHtml';

// Minimal rich text for product descriptions: bold, italic, lists. The HTML is sanitised
// again on the server (SafeHtml) and when rendered (DOMPurify).
export default function RichTextField({ value, onChange, placeholder }: { value: string; onChange: (html: string) => void; placeholder?: string }) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    // Only push outside changes (e.g. loading a product); typing keeps the caret.
    if (ref.current && ref.current.innerHTML !== value) {
      ref.current.innerHTML = safeHtml(value);
    }
  }, [value]);

  const run = (command: string) => {
    ref.current?.focus();
    document.execCommand(command);
    onChange(ref.current?.innerHTML ?? '');
  };

  const buttons: Array<[string, typeof Bold, string]> = [
    ['bold', Bold, 'Tebal'],
    ['italic', Italic, 'Miring'],
    ['insertUnorderedList', List, 'Daftar'],
    ['insertOrderedList', ListOrdered, 'Daftar bernomor'],
  ];

  return (
    <div className="rounded-xl border border-zinc-300 bg-white focus-within:border-zinc-900">
      <div className="flex gap-1 border-b border-zinc-200 p-1">
        {buttons.map(([command, Icon, label]) => (
          <button key={command} type="button" aria-label={label} title={label} onMouseDown={(e) => e.preventDefault()} onClick={() => run(command)} className="flex h-11 w-11 items-center justify-center rounded-lg text-zinc-600 hover:bg-zinc-100">
            <Icon className="h-4 w-4" />
          </button>
        ))}
      </div>
      <div
        ref={ref}
        contentEditable
        role="textbox"
        aria-multiline="true"
        aria-label={placeholder}
        data-placeholder={placeholder}
        onInput={() => onChange(ref.current?.innerHTML ?? '')}
        className="[&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-1 [&_li]:my-0.5 min-h-32 max-w-none px-3 py-2 text-base outline-none empty:before:text-zinc-400 empty:before:content-[attr(data-placeholder)]"
      />
    </div>
  );
}
