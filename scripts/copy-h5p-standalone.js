#!/usr/bin/env node

import { cpSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const root = join(__dirname, '..');

const source = join(root, 'node_modules', 'h5p-standalone', 'dist');
const target = join(root, 'public', 'vendor', 'h5p-standalone');

if (!existsSync(source)) {
    console.error('No existe node_modules/h5p-standalone/dist.');
    process.exit(1);
}

mkdirSync(target, { recursive: true });
cpSync(source, target, { recursive: true });

console.log('Assets de h5p-standalone copiados a public/vendor/h5p-standalone');
