import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { getEditorPreference, updateEditorPreference } from '@/lib/hellomApi';
import type { BuilderPreference } from '@/lib/hellomApi';
import { presetFor } from './presets';
import type { EditorPreset } from './presets';
import BuilderPreferenceDialog from './BuilderPreferenceDialog';

/**
 * Editor preference for the whole Hellom Page builder: asks "Sebelumnya terbiasa pakai apa?"
 * once (saved on the user), gives every tab the matching preset and remembers the tour.
 */
interface EditorPreferenceValue {
  /** false until the server answered (tabs that auto-open dialogs wait for it). */
  loaded: boolean;
  preference: BuilderPreference | null;
  preset: EditorPreset;
  tourDone: boolean;
  /** Bumped when the seller asks to see the tour again. */
  tourRun: number;
  choose: (preference: BuilderPreference) => void;
  finishTour: () => void;
  replayTour: () => void;
  askAgain: () => void;
}

const EditorPreferenceContext = createContext<EditorPreferenceValue | null>(null);

export function useEditorPreference(): EditorPreferenceValue {
  const value = useContext(EditorPreferenceContext);
  if (!value) throw new Error('useEditorPreference must be used inside <EditorPreferenceProvider>');
  return value;
}

/** Same, but safe outside the provider (components also used elsewhere fall back to defaults). */
export function useOptionalEditorPreference(): EditorPreferenceValue | null {
  return useContext(EditorPreferenceContext);
}

export function EditorPreferenceProvider({ children }: { children: ReactNode }) {
  const [loaded, setLoaded] = useState(false);
  const [preference, setPreference] = useState<BuilderPreference | null>(null);
  const [tourDone, setTourDone] = useState(true);
  const [tourRun, setTourRun] = useState(0);
  const [asking, setAsking] = useState(false);

  useEffect(() => {
    let alive = true;
    getEditorPreference()
      .then((data) => {
        if (!alive) return;
        setPreference(data.preference);
        setTourDone(data.tour_done);
        setAsking(data.preference === null);
      })
      // Without an answer the builder still works with the default (guided) preset.
      .catch(() => undefined)
      .finally(() => { if (alive) setLoaded(true); });
    return () => { alive = false; };
  }, []);

  const choose = useCallback((next: BuilderPreference) => {
    setPreference(next);
    setAsking(false);
    // A new choice deserves a fresh tour of the editor with the new words.
    setTourDone(false);
    setTourRun((n) => n + 1);
    // Applies at once; if saving fails the question simply comes back next visit.
    updateEditorPreference({ preference: next, tour_done: false }).catch(() => undefined);
  }, []);

  const finishTour = useCallback(() => {
    setTourDone(true);
    updateEditorPreference({ tour_done: true }).catch(() => undefined);
  }, []);

  const replayTour = useCallback(() => {
    setTourDone(false);
    setTourRun((n) => n + 1);
  }, []);

  const value = useMemo<EditorPreferenceValue>(() => ({
    loaded,
    preference,
    preset: presetFor(preference),
    tourDone,
    tourRun,
    choose,
    finishTour,
    replayTour,
    askAgain: () => setAsking(true),
  }), [loaded, preference, tourDone, tourRun, choose, finishTour, replayTour]);

  return (
    <EditorPreferenceContext.Provider value={value}>
      {children}
      {loaded && asking && (
        <BuilderPreferenceDialog
          current={preference}
          onChoose={choose}
          onClose={preference ? () => setAsking(false) : undefined}
        />
      )}
    </EditorPreferenceContext.Provider>
  );
}
