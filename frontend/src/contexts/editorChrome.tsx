import { createContext, useContext } from 'react';

/**
 * Lets a deeply-nested editor toolbar (e.g. the Landing Builder mobile editor)
 * collapse the surrounding dashboard chrome — the mobile top header and the
 * app tabs — so the editing canvas gets the full screen. Only has a visible
 * effect on mobile; the dashboard hides its mobile-only chrome at <lg widths.
 */
export interface EditorChromeValue {
  /** When true, the mobile dashboard header + app tabs are hidden. */
  chromeHidden: boolean;
  setChromeHidden: (hidden: boolean) => void;
}

export const EditorChromeContext = createContext<EditorChromeValue>({
  chromeHidden: false,
  setChromeHidden: () => undefined,
});

export const useEditorChrome = () => useContext(EditorChromeContext);
