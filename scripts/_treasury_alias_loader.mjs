// Node module loader — يحل aliases زي `@/constants/foo` → `resources/js/constants/foo`
// Node ESM resolver بيرفض الـ aliases دي بشكل افتراضي، فمحتاجين hook يدوية.
//
// شغّيله مع: `node --import scripts/_treasury_alias_loader.mjs scripts/test_treasury_account_groups.mjs`

import { fileURLToPath, pathToFileURL } from 'node:url';
import { resolve as pathResolve, dirname } from 'node:path';

const PROJECT_ROOT = pathResolve(dirname(fileURLToPath(import.meta.url)), '..');
const RESOURCES_DIR = pathResolve(PROJECT_ROOT, 'resources/js');

export async function resolve(specifier, context, nextResolve) {
  if (specifier.startsWith('@/')) {
    const rel = specifier.slice(2);              // '@/constants/...' → 'constants/...'
    const candidates = [
      pathToFileURL(pathResolve(RESOURCES_DIR, `${rel}.js`)).href,
      pathToFileURL(pathResolve(RESOURCES_DIR, `${rel}/index.js`)).href,
      pathToFileURL(pathResolve(RESOURCES_DIR, rel)).href,
    ];
    for (const url of candidates) {
      try {
        return await nextResolve(url, context);
      } catch (err) {
        if (err.code !== 'ERR_MODULE_NOT_FOUND' && err.code !== 'ERR_FILE_NOT_FOUND') {
          throw err;
        }
      }
    }
  }
  return nextResolve(specifier, context);
}
