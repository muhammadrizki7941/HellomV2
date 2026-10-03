import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { nanoid } from 'nanoid';
import { arrayMove } from '@dnd-kit/sortable';
import {
  ApiError,
  createLandingSitePage,
  getLandingDraft,
  getLandingPreviewLink,
  getLandingSite,
  publishLandingSitePage,
  restoreLandingVersion,
  saveLandingDraft,
  uploadLandingAsset,
} from '@/lib/hellomApi';
import type { LandingDocument, LandingSite, LandingSitePage } from '@/lib/hellomApi';
import { defaultContent } from '../constants';
import { BLOCK_TYPES } from '../types';
import type { Block, BlockStyles, BlockType } from '../types';
import type { PageTemplate } from '../templates';

/**
 * State of the Hellom Page editor: one document (theme, settings, blocks) with undo/redo,
 * autosave (revision-checked against other tabs), publish, history and templates.
 * The loaded document is kept whole, so fields this editor does not know survive a save.
 */
export type SaveState = 'saved' | 'dirty' | 'saving' | 'error' | 'conflict';
export type EditorTheme = LandingDocument['theme'];
export type EditorSettings = { whatsappNumber: string; whatsappMessage: string; showFloatingWhatsapp: boolean };
export interface EditorDoc { theme: EditorTheme; settings: EditorSettings; blocks: Block[] }
export type Notice = { kind: 'ok' | 'error'; text: string; url?: string } | null;

const AUTOSAVE_MS = 1500;
const COALESCE_MS = 800;
const HISTORY_LIMIT = 100;
const PAGE_KEY = 'hellom_landing_editor_page';
const DEFAULT_SETTINGS: EditorSettings = { whatsappNumber: '', whatsappMessage: 'Halo, saya tertarik dengan produk Anda.', showFloatingWhatsapp: false };
const EMPTY: EditorDoc = { theme: { preset: 'industrial' }, settings: DEFAULT_SETTINGS, blocks: [] };

// ── History (pure reducer: React may run it twice in development) ──
interface HistoryState { present: EditorDoc; past: EditorDoc[]; future: EditorDoc[]; lastKey: string | null; lastAt: number; version: number; loaded: number }
type HistoryAction =
  | { type: 'load'; doc: EditorDoc }
  | { type: 'change'; update: (doc: EditorDoc) => EditorDoc; key?: string; at: number }
  | { type: 'undo' }
  | { type: 'redo' };

function historyReducer(state: HistoryState, action: HistoryAction): HistoryState {
  switch (action.type) {
    case 'load':
      return { present: action.doc, past: [], future: [], lastKey: null, lastAt: 0, version: state.version + 1, loaded: state.version + 1 };
    case 'change': {
      const next = action.update(state.present);
      if (next === state.present) return state;
      // Typing in one field is one undo step.
      const merge = !!action.key && action.key === state.lastKey && action.at - state.lastAt < COALESCE_MS;
      return {
        present: next,
        past: merge ? state.past : [...state.past, state.present].slice(-HISTORY_LIMIT),
        future: [],
        lastKey: action.key ?? null,
        lastAt: action.at,
        version: state.version + 1,
        loaded: state.loaded,
      };
    }
    case 'undo': {
      const previous = state.past[state.past.length - 1];
      if (!previous) return state;
      return { ...state, present: previous, past: state.past.slice(0, -1), future: [state.present, ...state.future], lastKey: null, version: state.version + 1 };
    }
    case 'redo': {
      const [next, ...rest] = state.future;
      if (!next) return state;
      return { ...state, present: next, past: [...state.past, state.present], future: rest, lastKey: null, version: state.version + 1 };
    }
  }
}

function toEditorDoc(doc: LandingDocument): EditorDoc {
  return {
    theme: { preset: 'industrial', ...(doc.theme ?? {}) },
    settings: {
      whatsappNumber: doc.settings?.whatsappNumber ?? '',
      whatsappMessage: doc.settings?.whatsappMessage ?? DEFAULT_SETTINGS.whatsappMessage,
      showFloatingWhatsapp: !!doc.settings?.showFloatingWhatsapp,
    },
    blocks: (doc.blocks || [])
      .filter((b) => BLOCK_TYPES.includes(b.type as BlockType))
      .map((b) => ({ id: b.id, type: b.type as BlockType, hidden: !!b.hidden, content: { ...(b.content || {}) } as Record<string, unknown>, styles: (b.styles || {}) as BlockStyles })),
  };
}

