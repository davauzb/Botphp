// Veb qismni bitta fayl (app.js) ga yig'adi va Android "assets" papkasiga ko'chiradi.
//   npm run build   -> ../android/app/src/main/assets/ ga yozadi
//   npm run dev     -> http://localhost:8000 da brauzerda sinash uchun
import * as esbuild from 'esbuild';
import { cpSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const serve = process.argv.includes('--serve');
const outdir = serve ? join(here, 'dist') : join(here, '..', 'android', 'app', 'src', 'main', 'assets');

rmSync(outdir, { recursive: true, force: true });
mkdirSync(outdir, { recursive: true });
for (const f of ['index.html', 'style.css']) cpSync(join(here, 'public', f), join(outdir, f));

const options = {
  entryPoints: [join(here, 'src', 'main.js')],
  bundle: true,
  format: 'iife',
  target: ['chrome80'],
  minify: !serve,
  sourcemap: serve,
  outfile: join(outdir, 'app.js'),
  loader: { '.json': 'json' },
  logLevel: 'info',
};

if (serve) {
  const ctx = await esbuild.context(options);
  await ctx.watch();
  const { port } = await ctx.serve({ servedir: outdir, port: 8000 });
  console.log(`Dev server: http://localhost:${port}`);
} else {
  await esbuild.build(options);
}
