import { inspectComponents } from '@formbraid/core/vite';
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import integration from '../resources/js/formbraid/zero.ts';

// CLI generation uses the same registry and inspector as the Formbraid Vite plugin.
const snapshot = inspectComponents(fileURLToPath(integration.registry), process.cwd());
writeFileSync(integration.metadata, `${JSON.stringify(snapshot, null, 2)}\n`);
