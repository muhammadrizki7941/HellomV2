import { useState, useEffect } from 'react';
import { nanoid } from 'nanoid';
import { arrayMove } from '@dnd-kit/sortable';
import { THEMES, defaultContent } from './constants';
import { Block, BlockType, BlockStyles } from './types';
import {
  createLandingPage,
  createLandingPageBlock,
  deleteLandingPageBlock,
  getLandingPageBlocks,
  getLandingPages,
  getPricingMatrix,
  getSessionUser,
  publishLandingPage,
  updateLandingPage,
} from '@/lib/hellomApi';
import { MobileEditor } from './components/MobileEditor';
import { DesktopEditor } from './components/DesktopEditor';
import { AiModal } from './components/AiModal';
import { SettingsModal } from './components/SettingsModal';

const textByTone = (tone: string) => {
  if (tone === 'santai') {
    return {
      heroButton: 'Cek Penawaran',
      ctaButton: 'Chat Sekarang',
      proofTitle: 'Kenapa banyak pelanggan suka?',
      urgency: 'Slot promo terbatas untuk periode ini.',
    };
  }

  if (tone === 'persuasif') {
    return {
      heroButton: 'Ambil Promo Hari Ini',
      ctaButton: 'Klaim Penawaran',
      proofTitle: 'Alasan pelanggan memilih kami',
      urgency: 'Jangan tunggu sampai kompetitor bergerak lebih dulu.',
    };
  }

  return {
    heroButton: 'Konsultasi Gratis',
    ctaButton: 'Hubungi Tim Kami',
    proofTitle: 'Keunggulan yang siap dipakai',
    urgency: 'Mulai dari langkah kecil yang paling berdampak.',
  };
};

const pickThemeForTone = (tone: string) => {
  if (tone === 'santai') return 'ocean';
  if (tone === 'persuasif') return 'luxury';
  return 'industrial';
};

