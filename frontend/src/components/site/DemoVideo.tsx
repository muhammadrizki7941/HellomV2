import { useState } from 'react';
import { getImageUrl } from '@/lib/hellomApi';

export function demoVideoSource(value: string): { src: string } | null {
  // Only files uploaded to our own public media storage can be played here.
  if (!/^\/media\/showcase\/[a-zA-Z0-9_-]+\.(mp4|webm)$/i.test(value)) return null;
  return { src: getImageUrl(value) };
}

export default function DemoVideo({ url, title }: { url: string; title: string }) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null);
  const source = demoVideoSource(url);
  if (!source || failedUrl === url) return <p role="status">Video belum dapat diputar. Silakan coba lagi nanti.</p>;
  return <div className="home-video-frame mx-auto mt-7 aspect-video w-full max-w-[940px] overflow-hidden rounded-3xl bg-zinc-950"><video className="h-full w-full" key={source.src} src={source.src} controls playsInline preload="metadata" aria-label={title} onError={() => setFailedUrl(url)} /></div>;
}
