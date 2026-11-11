import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const serverPath = fileURLToPath(new URL('../server.js', import.meta.url));

for (const [description, secret] of [
  ['missing', ''],
  ['public placeholder', 'change-me']
]) {
  test(`server refuses a ${description} realtime secret`, () => {
    const result = spawnSync(process.execPath, [serverPath], {
      env: { ...process.env, REALTIME_SERVER_SECRET: secret }
    });

    assert.ifError(result.error);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr.toString(), /REALTIME_SERVER_SECRET must be configured/);
  });
}
