// Node ESM loader hook that maps `@/...` imports to `./resources/js/...`.
// Used ONLY by the verify_phase6_account_belongs_to_module.mjs test script.
// Mirrors the Vite alias defined in vite.config.js (`'@': '/resources/js'`).
//
// Note: Node ESM requires explicit `.js` extensions on relative imports, but
// the production code uses extension-less imports (which Vite handles for us).
// We resolve the file by trying with the `.js` extension appended if needed.

import { fileURLToPath, pathToFileURL } from 'node:url';
import { resolve as pathResolve, dirname, extname } from 'node:path';
import { existsSync } from 'node:fs';

const PROJECT_ROOT = dirname(fileURLToPath(import.meta.url));

function resolveJsPath(absBase) {
  // If extension present, use as-is.
  if (extname(absBase)) return absBase;
  // Try with .js, then as a directory /index.js
  if (existsSync(absBase + '.js')) return absBase + '.js';
  if (existsSync(absBase) && existsSync(absBase + '/index.js')) return absBase + '/index.js';
  return absBase + '.js'; // fall through — let Node report the error
}

export async function resolve(specifier, context, nextResolve) {
  if (specifier.startsWith('@/')) {
    const rel = specifier.slice(2); // drop '@/'
    const absBase = pathResolve(PROJECT_ROOT, 'resources/js', rel);
    const abs = resolveJsPath(absBase);
    const url = pathToFileURL(abs).href;
    return nextResolve(url, context);
  }
  return nextResolve(specifier, context);
}