export function newBlock(type: BlockType): Block {
  return { id: nanoid(10), type, content: structuredClone(defaultContent[type] ?? {}) };
}

export function useEditorDocument() {
  const [site, setSite] = useState<LandingSite | null>(null);
  const [page, setPage] = useState<LandingSitePage | null>(null);
  const [history, dispatch] = useReducer(historyReducer, { present: EMPTY, past: [], future: [], lastKey: null, lastAt: 0, version: 0, loaded: 0 });
  const doc = history.present;
  const [selectedId, setSelectedId] = useState<string | null>(null);
  // Locked until the draft is loaded: never save defaults over a real page.
  const [loadState, setLoadState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState<string | null>(null);
  const [loadAttempt, setLoadAttempt] = useState(0);
  const [saveState, setSaveState] = useState<SaveState>('saved');
  const [savedAt, setSavedAt] = useState<string | null>(null);
  const [notice, setNotice] = useState<Notice>(null);
  const [publishing, setPublishing] = useState(false);

  const revisionRef = useRef<number | null>(null);
  const baseRef = useRef<LandingDocument | null>(null); // last loaded document (unknown fields kept)
  const savingRef = useRef<Promise<void> | null>(null);
  const timerRef = useRef<number | undefined>(undefined);

  const buildDocument = useCallback((current: EditorDoc): LandingDocument => ({
    ...(baseRef.current ?? { theme: {}, settings: {}, blocks: [] }),
    theme: current.theme,
    settings: current.settings,
    blocks: current.blocks.map((b) => {
      const { styles: _legacy, ...content } = (b.content || {}) as Record<string, unknown>;
      return { id: b.id, type: b.type, hidden: !!b.hidden, content, styles: (b.styles || {}) as Record<string, unknown> };
    }),
  }), []);
  const document = useMemo(() => buildDocument(doc), [buildDocument, doc]);
  // Latest document + its history version, read by the (debounced) save.
  const latestRef = useRef({ document, version: history.version });
  useEffect(() => { latestRef.current = { document, version: history.version }; }, [document, history.version]);

  const applyDocument = useCallback((loaded: LandingDocument) => {
    baseRef.current = loaded;
    const next = toEditorDoc(loaded);
    dispatch({ type: 'load', doc: next });
    setSelectedId(null);
  }, []);

  const openPage = useCallback(async (target: LandingSitePage) => {
    const draft = await getLandingDraft(target.id);
    revisionRef.current = draft.revision;
    setPage(draft.page ?? target);
    setSavedAt(draft.saved_at);
    setSaveState('saved');
    applyDocument(draft.document);
    localStorage.setItem(PAGE_KEY, String(target.id));
  }, [applyDocument]);

  const refreshSite = useCallback(async () => {
    const next = await getLandingSite();
    setSite(next);
    setPage((current) => (current ? next.pages.find((p) => p.id === current.id) ?? current : current));
    return next;
  }, []);

  // Load the shop, then the remembered (or home) page's draft.
  useEffect(() => {
    let alive = true;
    (async () => {
      setLoadState('loading');
      setLoadError(null);
      try {
        let current = await getLandingSite();
        if (current.pages.length === 0) {
          try {
            await createLandingSitePage({ title: 'Halaman Utama' });
          } catch (err) {
            // Another load (second tab, React dev double effect) created it first: use that page.
            if (!(err instanceof ApiError && err.code === 'PAGE_QUOTA')) throw err;
          }
          current = await getLandingSite();
        }
        if (!alive) return;
        setSite(current);
        const remembered = Number(localStorage.getItem(PAGE_KEY));
        const target = current.pages.find((p) => p.id === remembered) ?? current.pages.find((p) => p.is_home) ?? current.pages[0];
        await openPage(target);
        if (alive) setLoadState('ready');
      } catch (err) {
        if (!alive) return;
        setLoadError(err instanceof Error ? err.message : 'Halaman belum bisa dimuat');
        setLoadState('error');
      }
    })();
    return () => { alive = false; };
  }, [loadAttempt, openPage]);

  const save = useCallback(async (): Promise<void> => {
    if (!page || loadState !== 'ready') return;
    if (savingRef.current) await savingRef.current; // one save at a time; the next one sends the newest document
    const sent = latestRef.current;
    setSaveState('saving');
    const run = (async () => {
      try {
        const saved = await saveLandingDraft(page.id, sent.document, revisionRef.current);
        revisionRef.current = saved.revision;
        setSavedAt(saved.saved_at);
        // Edits made while saving are saved by the next autosave.
        setSaveState(latestRef.current.version === sent.version ? 'saved' : 'dirty');
      } catch (err) {
        if (err instanceof ApiError && err.status === 409) {
          setSaveState('conflict');
        } else {
          setSaveState('error');
          setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Draft belum tersimpan' });
        }
        throw err;
      }
    })();
    savingRef.current = run.then(() => undefined, () => undefined).finally(() => { savingRef.current = null; });
    await run;
  }, [page, loadState]);

  // Autosave after edits (not after loading a draft).
  useEffect(() => {
    if (loadState !== 'ready' || history.version === history.loaded || saveState === 'conflict') return undefined;
    setSaveState('dirty');
    window.clearTimeout(timerRef.current);
    timerRef.current = window.setTimeout(() => { void save().catch(() => undefined); }, AUTOSAVE_MS);
    return () => window.clearTimeout(timerRef.current);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [history.version, loadState]);

  // Warn before leaving with unsaved edits.
  useEffect(() => {
    const onLeave = (e: BeforeUnloadEvent) => {
      if (saveState === 'dirty' || saveState === 'saving') {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', onLeave);
    return () => window.removeEventListener('beforeunload', onLeave);
  }, [saveState]);

  const flush = useCallback(async () => {
    window.clearTimeout(timerRef.current);
    if (saveState === 'dirty' || saveState === 'error') await save();
    else if (savingRef.current) await savingRef.current;
  }, [save, saveState]);

  // ── Document changes (each one is an undo step; key = merge consecutive typing) ──
  const change = useCallback((update: (d: EditorDoc) => EditorDoc, key?: string) => dispatch({ type: 'change', update, key, at: Date.now() }), []);
  const setBlocks = (update: (blocks: Block[]) => Block[], key?: string) => change((d) => ({ ...d, blocks: update(d.blocks) }), key);

  const actions = {
    undo: () => dispatch({ type: 'undo' }),
    redo: () => dispatch({ type: 'redo' }),
    setTheme: (patch: Partial<EditorTheme>) => change((d) => ({ ...d, theme: { ...d.theme, ...patch } }), 'theme'),
    setSettings: (patch: Partial<EditorSettings>) => change((d) => ({ ...d, settings: { ...d.settings, ...patch } }), 'settings'),
    addBlock: (type: BlockType, index?: number) => {
      const block = newBlock(type);
      setBlocks((blocks) => {
        const next = [...blocks];
        next.splice(index === undefined ? next.length : Math.max(0, Math.min(index, next.length)), 0, block);
        return next;
      });
      setSelectedId(block.id);
      return block;
    },
    updateContent: (id: string, content: Record<string, unknown>) => setBlocks((blocks) => blocks.map((b) => (b.id === id ? { ...b, content } : b)), `content:${id}`),
    updateStyles: (id: string, styles: BlockStyles) => setBlocks((blocks) => blocks.map((b) => (b.id === id ? { ...b, styles: { ...b.styles, ...styles } } : b)), `styles:${id}`),
    reorder: (from: number, to: number) => setBlocks((blocks) => arrayMove(blocks, from, to)),
    move: (id: string, direction: -1 | 1) => setBlocks((blocks) => {
      const from = blocks.findIndex((b) => b.id === id);
      const to = from + direction;
      return from < 0 || to < 0 || to >= blocks.length ? blocks : arrayMove(blocks, from, to);
    }),
    remove: (id: string) => {
      setBlocks((blocks) => blocks.filter((b) => b.id !== id));
      setSelectedId((current) => (current === id ? null : current));
    },
    duplicate: (id: string) => {
      const copyId = nanoid(10);
      setBlocks((blocks) => {
        const index = blocks.findIndex((b) => b.id === id);
        if (index < 0) return blocks;
        const next = [...blocks];
        next.splice(index + 1, 0, { ...structuredClone(blocks[index]), id: copyId });
        return next;
      });
      setSelectedId(copyId);
    },
    toggleHidden: (id: string) => setBlocks((blocks) => blocks.map((b) => (b.id === id ? { ...b, hidden: !b.hidden } : b))),
    applyTemplate: (template: PageTemplate, mode: 'all' | 'style' = 'all') => {
      change((d) => ({
        ...d,
        theme: { ...d.theme, preset: template.themeId, ...template.options },
        blocks: mode === 'all' ? template.blocks() : d.blocks,
      }));
      setSelectedId(null);
    },
  };

  // Images/PDF go to the server (stored as WebP), never inline in the page.
  const uploadFile = async (file: File, blockId: string, field: string, isStyle = false) => {
    if (file.size > 8 * 1024 * 1024) {
      setNotice({ kind: 'error', text: 'File maksimal 8 MB.' });
      return;
    }
    try {
      const { url } = await uploadLandingAsset(file);
      if (isStyle) {
        setBlocks((blocks) => blocks.map((b) => (b.id === blockId ? { ...b, styles: { ...b.styles, [field]: url } } : b)));
      } else {
        setBlocks((blocks) => blocks.map((b) => (b.id === blockId ? { ...b, content: { ...b.content, [field]: url, ...(field === 'fileUrl' ? { fileName: file.name } : {}) } } : b)));
      }
    } catch (err) {
      setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Upload gagal' });
    }
  };

  const publish = async () => {
    if (!page) return;
    setPublishing(true);
    setNotice(null);
    try {
      await flush();
      const result = await publishLandingSitePage(page.id);
      setPage(result.page);
      await refreshSite();
      setNotice({ kind: 'ok', text: result.page.is_live ? 'Halaman terbit! Bagikan link ini:' : 'Terbit, tapi halaman ini melebihi kuota paket kamu sehingga belum tayang.', url: result.page.url });
    } catch (err) {
      setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Belum bisa diterbitkan' });
    } finally {
      setPublishing(false);
    }
  };

  /** Live page when published, otherwise a signed preview of the draft. */
  const viewUrl = async (): Promise<string | null> => {
    if (!page) return null;
    try {
      await flush();
      if (page.is_live && !page.has_unpublished_changes) return page.url;
      return (await getLandingPreviewLink(page.id)).url;
    } catch (err) {
      setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Pratinjau belum bisa dibuka' });
      return null;
    }
  };

  const switchPage = async (target: LandingSitePage) => {
    try {
      await flush();
      await openPage(target);
    } catch (err) {
      setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Halaman belum bisa dibuka' });
    }
  };

  const restore = async (versionId: number, versionNo: number) => {
    if (!page) return;
    try {
      await flush();
      const draft = await restoreLandingVersion(page.id, versionId);
      revisionRef.current = draft.revision;
      setSavedAt(draft.saved_at);
      applyDocument(draft.document);
      setSaveState('saved');
      setNotice({ kind: 'ok', text: `Versi ${versionNo} dikembalikan ke draft. Tekan Terbitkan untuk menayangkannya.` });
    } catch (err) {
      setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Versi belum bisa dikembalikan' });
    }
  };

  return {
    site, page, doc, document, selectedId, setSelectedId,
    loadState, loadError, reload: () => setLoadAttempt((n) => n + 1),
    saveState, savedAt, notice, setNotice, publishing,
    canUndo: history.past.length > 0, canRedo: history.future.length > 0,
    actions, uploadFile, publish, viewUrl, switchPage, restore, refreshSite,
  };
}

export type EditorApi = ReturnType<typeof useEditorDocument>;
