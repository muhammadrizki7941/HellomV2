export function safeBannerLink(value?: string | null): string | null {
  if (!value || [...value].some(char => char.charCodeAt(0) <= 0x20 || char.charCodeAt(0) === 0x7f || char === '\\')) return null;
  if (value.startsWith('/') && !value.startsWith('//')) return value;
  try {
    const url = new URL(value);
    return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
  } catch {
    return null;
  }
}

export function toDateTimeLocal(value?: string | null): string {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
}

export function toDateTimeIso(value: string): string {
  return value ? new Date(value).toISOString() : '';
}
