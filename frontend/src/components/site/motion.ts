// Motion tokens for the public site. Everything animates transform/opacity only.
import { createContext, useContext } from 'react';

/** Curtain wipe: slow in, slow out. */
export const EASE_CURTAIN = [0.76, 0, 0.24, 1] as const;
/** Content reveal: fast start, soft landing. */
export const EASE_REVEAL = [0.22, 1, 0.36, 1] as const;

export const CURTAIN_DURATION = 0.55;
/** Stagger between hero title lines. */
export const LINE_STAGGER = 0.08;

/**
 * Seconds to wait before a page's first-screen reveal starts: after the preloader
 * on the first visit, or while the curtain is lifting on navigation.
 */
export const RevealDelayContext = createContext(0);
export const useRevealDelay = () => useContext(RevealDelayContext);

/** Title of the page being navigated to (shown in the middle of the curtain). */
export const DestinationTitleContext = createContext('');

export function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Fine pointer = mouse/trackpad (desktop). Magnetic buttons and Lenis are desktop-only. */
export function hasFinePointer(): boolean {
  return typeof window !== 'undefined' && window.matchMedia('(pointer: fine)').matches;
}