const buildAdvancedLandingBlocks = (
  prompt: { name: string; description: string; tone: string },
  ensureProduct: (content: Record<string, unknown>) => Record<string, unknown>
): Block[] => {
  const productName = prompt.name.trim() || 'Produk Unggulan';
  const description = prompt.description.trim() || 'Solusi praktis untuk membantu pelanggan mendapatkan hasil yang lebih cepat dan lebih rapi.';
  const toneCopy = textByTone(prompt.tone);
  const shortDescription = description.length > 145 ? `${description.slice(0, 142).trim()}...` : description;
  const heroTitle = prompt.tone === 'persuasif'
    ? `${productName} yang bikin pelanggan cepat yakin`
    : `${productName} untuk pengalaman yang lebih berkelas`;

  return [
    {
      id: nanoid(),
      type: 'hero',
      content: {
        ...defaultContent.hero,
        title: heroTitle,
        subtitle: shortDescription,
        buttonText: toneCopy.heroButton,
        showButton: true,
      },
      styles: {
        paddingY: 'py-24',
        textAlign: 'center',
      },
    },
    {
      id: nanoid(),
      type: 'banner',
      content: {
        ...defaultContent.banner,
        imageUrl: `https://picsum.photos/seed/${encodeURIComponent(productName)}-hero/1400/520`,
        title: `Penawaran spesial untuk ${productName}`,
        subtitle: toneCopy.urgency,
        overlayOpacity: 0.62,
      },
      styles: {
        paddingY: 'py-24',
      },
    },
    {
      id: nanoid(),
      type: 'content',
      content: {
        ...defaultContent.content,
        title: `Tentang ${productName}`,
        body: `${description}\n\nLanding page ini dirancang untuk menjelaskan manfaat utama, membangun kepercayaan, menampilkan penawaran, dan mengarahkan pengunjung ke aksi yang jelas.`,
      },
      styles: {
        paddingY: 'py-20',
        textAlign: 'left',
      },
    },
    {
      id: nanoid(),
      type: 'features',
      content: {
        ...defaultContent.features,
        title: toneCopy.proofTitle,
        items: [
          { title: 'Value jelas', desc: 'Pesan utama langsung mudah dipahami sejak layar pertama.' },
          { title: 'Mudah dipercaya', desc: 'Susunan konten membantu calon pelanggan merasa lebih aman.' },
          { title: 'Siap konversi', desc: 'CTA diarahkan ke tindakan berikutnya tanpa membingungkan.' },
          { title: 'Rapi di mobile', desc: 'Struktur section tetap nyaman dibaca di layar kecil.' },
          { title: 'Cocok untuk promo', desc: 'Tersedia area penawaran, katalog, dan tombol kontak.' },
          { title: 'Bisa diedit cepat', desc: 'Setiap blok bisa disesuaikan dari editor tanpa coding.' },
        ],
      },
      styles: {
        paddingY: 'py-20',
      },
    },
    {
      id: nanoid(),
      type: 'product',
      content: ensureProduct({
        ...defaultContent.product,
        imageUrl: `https://picsum.photos/seed/${encodeURIComponent(productName)}-product/720/720`,
        name: productName,
        price: 'Rp 149.000',
        description: shortDescription,
        buttonText: 'Beli Sekarang',
        paymentType: 'gateway',
      }),
      styles: {
        paddingY: 'py-20',
      },
    },
    {
      id: nanoid(),
      type: 'text',
      content: {
        ...defaultContent.text,
        body: `Cocok untuk:\n- Campaign launching produk baru\n- Promo musiman atau paket bundling\n- Pengumpulan lead via WhatsApp\n- Validasi penawaran sebelum iklan dijalankan`,
      },
      styles: {
        paddingY: 'py-16',
        textAlign: 'left',
      },
    },
    {
      id: nanoid(),
      type: 'pdf',
      content: ensureProduct({
        ...defaultContent.pdf,
        title: `Download katalog ${productName}`,
        description: 'Tambahkan katalog, price list, atau company profile agar pengunjung bisa menyimpan informasi lengkap.',
        accessType: 'free',
      }),
      styles: {
        paddingY: 'py-16',
      },
    },
    {
      id: nanoid(),
      type: 'form',
      content: {
        ...defaultContent.form,
        title: `Daftar minat ${productName}`,
        subtitle: 'Kumpulkan nama, nomor HP, email, atau kebutuhan khusus calon pelanggan langsung dari landing page.',
      },
      styles: {
        paddingY: 'py-20',
      },
    },
    {
      id: nanoid(),
      type: 'social',
      content: {
        ...defaultContent.social,
      },
      styles: {
        paddingY: 'py-12',
      },
    },
    {
      id: nanoid(),
      type: 'cta',
      content: {
        ...defaultContent.cta,
        title: `Siap mulai dengan ${productName}?`,
        subtitle: 'Arahkan pengunjung ke WhatsApp, checkout, atau konsultasi agar peluang tidak berhenti di halaman.',
        buttonText: toneCopy.ctaButton,
        actionType: 'whatsapp',
        whatsappNumber: '',
        whatsappMessage: `Halo, saya tertarik dengan ${productName}. Bisa dibantu informasinya?`,
      },
      styles: {
        paddingY: 'py-24',
      },
    },
  ];
};

