#!/usr/bin/env node
/**
 * check-hardcoded — fail the build when a feature class contains client content.
 *
 * WHY THIS EXISTS
 *
 * "Every core plugin needs a settings page editing everything it does; features read a
 * Config, never literals" has been a written rule for months, and on 2026-08-20 it was
 * broken again in a live client core plugin. That is the signature of a rule with no gate:
 * it is not forgotten because people are careless, it is forgotten because nothing stops it.
 *
 * So this is the gate. It reads the PHP under src/ and reports anything that looks like a
 * client's own content sitting in code — a phone number, an email address, a postcode, a
 * third-party URL. Those belong in Core\Config::schema(), where they become an editable
 * admin field for free.
 *
 * WHAT IT DELIBERATELY DOES NOT FLAG
 *
 *   - Core/Config.php itself. The schema is where values are SUPPOSED to live; flagging it
 *     would make the checker fire on the correct answer.
 *   - Comments and docblocks. This codebase explains itself at length, and examples inside
 *     an explanation are the point. Stripping comments before matching is the single
 *     biggest false-positive reduction available.
 *   - Infrastructure URLs — googletagmanager.com, schema.org, gnu.org, wordpress.org. Those
 *     are protocol endpoints, not client content, and no settings page should offer to edit
 *     where Google Tag Manager lives.
 *
 * ⚠️ Tuned once already, and it will need tuning again. A false positive here costs more
 * than a miss: the first time this reports something correct as wrong, it stops being run.
 * If it floods, tighten it the same day rather than adding a suppression.
 *
 * Usage:  node bin/check-hardcoded.mjs [--json]
 * Exit:   0 clean, 1 findings.
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(path.join(import.meta.dirname, '..'));
const SRC = path.join(ROOT, 'src');

/** Files where literals are correct by design. */
const ALLOW_FILES = new Set(['Config.php']);

/** Hosts that are infrastructure, not client content. */
const INFRA_HOSTS = [
  'googletagmanager.com', 'google-analytics.com', 'schema.org', 'gnu.org',
  'wordpress.org', 'w3.org', 'github.com', 'ajrwebdesign.com',
];

const RULES = [
  {
    id: 'EMAIL',
    why: 'An email address belongs in Config::schema() so the client can change it.',
    re: /['"][A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}['"]/g,
  },
  {
    id: 'PHONE',
    why: 'A phone number belongs in Config::schema() — providers and numbers change.',
    re: /['"][+(]?\d[\d\s().-]{8,}\d['"]/g,
  },
  {
    id: 'URL',
    why: 'A third-party URL belongs in Config::schema() unless it is infrastructure.',
    re: /['"]https?:\/\/[^'"]+['"]/g,
  },
  {
    id: 'POSTCODE',
    why: 'An address fragment belongs in Config::schema().',
    // UK-style postcode or US ZIP+4, quoted.
    re: /['"](?:[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}|\d{5}(?:-\d{4})?)['"]/g,
  },
];

/**
 * Remove comments and docblocks so prose examples cannot trip the rules.
 * Newlines are preserved so reported line numbers stay true.
 */
const stripComments = (src) =>
  src
    .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '))
    .replace(/(^|[^:])\/\/[^\n]*/g, (m, p1) => p1 + ' '.repeat(Math.max(0, m.length - p1.length)))
    .replace(/(^|\s)#[^\n]*/g, (m, p1) => p1 + ' '.repeat(Math.max(0, m.length - p1.length)));

const walk = (dir, out = []) => {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.php')) out.push(p);
  }
  return out;
};

const findings = [];
let scanned = 0;

for (const file of walk(SRC)) {
  if (ALLOW_FILES.has(path.basename(file))) continue;
  scanned++;

  const raw = fs.readFileSync(file, 'utf8');
  const code = stripComments(raw);
  const lines = code.split('\n');

  lines.forEach((line, i) => {
    for (const rule of RULES) {
      rule.re.lastIndex = 0;
      let m;
      while ((m = rule.re.exec(line)) !== null) {
        const literal = m[0];

        if (rule.id === 'URL' && INFRA_HOSTS.some((h) => literal.includes(h))) continue;
        // A bare scheme or a schema.org @context is structural, not content.
        if (rule.id === 'URL' && /^['"]https?:\/\/['"]$/.test(literal)) continue;

        findings.push({
          file: path.relative(ROOT, file).replace(/\\/g, '/'),
          line: i + 1,
          rule: rule.id,
          why: rule.why,
          literal: literal.length > 60 ? literal.slice(0, 57) + '…' : literal,
        });
      }
    }
  });
}

if (process.argv.includes('--json')) {
  console.log(JSON.stringify({ scanned, findings }, null, 1));
  process.exit(findings.length ? 1 : 0);
}

console.log(`check-hardcoded — ${scanned} file(s) scanned (Config.php excluded by design)\n`);

if (!findings.length) {
  console.log('  0 hardcoded values. Every value this plugin uses is declared in Core\\Config::schema(),');
  console.log('  which means every one of them has an admin field.');
  process.exit(0);
}

const byFile = new Map();
for (const f of findings) {
  if (!byFile.has(f.file)) byFile.set(f.file, []);
  byFile.get(f.file).push(f);
}
for (const [file, fs_] of byFile) {
  console.log(`  ${file}`);
  for (const f of fs_) console.log(`     L${f.line}  ${f.rule}  ${f.literal}\n        → ${f.why}`);
}
console.log(`\n  ${findings.length} hardcoded value(s). Move each into Core\\Config::schema() — the admin field appears automatically.`);
process.exit(1);
