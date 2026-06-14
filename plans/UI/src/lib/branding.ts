export const BRAND_NAME = 'Hellom';

// Fallback logo paths (prioritized order)
export const BRAND_LOGO_PATH = '/assets/hellom.png'; // Actual logo instead of 1x1 GIF

// Helper function to get logo URL from brand settings
export function getBrandLogo(brandLogoUrl: string | null | undefined, fallback: string = BRAND_LOGO_PATH): string {
  if (brandLogoUrl && typeof brandLogoUrl === 'string' && brandLogoUrl.trim()) {
    return brandLogoUrl;
  }
  return fallback;
}
