// Copies Lucide SVG icons from node_modules into resources/icons/lucide so the
// server can render them inline without Node.js being present in production.
// Run with: npm run icons:sync
import { readdirSync, readFileSync, writeFileSync, mkdirSync, copyFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'node_modules', 'lucide-static', 'icons');
const target = join(root, 'resources', 'icons', 'lucide');
mkdirSync(target, { recursive: true });

let count = 0;
for (const file of readdirSync(source)) {
    if (!file.endsWith('.svg')) continue;
    const svg = readFileSync(join(source, file), 'utf8');
    // Keep only the drawing primitives; the Blade component builds the <svg> wrapper.
    const inner = svg
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/^[\s\S]*?<svg[\s\S]*?>/, '')
        .replace(/<\/svg>[\s\S]*$/, '')
        .replace(/\s*\n\s*/g, '')
        .trim();
    writeFileSync(join(target, file), inner);
    count++;
}
copyFileSync(join(root, 'node_modules', 'lucide-static', 'LICENSE'), join(target, 'LICENSE'));
console.log(`Synced ${count} Lucide icons to resources/icons/lucide`);