export default function LandingBuilder() {
  type CheckoutDefault = {
    appSlug: string;
    appName: string;
    planSlug: string;
    planName: string;
  };

  const [activeThemeId, setActiveThemeId] = useState<string>('industrial');
  const [blocks, setBlocks] = useState<Block[]>([
    { id: '1', type: 'hero', content: defaultContent.hero }
  ]);
  const [selectedBlockId, setSelectedBlockId] = useState<string | null>('1');
  const [isPreview, setIsPreview] = useState(false);
  const [currentPageId, setCurrentPageId] = useState<number | null>(null);
  const [currentPageSlug, setCurrentPageSlug] = useState<string>('landing-page');
  const [isSaving, setIsSaving] = useState(false);
  const [saveInfo, setSaveInfo] = useState<string | null>(null);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [checkoutDefault, setCheckoutDefault] = useState<CheckoutDefault | null>(null);
  
  // AI Generator State
  const [showAiModal, setShowAiModal] = useState(false);
  const [aiPrompt, setAiPrompt] = useState({
    name: '',
    description: '',
    tone: 'professional'
  });
  const [isGenerating, setIsGenerating] = useState(false);

  // Page Settings (WhatsApp Widget)
  const [showSettingsModal, setShowSettingsModal] = useState(false);
  const [pageSettings, setPageSettings] = useState({
    whatsappNumber: '',
    whatsappMessage: 'Halo, saya tertarik dengan produk Anda.',
    showFloatingWhatsapp: false
  });

  // Mobile Detection
  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    const checkMobile = () => setIsMobile(window.innerWidth < 768);
    checkMobile();
    window.addEventListener('resize', checkMobile);
    return () => window.removeEventListener('resize', checkMobile);
  }, []);

  useEffect(() => {
    const loadCheckoutDefaults = async () => {
      try {
        const matrix = await getPricingMatrix();
        const withPlans = (matrix.items || []).find((item) => (item.plans || []).length > 0);
        if (!withPlans) {
          setCheckoutDefault(null);
          return;
        }

        const targetPlan = withPlans.plans.find((plan) => !plan.is_current) || withPlans.plans[0];
        if (!targetPlan) {
          setCheckoutDefault(null);
          return;
        }

        setCheckoutDefault({
          appSlug: withPlans.app.slug,
          appName: withPlans.app.name,
          planSlug: targetPlan.slug,
          planName: targetPlan.name,
        });
      } catch {
        setCheckoutDefault(null);
      }
    };

    void loadCheckoutDefaults();
  }, []);

  useEffect(() => {
    const loadPage = async () => {
      setSaveError(null);
      try {
        const pages = await getLandingPages();
        const first = pages.items?.[0];
        if (!first) {
          return;
        }

        setCurrentPageId(first.id);
        setCurrentPageSlug(first.slug);

        const remoteTheme = String((first.content as Record<string, unknown> | null)?.theme || activeThemeId);
        if (THEMES.some((theme) => theme.id === remoteTheme)) {
          setActiveThemeId(remoteTheme);
        }

        const remoteSettings = (first.content as Record<string, unknown> | null)?.settings as Partial<typeof pageSettings> | undefined;
        if (remoteSettings) {
          setPageSettings((current) => ({
            ...current,
            ...remoteSettings,
          }));
        }

        const blocksResult = await getLandingPageBlocks(first.id);
        if ((blocksResult.items || []).length > 0) {
          const mappedBlocks: Block[] = blocksResult.items.map((row) => ({
            id: String(row.id),
            type: (['hero', 'features', 'cta', 'content', 'banner', 'product', 'video', 'text', 'image', 'pdf', 'social', 'form'].includes(row.block_type)
              ? row.block_type
              : 'content') as BlockType,
            content: row.content || {},
            styles: (row.content as Record<string, unknown> | null)?.styles as BlockStyles | undefined,
          }));
          setBlocks(mappedBlocks);
          setSelectedBlockId(mappedBlocks[0]?.id ?? null);
        }
      } catch (loadError) {
        const message = loadError instanceof Error ? loadError.message : 'Gagal memuat landing page editor';
        setSaveError(message);
      }
    };

    void loadPage();
  }, []);

  const activeTheme = THEMES.find(t => t.id === activeThemeId) || THEMES[0];

  const ensureProductCheckoutContent = (content: Record<string, unknown>) => {
    if (!checkoutDefault) {
      return content;
    }

    return {
      ...content,
      appSlug: (content.appSlug as string | undefined) || checkoutDefault.appSlug,
      appName: (content.appName as string | undefined) || checkoutDefault.appName,
      planSlug: (content.planSlug as string | undefined) || checkoutDefault.planSlug,
      planName: (content.planName as string | undefined) || checkoutDefault.planName,
    };
  };

  useEffect(() => {
    if (!checkoutDefault) {
      return;
    }

    setBlocks((prev) => {
      let changed = false;
      const next = prev.map((block) => {
        if (block.type !== 'product' && block.type !== 'pdf') {
          return block;
        }

        const updatedContent = ensureProductCheckoutContent(block.content as Record<string, unknown>);
        if (
          (updatedContent.appSlug as string | undefined) !== (block.content as Record<string, unknown>).appSlug ||
          (updatedContent.planSlug as string | undefined) !== (block.content as Record<string, unknown>).planSlug
        ) {
          changed = true;
          return { ...block, content: updatedContent };
        }

        return block;
      });

      return changed ? next : prev;
    });
  }, [checkoutDefault]);

  const handleAiGenerate = () => {
    setIsGenerating(true);
    // Simulate AI generation delay
    setTimeout(() => {
      const newBlocks = buildAdvancedLandingBlocks(aiPrompt, ensureProductCheckoutContent);
      setActiveThemeId(pickThemeForTone(aiPrompt.tone));
      setBlocks(newBlocks);
      setSelectedBlockId(newBlocks[0]?.id ?? null);
      setIsGenerating(false);
      setShowAiModal(false);
    }, 2000);
  };

  const addBlock = (type: BlockType) => {
    const defaultBlockContent = { ...defaultContent[type] };
    const content = type === 'product' || type === 'pdf'
      ? ensureProductCheckoutContent(defaultBlockContent as Record<string, unknown>)
      : defaultBlockContent;

    const newBlock: Block = {
      id: nanoid(),
      type,
      content,
    };
    setBlocks([...blocks, newBlock]);
    setSelectedBlockId(newBlock.id);
  };

  const updateBlockContent = (id: string, newContent: any) => {
    setBlocks(blocks.map(b => b.id === id ? { ...b, content: newContent } : b));
  };

  const updateBlockStyles = (id: string, newStyles: BlockStyles) => {
    setBlocks(blocks.map(b => b.id === id ? { ...b, styles: { ...b.styles, ...newStyles } } : b));
  };

  const moveBlock = (index: number, direction: 'up' | 'down') => {
    if (direction === 'up' && index === 0) return;
    if (direction === 'down' && index === blocks.length - 1) return;
    
    const newBlocks = [...blocks];
    const targetIndex = direction === 'up' ? index - 1 : index + 1;
    [newBlocks[index], newBlocks[targetIndex]] = [newBlocks[targetIndex], newBlocks[index]];
    setBlocks(newBlocks);
  };

  const reorderBlocks = (oldIndex: number, newIndex: number) => {
    setBlocks((items) => arrayMove(items, oldIndex, newIndex));
  };

  const deleteBlock = (id: string) => {
    setBlocks(blocks.filter(b => b.id !== id));
    if (selectedBlockId === id) setSelectedBlockId(null);
  };

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>, fieldName: string, isStyle = false) => {
    const file = e.target.files?.[0];
    if (file && selectedBlockId) {
      const reader = new FileReader();
      reader.onload = (event) => {
        const result = event.target?.result as string;
        const selectedBlock = blocks.find(b => b.id === selectedBlockId);
        if (selectedBlock) {
          if (isStyle) {
             updateBlockStyles(selectedBlockId, { [fieldName]: result });
          } else {
            updateBlockContent(selectedBlockId, { 
              ...selectedBlock.content, 
              [fieldName]: result,
              fileName: fieldName === 'fileUrl' ? file.name : selectedBlock.content.fileName
            });
          }
        }
      };
      reader.readAsDataURL(file);
    }
  };

  const syncToBackend = async (publish: boolean) => {
    setIsSaving(true);
    setSaveError(null);
    setSaveInfo(null);
    try {
      let pageId = currentPageId;
      let pageSlug = currentPageSlug;

      if (!pageId) {
        const created = await createLandingPage({
          title: 'Landing Page',
          slug: pageSlug,
          content: { theme: activeThemeId, settings: pageSettings },
        });
        pageId = created.id;
        pageSlug = created.slug;
        setCurrentPageId(pageId);
        setCurrentPageSlug(pageSlug);
      }

      await updateLandingPage(pageId, {
        title: 'Landing Page',
        slug: pageSlug,
        content: { theme: activeThemeId, settings: pageSettings },
      });

      const existing = await getLandingPageBlocks(pageId);
      for (const oldBlock of existing.items || []) {
        await deleteLandingPageBlock(pageId, oldBlock.id);
      }

      for (let index = 0; index < blocks.length; index += 1) {
        const block = blocks[index];
        const blockContent = block.type === 'product' || block.type === 'pdf'
          ? ensureProductCheckoutContent(block.content as Record<string, unknown>)
          : block.content;

        await createLandingPageBlock(pageId, {
          block_key: `ui_${index + 1}_${block.type}`,
          block_type: block.type,
          sort_order: index,
          is_visible: true,
          content: {
            ...blockContent,
            styles: block.styles,
          },
        });
      }

      if (publish) {
        await publishLandingPage(pageId);
        const orgSlug = getSessionUser<{ current_organization?: { slug?: string } }>()?.current_organization?.slug;
        const publicPath = orgSlug ? `/p/landingpage/${orgSlug}` : `/p/landingpage/${pageSlug}`;
        setSaveInfo(`Publish berhasil. Akses halaman di ${publicPath}`);
      } else {
        setSaveInfo('Draft berhasil disimpan ke backend.');
      }
    } catch (syncError) {
      const message = syncError instanceof Error ? syncError.message : 'Gagal sync landing editor';
      setSaveError(message);
    } finally {
      setIsSaving(false);
    }
  };

  const handleSave = () => {
    void syncToBackend(false);
  };

  const handlePublish = () => {
    void syncToBackend(true);
  };

  return (
    <>
      {saveError && (
        <div className="mb-3 p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600">{saveError}</div>
      )}
      {saveInfo && (
        <div className="mb-3 p-3 rounded-lg bg-green-50 border border-green-100 text-sm text-green-700">{saveInfo}</div>
      )}
      {isMobile ? (
        <MobileEditor 
          blocks={blocks}
          selectedBlockId={selectedBlockId}
          setSelectedBlockId={setSelectedBlockId}
          activeTheme={activeTheme}
          addBlock={addBlock}
          updateBlockContent={updateBlockContent}
          updateBlockStyles={updateBlockStyles}
          moveBlock={moveBlock}
          deleteBlock={deleteBlock}
          handleFileUpload={handleFileUpload}
          isPreview={isPreview}
          setIsPreview={setIsPreview}
          setShowAiModal={setShowAiModal}
          setShowSettingsModal={setShowSettingsModal}
          onSave={handleSave}
          onPublish={handlePublish}
          isSaving={isSaving}
          pageSettings={pageSettings}
        />
      ) : (
        <DesktopEditor 
          blocks={blocks}
          selectedBlockId={selectedBlockId}
          setSelectedBlockId={setSelectedBlockId}
          activeTheme={activeTheme}
          activeThemeId={activeThemeId}
          setActiveThemeId={setActiveThemeId}
          THEMES={THEMES}
          addBlock={addBlock}
          updateBlockContent={updateBlockContent}
          updateBlockStyles={updateBlockStyles}
          reorderBlocks={reorderBlocks}
          deleteBlock={deleteBlock}
          handleFileUpload={handleFileUpload}
          isPreview={isPreview}
          setIsPreview={setIsPreview}
          setShowAiModal={setShowAiModal}
          setShowSettingsModal={setShowSettingsModal}
          onSave={handleSave}
          onPublish={handlePublish}
          isSaving={isSaving}
          pageSettings={pageSettings}
        />
      )}

      <AiModal 
        isOpen={showAiModal}
        onClose={() => setShowAiModal(false)}
        onGenerate={handleAiGenerate}
        isGenerating={isGenerating}
        prompt={aiPrompt}
        setPrompt={setAiPrompt}
      />

      <SettingsModal 
        isOpen={showSettingsModal}
        onClose={() => setShowSettingsModal(false)}
        settings={pageSettings}
        setSettings={setPageSettings}
      />
    </>
  );
}
