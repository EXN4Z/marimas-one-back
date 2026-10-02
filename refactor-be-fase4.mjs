#!/usr/bin/env node
/**
 * Marimas One — Fase 4 (BE): pindah controller / model / import / request + update namespace.
 *
 * Taruh file ini di root repo backend (sejajar `artisan`), lalu:
 *
 *   node refactor-be-fase4.mjs                  # DRY-RUN (default): cuma nampilin rencana, gak nulis apa-apa
 *   node refactor-be-fase4.mjs --apply          # eksekusi (pakai `git mv` kalau repo git)
 *   node refactor-be-fase4.mjs --apply --only=controllers
 *   node refactor-be-fase4.mjs --apply --only=models,imports,requests
 *   node refactor-be-fase4.mjs --verify         # cek struktur/namespace/use di kondisi sekarang (tanpa ubah apa-apa)
 *   node refactor-be-fase4.mjs --root=/path/ke/marimas-one-back
 *
 * Group yang tersedia: controllers | models | imports | requests (default: semuanya).
 * Aman dijalankan ulang: file yang sudah pindah dilewati.
 *
 * Yang dikerjakan script:
 *   1. Pindah file sesuai tabel di buku (bagian 6).
 *   2. Ganti baris `namespace ...;` di file yang pindah.
 *   3. Ganti SEMUA referensi FQCN lama -> baru di app/ routes/ database/ config/ tests/ bootstrap/
 *      (use, \App\Models\X::, 'App\\Models\\X' di string, dst).
 *   4. Tambah `use` otomatis buat referensi "nama telanjang" yang dulu resolve karena satu namespace
 *      (mis. `extends Controller` di controller yang pindah folder, `LokasiKantor::class` di User.php).
 *   5. Buang `use` yang jadi redundant (kelas yang sekarang satu namespace sama file itu).
 *   6. Verifikasi statis: namespace == path, tiap `use App\...` nunjuk ke kelas yang ada, gak ada nama
 *      telanjang yang kehilangan `use`.
 *
 * Setelah --apply, tetap jalankan: composer dump-autoload && php artisan route:list && php artisan optimize:clear
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

// ───────────────────────── konfigurasi ─────────────────────────

const args = process.argv.slice(2);
const flag = (n) => args.includes(`--${n}`);
const opt = (n) => (args.find((a) => a.startsWith(`--${n}=`)) || '').split('=').slice(1).join('=');

const ROOT = path.resolve(opt('root') || process.cwd());
const APPLY = flag('apply');
const VERIFY_ONLY = flag('verify');
const ONLY = opt('only') ? opt('only').split(',').map((s) => s.trim()) : null;

const C = 'app/Http/Controllers';
// [group, dari, ke]  (path relatif root repo)
const MOVES = [
  ['controllers', `${C}/Organisasi/DepartemenController.php`, `${C}/MasterData/DepartemenController.php`],
  ['controllers', `${C}/Organisasi/CabangController.php`, `${C}/MasterData/CabangController.php`],
  ['controllers', `${C}/Organisasi/PerusahaanController.php`, `${C}/MasterData/PerusahaanController.php`],
  ['controllers', `${C}/Karyawan/UserController.php`, `${C}/MasterData/UserController.php`],
  ['controllers', `${C}/Karyawan/AdminUserController.php`, `${C}/MasterData/AdminUserController.php`],
  ['controllers', `${C}/RoleController.php`, `${C}/MasterData/RoleController.php`],
  ['controllers', `${C}/AuditLogController.php`, `${C}/AuditLog/AuditLogController.php`],
  ['controllers', `${C}/NotificationController.php`, `${C}/Notifikasi/NotificationController.php`],
  ['controllers', `${C}/PushSubscriptionController.php`, `${C}/Notifikasi/PushSubscriptionController.php`],
  ['requests', 'app/Http/Requests/StoreCabangRequest.php', 'app/Http/Requests/MasterData/StoreCabangRequest.php'],
  ['models', 'app/Models/Perusahaan.php', 'app/Models/MasterData/Perusahaan.php'],
  ['models', 'app/Models/LokasiKantor.php', 'app/Models/MasterData/LokasiKantor.php'],
  ...['Karyawan', 'Supplier', 'Departemen', 'Cabang', 'Perusahaan', 'InventoryBukti'].map((n) => [
    'imports', `app/Imports/${n}Import.php`, `app/Imports/MasterData/${n}Import.php`,
  ]),
  ['imports', 'app/Imports/InventoryPenangananImport.php', 'app/Imports/Transaksi/InventoryPenangananImport.php'],
];

const SCAN_DIRS = ['app', 'routes', 'database', 'config', 'tests', 'bootstrap'];
const SKIP_DIRS = new Set(['vendor', 'node_modules', 'storage', 'cache', '.git']);

// ───────────────────────── util ─────────────────────────

const log = (...a) => console.log(...a);
const posix = (p) => p.split(path.sep).join('/');
const exists = (rel) => fs.existsSync(path.join(ROOT, rel));

function walk(dirRel, out = []) {
  const abs = path.join(ROOT, dirRel);
  if (!fs.existsSync(abs)) return out;
  for (const e of fs.readdirSync(abs, { withFileTypes: true })) {
    if (e.isDirectory()) {
      if (!SKIP_DIRS.has(e.name)) walk(posix(path.join(dirRel, e.name)), out);
    } else if (e.name.endsWith('.php')) out.push(posix(path.join(dirRel, e.name)));
  }
  return out;
}

const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

// app/Http/Controllers/X/Y.php -> App\Http\Controllers\X
function nsOfPath(rel) {
  if (!rel.startsWith('app/')) return null;
  const parts = rel.slice(4).split('/');
  parts.pop();
  return ['App', ...parts].join('\\');
}
const classOfPath = (rel) => path.posix.basename(rel, '.php');
const fqcnOfPath = (rel) => `${nsOfPath(rel)}\\${classOfPath(rel)}`;

// buang komentar & string supaya deteksi nama telanjang gak kena false positive
function stripCode(src) {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/(^|[^:'"\\])\/\/.*$/gm, '$1')
    .replace(/^\s*#(?!\[).*$/gm, '')
    .replace(/'(?:\\.|[^'\\])*'/g, "''")
    .replace(/"(?:\\.|[^"\\])*"/g, '""');
}

const usesBareName = (code, name) => new RegExp(`(?<![\\\\\\w$])(?<!->)${esc(name)}\\b`).test(code);

function importedNames(src) {
  // nama pendek yang sudah di-use (termasuk alias)
  const names = new Map();
  for (const m of src.matchAll(/^use\s+([\w\\]+?)(?:\s+as\s+(\w+))?\s*;/gm)) {
    names.set(m[2] || m[1].split('\\').pop(), m[1]);
  }
  return names;
}

function insertUse(src, fqcn) {
  // ikutin line ending file (repo ini campur LF & CRLF) biar gak bikin EOL campur
  const eol = src.includes('\r\n') ? '\r\n' : '\n';
  const lines = src.split(eol);
  const declIdx = lines.findIndex((l) => /^(final\s+|abstract\s+|readonly\s+)*(class|trait|interface|enum)\s/.test(l));
  const limit = declIdx === -1 ? lines.length : declIdx;
  let last = -1;
  for (let i = 0; i < limit; i++) if (/^use\s+[\w\\]/i.test(lines[i])) last = i;
  if (last === -1) {
    const nsIdx = lines.findIndex((l) => /^namespace\s/.test(l));
    lines.splice(nsIdx + 1, 0, '', `use ${fqcn};`);
  } else {
    // kalau use multi-baris (group use) cari penutupnya
    let end = last;
    while (end < lines.length && !lines[end].includes(';')) end++;
    lines.splice(end + 1, 0, `use ${fqcn};`);
  }
  return lines.join(eol);
}

// ───────────────────────── bangun state ─────────────────────────

function loadFiles() {
  const files = new Map();
  for (const d of SCAN_DIRS) for (const f of walk(d)) files.set(f, fs.readFileSync(path.join(ROOT, f), 'utf8'));
  return files;
}

function plan(files) {
  const moves = MOVES.filter(([g]) => !ONLY || ONLY.includes(g)).map(([group, from, to]) => ({ group, from, to }));
  const notes = [];
  const active = [];
  for (const m of moves) {
    if (files.has(m.from)) active.push(m);
    else if (files.has(m.to)) notes.push(`skip (sudah pindah): ${m.to}`);
    else notes.push(`PERINGATAN: ${m.from} tidak ditemukan`);
  }

  // peta kelas: path-lama -> info; dipakai buat tahu siapa "tetangga" satu namespace
  const moveByFrom = new Map(active.map((m) => [m.from, m]));
  const classes = []; // semua kelas di app/
  for (const f of files.keys()) {
    if (!f.startsWith('app/')) continue;
    const m = moveByFrom.get(f);
    const to = m ? m.to : f;
    classes.push({
      name: classOfPath(f),
      oldPath: f,
      newPath: to,
      oldNs: nsOfPath(f),
      newNs: nsOfPath(to),
      oldFqcn: fqcnOfPath(f),
      newFqcn: fqcnOfPath(to),
      moved: !!m,
    });
  }

  const out = new Map(); // newPath -> {content, oldPath, stats}
  const fqcnSwaps = classes.filter((c) => c.moved);

  for (const [oldPath, original] of files) {
    const info = classes.find((c) => c.oldPath === oldPath);
    const newPath = info ? info.newPath : oldPath;
    let src = original;
    const stats = { ns: false, fqcn: 0, usesAdded: [], usesRemoved: [] };

    // 1) namespace
    if (info && info.moved) {
      const before = src;
      src = src.replace(/^namespace\s+[\w\\]+\s*;/m, `namespace ${info.newNs};`);
      stats.ns = src !== before;
    }

    // 2) ganti FQCN lama -> baru di seluruh isi file
    for (const c of fqcnSwaps) {
      const seg = c.oldFqcn.split('\\').map(esc).join('\\\\{1,2}');
      const re = new RegExp(`(?<![\\w\\\\])(\\\\{0,2})${seg}(?![\\w])`, 'g');
      src = src.replace(re, (_m, lead) => {
        stats.fqcn++;
        const dbl = _m.includes('\\\\');
        const newName = dbl ? c.newFqcn.replace(/\\/g, '\\\\') : c.newFqcn;
        return lead + newName;
      });
    }

    // 3) nama telanjang yang dulu resolve via namespace yang sama
    if (info) {
      const code = stripCode(src);
      const imported = importedNames(src);
      for (const sib of classes) {
        if (sib.oldNs !== info.oldNs || sib.name === info.name) continue; // bukan tetangga lama
        if (sib.newNs === info.newNs) continue; // sekarang tetap satu namespace -> aman
        if (imported.has(sib.name)) continue;
        if (!usesBareName(code, sib.name)) continue;
        src = insertUse(src, sib.newFqcn);
        stats.usesAdded.push(sib.newFqcn);
        imported.set(sib.name, sib.newFqcn);
      }
    }

    // 4) buang `use` redundant (kelas sekarang satu namespace dengan file ini)
    if (info && (stats.ns || stats.fqcn || stats.usesAdded.length)) {
      src = src.replace(/^use\s+([\w\\]+)\\(\w+)\s*;(?:\r?\n)?/gm, (line, ns, name) => {
        if (ns === info.newNs) {
          stats.usesRemoved.push(`${ns}\\${name}`);
          return '';
        }
        return line;
      });
    }

    out.set(newPath, { content: src, oldPath, stats, changed: src !== original || newPath !== oldPath });
  }

  // file non-app (routes/database/config/tests/bootstrap) sudah ikut di loop di atas (info == undefined)
  return { out, active, notes, classes };
}

// ───────────────────────── verifikasi ─────────────────────────

function verify(contentByPath) {
  const problems = [];
  const classIndex = new Map(); // fqcn -> path
  for (const [p, src] of contentByPath) {
    if (!p.startsWith('app/')) continue;
    const expectNs = nsOfPath(p);
    const nsMatch = src.match(/^namespace\s+([\w\\]+)\s*;/m);
    const declMatch = src.match(/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait|interface|enum)\s+(\w+)/m);
    if (nsMatch && nsMatch[1] !== expectNs) problems.push(`${p}: namespace "${nsMatch[1]}" != path "${expectNs}"`);
    if (declMatch && declMatch[1] !== classOfPath(p)) problems.push(`${p}: kelas "${declMatch[1]}" != nama file`);
    if (declMatch) classIndex.set(`${expectNs}\\${classOfPath(p)}`, p);
  }

  const mustResolve = (fqcn, where) => {
    if (!fqcn.startsWith('App\\')) return;
    if (classIndex.has(fqcn)) return;
    // boleh juga namespace murni (mis. `use App\Models;` jarang) -> cek ada kelas yang berawalan itu
    if ([...classIndex.keys()].some((k) => k.startsWith(fqcn + '\\'))) return;
    problems.push(`${where}: referensi ke ${fqcn} tidak ketemu`);
  };

  for (const [p, src] of contentByPath) {
    for (const m of src.matchAll(/^use\s+(App\\[\w\\]+?)(?:\s+as\s+\w+)?\s*;/gm)) mustResolve(m[1], p);
    for (const m of src.matchAll(/\\(App\\[A-Z][\w]*(?:\\[A-Z][\w]*)+)(?=::|\s|\)|;|,)/g)) mustResolve(m[1], p);
  }

  // nama telanjang tanpa use
  const appFiles = [...contentByPath].filter(([p]) => p.startsWith('app/'));
  const byName = new Map();
  for (const [fq, p] of classIndex) {
    const n = fq.split('\\').pop();
    (byName.get(n) || byName.set(n, []).get(n)).push({ fq, p });
  }
  for (const [p, src] of contentByPath) {
    const ns = p.startsWith('app/') ? nsOfPath(p) : null;
    const code = stripCode(src);
    const imported = importedNames(src);
    for (const mv of MOVES) {
      const name = classOfPath(mv[2]);
      if (name === classOfPath(p) || imported.has(name)) continue;
      const target = fqcnOfPath(mv[2]);
      if (!classIndex.has(target)) continue;
      if (ns === nsOfPath(mv[2])) continue;
      if (usesBareName(code, name)) problems.push(`${p}: pakai "${name}" tapi tanpa use ${target}`);
    }
  }
  void appFiles;
  return problems;
}

// ───────────────────────── main ─────────────────────────

if (!exists('artisan') || !exists('app')) {
  console.error(`Ini bukan root repo Laravel: ${ROOT} (gak ada artisan/app). Pakai --root=...`);
  process.exit(1);
}

const files = loadFiles();

if (VERIFY_ONLY) {
  const problems = verify(files);
  log(problems.length ? `✗ ${problems.length} masalah:\n` + problems.map((p) => '  - ' + p).join('\n') : '✓ Verifikasi statis OK (namespace, use, nama telanjang).');
  process.exit(problems.length ? 1 : 0);
}

// masalah yang SUDAH ada sebelum script jalan (bukan salah refactor) dicatat terpisah
const baseline = new Set(verify(files));

const { out, active, notes } = plan(files);
const changed = [...out].filter(([, v]) => v.changed);

log(`\nMode: ${APPLY ? 'APPLY' : 'DRY-RUN (tambah --apply buat eksekusi)'}   Root: ${ROOT}`);
if (ONLY) log(`Group: ${ONLY.join(', ')}`);
notes.forEach((n) => log('  ' + n));

log(`\nFile dipindah (${active.length}):`);
active.forEach((m) => log(`  ${m.from}\n    -> ${m.to}`));

log(`\nFile berubah isinya (${changed.filter(([, v]) => v.content !== files.get(v.oldPath)).length}):`);
for (const [p, v] of changed) {
  if (v.content === files.get(v.oldPath)) continue;
  const bits = [];
  if (v.stats.ns) bits.push('namespace');
  if (v.stats.fqcn) bits.push(`${v.stats.fqcn} referensi FQCN`);
  if (v.stats.usesAdded.length) bits.push(`+use ${v.stats.usesAdded.map((u) => u.split('\\').pop()).join(', ')}`);
  if (v.stats.usesRemoved.length) bits.push(`-use ${v.stats.usesRemoved.map((u) => u.split('\\').pop()).join(', ')}`);
  log(`  ${p}  [${bits.join(' · ')}]`);
}

const after = verify(new Map([...out].map(([p, v]) => [p, v.content])));
const problems = after.filter((x) => !baseline.has(x));
const preexisting = after.filter((x) => baseline.has(x));
if (preexisting.length) {
  log(`\nℹ Masalah yang SUDAH ada sebelum refactor (bukan dari script, tidak menghalangi):`);
  preexisting.forEach((x) => log('  - ' + x));
}
log(problems.length ? `\n✗ Verifikasi rencana: ${problems.length} masalah BARU\n` + problems.map((x) => '  - ' + x).join('\n') : '\n✓ Verifikasi statis atas hasil rencana: OK (gak ada masalah baru)');

if (!APPLY) {
  log('\nDry-run selesai, belum ada file yang diubah.');
  process.exit(problems.length ? 1 : 0);
}
if (problems.length) {
  console.error('\nBatal --apply karena verifikasi gagal. Benerin dulu / jalankan per --only=group.');
  process.exit(1);
}

const isGit = exists('.git');
for (const m of active) {
  const to = path.join(ROOT, m.to);
  fs.mkdirSync(path.dirname(to), { recursive: true });
  if (isGit) {
    try {
      execFileSync('git', ['mv', m.from, m.to], { cwd: ROOT, stdio: 'pipe' });
      continue;
    } catch {
      /* file belum ke-track -> fallback rename biasa */
    }
  }
  fs.renameSync(path.join(ROOT, m.from), to);
}
for (const [p, v] of out) {
  if (v.content !== files.get(v.oldPath)) fs.writeFileSync(path.join(ROOT, p), v.content);
}
// bersihin folder kosong bekas
for (const m of active) {
  let dir = path.dirname(path.join(ROOT, m.from));
  while (dir.startsWith(path.join(ROOT, 'app')) && fs.existsSync(dir) && fs.readdirSync(dir).length === 0) {
    fs.rmdirSync(dir);
    dir = path.dirname(dir);
  }
}

log(`\n✓ Selesai: ${active.length} file dipindah, ${changed.length} file diperbarui.`);
log('Lanjut:\n  composer dump-autoload\n  php artisan route:list     # jumlah route harus sama kayak sebelum refactor\n  php artisan optimize:clear\n  node refactor-be-fase4.mjs --verify');
