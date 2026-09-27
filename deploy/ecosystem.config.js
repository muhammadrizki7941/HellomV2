// PM2 process file for the Hellom realtime server (and an optional queue worker).
//
//   pm2 startOrReload deploy/ecosystem.config.js --update-env
//   pm2 save
//
// Secrets are NOT stored here: they are read from realtime/.env (see
// realtime/.env.example), which is git-ignored and lives only on the server.

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');

// Minimal KEY=VALUE parser so PM2 can pass realtime/.env to server.js
// (server.js reads process.env and does not load .env files itself).
function readEnvFile(file) {
  if (!fs.existsSync(file)) return {};
  return fs
    .readFileSync(file, 'utf8')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#') && line.includes('='))
    .reduce((env, line) => {
      const index = line.indexOf('=');
      const key = line.slice(0, index).trim();
      const value = line.slice(index + 1).trim().replace(/^(['"])(.*)\1$/, '$2');
      env[key] = value;
      return env;
    }, {});
}

module.exports = {
  apps: [
    {
      name: 'hellom-realtime',
      cwd: path.join(root, 'realtime'),
      script: 'server.js',
      instances: 1,
      exec_mode: 'fork',
      autorestart: true,
      max_memory_restart: '256M',
      env: {
        NODE_ENV: 'production',
        PORT: '3001',
        // Same as today. Once Nginx proxies /socket.io (deploy/nginx/*.conf.example),
        // set HOST=127.0.0.1 in realtime/.env so port 3001 is not exposed publicly.
        HOST: '0.0.0.0',
        ...readEnvFile(path.join(root, 'realtime', '.env')),
      },
    },

    // Optional: Laravel queue worker. Only needed if QUEUE_CONNECTION is not
    // "sync" AND something is dispatched to the queue (today nothing is:
    // no job/mail/listener implements ShouldQueue). Uncomment when needed.
    // {
    //   name: 'hellom-queue',
    //   cwd: path.join(root, 'backend'),
    //   script: 'artisan',
    //   interpreter: 'php',
    //   args: 'queue:work --sleep=3 --tries=3 --max-time=3600',
    //   autorestart: true,
    // },
  ],
};
