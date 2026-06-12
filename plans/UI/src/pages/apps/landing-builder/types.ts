export type BlockType = 'hero' | 'features' | 'cta' | 'content' | 'banner' | 'product' | 'video' | 'text' | 'image' | 'pdf' | 'social' | 'form';

export interface BlockStyles {
  backgroundColor?: string;
  backgroundImage?: string;
  textColor?: string;
  buttonColor?: string;
  buttonTextColor?: string;
  paddingY?: string; // 'py-8', 'py-16', 'py-24', etc.
  textAlign?: 'left' | 'center' | 'right';
}

export interface Block {
  id: string;
  type: BlockType;
  content: Record<string, any>;
  styles?: BlockStyles;
}
