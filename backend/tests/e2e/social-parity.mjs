// Editor (socialPlatforms.ts) and server (App\Support\Landing\SocialLinks) must agree on every link,
// otherwise the editor says "✓" for a link the server drops (or the other way round).
// Run from backend/: node tests/e2e/social-parity.mjs   (Node 22.18+ runs the .ts file directly)
import { execFileSync } from 'node:child_process';
import { socialUrl, SOCIAL_PLATFORMS } from '../../../frontend/src/pages/apps/landing-builder/editor/socialPlatforms.ts';

const values = [
  '@toko.kue', 'toko.kue', 'tokokue', 'toko_kue', 'toko-kue', 'ab', 'a', 'toko kue', '0812-3456-7890', '+62 812 3456 7890', '628123456789',
  '123', 'Halo@Toko.ID', 'mailto:halo@toko.id', 'bukan-email', 'tokokue.id', 'https://tokokue.id/menu', 'javascript:alert(1)',
  'https://www.instagram.com/toko.kue/?hl=id', 'https://instagram.com/p/abc', 'https://www.tiktok.com/@toko_kue?lang=id',
  'https://youtube.com/@tokokue', 'https://www.youtube.com/channel/UC1234567890abcdefghijkl', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
  'https://web.facebook.com/tokokue', 'https://www.facebook.com/profile.php?id=100012345678', 'https://twitter.com/tokokue', 'https://x.com/tokokue/status/1',
  'https://www.threads.net/@tokokue', 'https://wa.me/6281234567890', 't.me/tokokue', 'https://www.linkedin.com/company/hellom/', 'https://www.linkedin.com/in/budi-s',
  'https://id.pinterest.com/tokokue/', 'https://shopee.co.id/tokokue', 'tokopedia.com/toko-kue', 'https://open.spotify.com/intl-id/artist/0TnOYISbd1XYRBk9myaseg',
  'https://open.spotify.com/track/0TnOYISbd1XYRBk9myaseg', 'https://discord.gg/abcDEF', 'https://discord.com/invite/abcDEF', 'https://www.snapchat.com/add/tokokue',
  'https://www.twitch.tv/tokokue', 'https://evil.com/x', 'https://instagram.com.evil.tld/x', '<script>', 'http://tokokue.id',
];
const cases = SOCIAL_PLATFORMS.flatMap((p) => values.map((v) => [p.key, v]));
const php = execFileSync('php', ['-r', `require 'vendor/autoload.php'; $cases = json_decode(stream_get_contents(STDIN), true); echo json_encode(array_map(fn ($c) => App\\Support\\Landing\\SocialLinks::url($c[0], $c[1]), $cases));`], { input: JSON.stringify(cases) }).toString();
const server = JSON.parse(php);
let mismatches = 0;
cases.forEach(([platform, value], i) => {
  const editor = socialUrl(platform, value);
  if (editor !== server[i]) {
    mismatches++;
    console.log(`MISMATCH ${platform} "${value}": editor=${editor} server=${server[i]}`);
  }
});
console.log(`${cases.length - mismatches}/${cases.length} cases agree`);
process.exit(mismatches === 0 ? 0 : 1);
