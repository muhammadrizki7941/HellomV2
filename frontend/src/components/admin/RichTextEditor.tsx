import { type ReactNode, useEffect, useRef, useState } from 'react';
import {
  Bold,
  Heading2,
  Heading3,
  Image as ImageIcon,
  Italic,
  Link as LinkIcon,
  List,
  ListOrdered,
  Pilcrow,
  Quote,
  Underline,
} from 'lucide-react';

interface RichTextEditorProps {
  value: string;
  onChange: (html: string) => void;
  /** Returns the public URL of the uploaded image. */
  onUploadImage?: (file: File) => Promise<string>;
  placeholder?: string;
}

const exec = (command: string, value?: string) => {
  document.execCommand(command, false, value);
};

export default function RichTextEditor({ value, onChange, onUploadImage, placeholder }: RichTextEditorProps) {
  const editorRef = useRef<HTMLDivElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const lastEmitted = useRef<string>('');
  const [uploading, setUploading] = useState(false);

  // Sync external value (e.g. AI fill) without disturbing the caret while typing.
  useEffect(() => {
    const el = editorRef.current;
    if (!el) return;
    if (value !== lastEmitted.current && value !== el.innerHTML) {
      el.innerHTML = value || '';
    }
  }, [value]);

  const emit = () => {
    const html = editorRef.current?.innerHTML || '';
    lastEmitted.current = html;
    onChange(html);
  };

  const run = (command: string, arg?: string) => {
    editorRef.current?.focus();
    exec(command, arg);
    emit();
  };

  const addLink = () => {
    const url = window.prompt('Masukkan URL link (https://...)');
    if (url) run('createLink', url);
  };

  const pickImage = () => fileRef.current?.click();

  const handleImageFile = async (file: File) => {
    if (!onUploadImage) return;
    setUploading(true);
    try {
      const url = await onUploadImage(file);
      editorRef.current?.focus();
      exec('insertImage', url);
      emit();
    } catch {
      window.alert('Gagal mengunggah gambar.');
    } finally {
      setUploading(false);
    }
  };

  const tools: Array<{ icon: ReactNode; label: string; action: () => void }> = [
    { icon: <Bold className="h-4 w-4" />, label: 'Tebal', action: () => run('bold') },
    { icon: <Italic className="h-4 w-4" />, label: 'Miring', action: () => run('italic') },
    { icon: <Underline className="h-4 w-4" />, label: 'Garis bawah', action: () => run('underline') },
    { icon: <Heading2 className="h-4 w-4" />, label: 'Judul 2', action: () => run('formatBlock', 'h2') },
    { icon: <Heading3 className="h-4 w-4" />, label: 'Judul 3', action: () => run('formatBlock', 'h3') },
    { icon: <Pilcrow className="h-4 w-4" />, label: 'Paragraf', action: () => run('formatBlock', 'p') },
    { icon: <List className="h-4 w-4" />, label: 'List', action: () => run('insertUnorderedList') },
    { icon: <ListOrdered className="h-4 w-4" />, label: 'List bernomor', action: () => run('insertOrderedList') },
    { icon: <Quote className="h-4 w-4" />, label: 'Kutipan', action: () => run('formatBlock', 'blockquote') },
    { icon: <LinkIcon className="h-4 w-4" />, label: 'Link', action: addLink },
  ];

  return (
    <div className="overflow-hidden rounded-xl border border-zinc-300 bg-white">
      <div className="flex flex-wrap items-center gap-1 border-b border-zinc-200 bg-zinc-50 p-2">
        {tools.map((tool) => (
          <button
            key={tool.label}
            type="button"
            title={tool.label}
            onClick={tool.action}
            className="rounded-md p-2 text-zinc-600 transition hover:bg-zinc-200 hover:text-zinc-900"
          >
            {tool.icon}
          </button>
        ))}
        {onUploadImage ? (
          <button
            type="button"
            title="Sisipkan gambar"
            onClick={pickImage}
            disabled={uploading}
            className="rounded-md p-2 text-zinc-600 transition hover:bg-zinc-200 hover:text-zinc-900 disabled:opacity-50"
          >
            <ImageIcon className="h-4 w-4" />
          </button>
        ) : null}
        {uploading ? <span className="ml-1 text-xs text-zinc-500">Mengunggah...</span> : null}
      </div>

      <div
        ref={editorRef}
        contentEditable
        suppressContentEditableWarning
        onInput={emit}
        onBlur={emit}
        data-placeholder={placeholder || 'Tulis isi artikel di sini...'}
        className="article-content min-h-[280px] max-w-none px-4 py-3 text-[15px] leading-relaxed text-zinc-800 outline-none [&_a]:text-yellow-600 [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-yellow-300 [&_blockquote]:pl-4 [&_blockquote]:italic [&_blockquote]:text-zinc-600 [&_h2]:mt-4 [&_h2]:text-2xl [&_h2]:font-bold [&_h3]:mt-3 [&_h3]:text-xl [&_h3]:font-semibold [&_img]:my-3 [&_img]:max-w-full [&_img]:rounded-lg [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:mt-2 [&_ul]:list-disc [&_ul]:pl-6 empty:before:text-zinc-400 empty:before:content-[attr(data-placeholder)]"
      />

      <input
        ref={fileRef}
        type="file"
        accept="image/*"
        className="hidden"
        onChange={(event) => {
          const file = event.target.files?.[0];
          if (file) void handleImageFile(file);
          event.target.value = '';
        }}
      />
    </div>
  );
}
