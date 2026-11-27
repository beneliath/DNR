import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

// Fast fixture checks complement release-workflows.test.cjs, which needs the
// disposable application image and is run by integration_environment.py.
const suites = [
    'initial-layout', 'mobile-shell', 'sticky-banners', 'edit-engagement', 'new-engagement-location', 'inquiry-draft',
    'audience-mobile-workspace', 'audience-form-reminders', 'audience-ai-visibility', 'audience-home-paint',
];
const root = fileURLToPath(new URL('../', import.meta.url));
const result = spawnSync(process.execPath, ['--test', '--test-concurrency=2', '--test-timeout=600000',
    ...suites.map(name => `tests/browser/${name}.test.cjs`)], {cwd: root, stdio: 'inherit'});
if (result.error) console.error(result.error.message);
process.exitCode = result.status ?? 1;
