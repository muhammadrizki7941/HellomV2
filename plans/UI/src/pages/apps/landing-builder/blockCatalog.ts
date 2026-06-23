import {
  Layout, Type, Image as ImageIcon, MousePointer2, Video, ShoppingBag,
  FileText, Share2, ClipboardList, LayoutGrid, AlignLeft, ArrowRight,
  Minus, Quote, HelpCircle, List, Images, Hourglass, Wand2, Code2,
  LucideIcon,
} from 'lucide-react';
import { BlockType } from './types';

export type BlockCategory = 'popular' | 'order' | 'sales' | 'other';

export interface BlockCatalogItem {
  type: BlockType;
  icon: LucideIcon;
  /** i18n keys */
  labelKey: string;
  descKey: string;
  categories: BlockCategory[];
}

// Order here = display order inside each category / "All" tab.
export const BLOCK_CATALOG: BlockCatalogItem[] = [
  { type: 'text', icon: Type, labelKey: 'block.text.label', descKey: 'block.text.desc', categories: ['popular', 'sales'] },
  { type: 'image', icon: ImageIcon, labelKey: 'block.image.label', descKey: 'block.image.desc', categories: ['popular', 'other'] },
  { type: 'hero', icon: Layout, labelKey: 'block.hero.label', descKey: 'block.hero.desc', categories: ['popular', 'sales'] },
  { type: 'button', icon: ArrowRight, labelKey: 'block.button.label', descKey: 'block.button.desc', categories: ['popular', 'order', 'sales'] },
  { type: 'content', icon: AlignLeft, labelKey: 'block.content.label', descKey: 'block.content.desc', categories: ['popular', 'sales'] },

  { type: 'form', icon: ClipboardList, labelKey: 'block.form.label', descKey: 'block.form.desc', categories: ['order', 'popular'] },
  { type: 'product', icon: ShoppingBag, labelKey: 'block.product.label', descKey: 'block.product.desc', categories: ['order', 'sales'] },
  { type: 'cta', icon: MousePointer2, labelKey: 'block.cta.label', descKey: 'block.cta.desc', categories: ['order', 'sales'] },
  { type: 'pdf', icon: FileText, labelKey: 'block.pdf.label', descKey: 'block.pdf.desc', categories: ['order', 'sales'] },

  { type: 'banner', icon: ImageIcon, labelKey: 'block.banner.label', descKey: 'block.banner.desc', categories: ['sales'] },
  { type: 'features', icon: LayoutGrid, labelKey: 'block.features.label', descKey: 'block.features.desc', categories: ['sales'] },
  { type: 'testimonials', icon: Quote, labelKey: 'block.testimonials.label', descKey: 'block.testimonials.desc', categories: ['sales'] },
  { type: 'faq', icon: HelpCircle, labelKey: 'block.faq.label', descKey: 'block.faq.desc', categories: ['sales'] },
  { type: 'list', icon: List, labelKey: 'block.list.label', descKey: 'block.list.desc', categories: ['sales'] },
  { type: 'countdown', icon: Hourglass, labelKey: 'block.countdown.label', descKey: 'block.countdown.desc', categories: ['sales'] },

  { type: 'video', icon: Video, labelKey: 'block.video.label', descKey: 'block.video.desc', categories: ['other', 'sales'] },
  { type: 'slider', icon: Images, labelKey: 'block.slider.label', descKey: 'block.slider.desc', categories: ['other'] },
  { type: 'gif', icon: Wand2, labelKey: 'block.gif.label', descKey: 'block.gif.desc', categories: ['other'] },
  { type: 'social', icon: Share2, labelKey: 'block.social.label', descKey: 'block.social.desc', categories: ['other'] },
  { type: 'divider', icon: Minus, labelKey: 'block.divider.label', descKey: 'block.divider.desc', categories: ['other'] },
  { type: 'html', icon: Code2, labelKey: 'block.html.label', descKey: 'block.html.desc', categories: ['other'] },
];

export const CATEGORY_TABS: { id: 'all' | BlockCategory; labelKey: string }[] = [
  { id: 'all', labelKey: 'cat.all' },
  { id: 'popular', labelKey: 'cat.popular' },
  { id: 'order', labelKey: 'cat.order' },
  { id: 'sales', labelKey: 'cat.sales' },
  { id: 'other', labelKey: 'cat.other' },
];

/** Map type -> icon, used by the Structure tab and elsewhere. */
export const BLOCK_ICON: Record<BlockType, LucideIcon> = BLOCK_CATALOG.reduce(
  (acc, item) => {
    acc[item.type] = item.icon;
    return acc;
  },
  {} as Record<BlockType, LucideIcon>,
);

export const BLOCK_LABEL_KEY: Record<BlockType, string> = BLOCK_CATALOG.reduce(
  (acc, item) => {
    acc[item.type] = item.labelKey;
    return acc;
  },
  {} as Record<BlockType, string>,
);
