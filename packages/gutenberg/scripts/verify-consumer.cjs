const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const packageRoot = path.resolve(__dirname, '..');
const repositoryRoot = path.resolve(packageRoot, '../..');
const fixtureRoot = path.join(repositoryRoot, 'fixtures/gutenberg-consumer');
const temporaryDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'wp-native-gutenberg-'));

function npm(args, options = {}) {
  return execFileSync('npm', args, {
    cwd: repositoryRoot,
    encoding: 'utf8',
    stdio: options.capture ? 'pipe' : 'inherit',
    env: { ...process.env, CI: '1' },
  });
}

try {
  npm(['ci', '--prefix', fixtureRoot]);
  const packResult = JSON.parse(
    npm(['pack', '--workspace=wp-native-gutenberg', '--pack-destination', temporaryDirectory, '--json'], {
      capture: true,
    }),
  );
  const tarball = path.join(temporaryDirectory, packResult[0].filename);

  npm([
    'install',
    '--prefix',
    fixtureRoot,
    '--no-save',
    '--package-lock=false',
    tarball,
  ]);
  npm(['run', 'typecheck', '--prefix', fixtureRoot]);
  npm(['run', 'test:config', '--prefix', fixtureRoot]);
  npm(['run', 'prebuild', '--prefix', fixtureRoot]);
  npm(['run', 'test:prebuild', '--prefix', fixtureRoot]);
} finally {
  fs.rmSync(temporaryDirectory, { recursive: true, force: true });
}
