import { ReactNode } from 'react';

interface PillProps {
  children: ReactNode;
  className?: string;
}

export const Pill = ({ children, className = '' }: PillProps) => (
  <span className={`inline-block text-xs font-bold uppercase tracking-wider px-3 py-1 rounded-full ${className}`}>
    {children}
  </span>
);