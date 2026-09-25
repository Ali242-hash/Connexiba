/**
 * build-i18n.js
 *
 * Dev-time static site generator for Connexiba's multilingual homepage.
 * NOT deployed / NOT served to visitors — this is run locally to produce
 * fully self-contained, pre-translated static HTML files:
 *   /en/index.html  /de/index.html  /fr/index.html
 *   /es/index.html  /it/index.html  /hu/index.html
 * plus one static Terms & Conditions page per language:
 *   /en/terms.html  /de/terms.html  /fr/terms.html
 *   /es/terms.html  /it/terms.html  /hu/terms.html
 * plus a root /index.html that redirects to /en/.
 *
 * Each output file is 100% static HTML/CSS/JS for its language only —
 * no client-side translation, no shared JS bundle of all languages,
 * no runtime API call. A visitor to /de/ downloads ONLY German content.
 *
 * Usage:  node build-i18n.js
 */
const fs = require('fs');
const path = require('path');

const ROOT = __dirname;
const SOURCE_HTML = path.join(ROOT, 'template.html');
const TERMS_SOURCE_HTML = path.join(ROOT, 'terms.template.html');
const LOCALES_DIR = path.join(ROOT, 'locales');
const SITE_URL = 'https://connexiba.com';

const LANGS = [
  { code: 'en', name: 'English', ogLocale: 'en_US' },
  { code: 'de', name: 'Deutsch', ogLocale: 'de_DE' },
  { code: 'fr', name: 'Français', ogLocale: 'fr_FR' },
  { code: 'es', name: 'Español', ogLocale: 'es_ES' },
  { code: 'it', name: 'Italiano', ogLocale: 'it_IT' },
  { code: 'hu', name: 'Magyar', ogLocale: 'hu_HU' },
];
const DEFAULT_LANG = 'en';

// ---------------------------------------------------------------------
// 1. Load locale data
// ---------------------------------------------------------------------
function loadLocale(code, page) {
  const p = path.join(LOCALES_DIR, code, (page || 'home') + '.json');
  if (!fs.existsSync(p)) throw new Error('Missing locale file: ' + p);
  return JSON.parse(fs.readFileSync(p, 'utf8'));
}

const enData = loadLocale('en');

// ---------------------------------------------------------------------
// 2. Walk two parallel JSON trees (en + target) and collect
//    [englishString, translatedString] replacement pairs.
// ---------------------------------------------------------------------
function collectPairs(enNode, targetNode, pairs) {
  if (typeof enNode === 'string') {
    if (typeof targetNode !== 'string') {
      throw new Error('Structure mismatch: expected string, got ' + typeof targetNode + ' for EN value "' + enNode + '"');
    }
    pairs.push([enNode, targetNode]);
    return;
  }
  if (Array.isArray(enNode)) {
    if (!Array.isArray(targetNode) || targetNode.length !== enNode.length) {
      throw new Error('Array length mismatch for: ' + JSON.stringify(enNode));
    }
    enNode.forEach((v, i) => collectPairs(v, targetNode[i], pairs));
    return;
  }
  if (enNode && typeof enNode === 'object') {
    Object.keys(enNode).forEach((k) => {
      if (k === 'htmlLang') return; // handled separately
      if (!(k in targetNode)) throw new Error('Missing key "' + k + '" in target locale');
      collectPairs(enNode[k], targetNode[k], pairs);
    });
  }
}

// ---------------------------------------------------------------------
// 3. Build the <head> language-alternate / SEO block for a given lang
// ---------------------------------------------------------------------
function buildSeoHead(lang, t, page) {
  const suffix = page || ''; // '' = homepage ("/en/"), or e.g. "terms.html"
  const canonical = `${SITE_URL}/${lang.code}/${suffix}`;
  const hreflangLinks = LANGS.map(
    (l) => `<link rel="alternate" hreflang="${l.code}" href="${SITE_URL}/${l.code}/${suffix}">`
  ).join('\n');
  const xDefault = `<link rel="alternate" hreflang="x-default" href="${SITE_URL}/${DEFAULT_LANG}/${suffix}">`;
  return [
    `<link rel="canonical" href="${canonical}">`,
    hreflangLinks,
    xDefault,
    `<meta property="og:type" content="website">`,
    `<meta property="og:site_name" content="Connexiba">`,
    `<meta property="og:locale" content="${lang.ogLocale}">`,
    ...LANGS.filter((l) => l.code !== lang.code).map(
      (l) => `<meta property="og:locale:alternate" content="${l.ogLocale}">`
    ),
    `<meta property="og:url" content="${canonical}">`,
    `<meta property="og:title" content="${escapeAttr(t.meta.ogTitle)}">`,
    `<meta property="og:description" content="${escapeAttr(t.meta.ogDescription)}">`,
  ].join('\n');
}

function escapeAttr(s) {
  return String(s).replace(/&(?!amp;|lt;|gt;|quot;|#39;)/g, '&amp;').replace(/"/g, '&quot;');
}

// ---------------------------------------------------------------------
// 4. Build the language switcher (zero-JS, native <details>/<summary>)
// ---------------------------------------------------------------------
function buildLangSwitcher(currentCode, targetFile) {
  const file = targetFile || 'index.html';
  const current = LANGS.find((l) => l.code === currentCode);
  // Exclude the current language from the dropdown entirely — it's
  // already shown as the trigger itself, so listing it again inside
  // .lang-panel produced a visible duplicate (e.g. "English" as the
  // trigger AND again as the first dropdown row).
  const items = LANGS.filter((l) => l.code !== currentCode).map((l) => {
    // Every language page lives one folder below the site root
    // (e.g. /en/index.html), and all language folders are siblings, so
    // "../{code}/index.html" is the correct relative path FROM any of
    // them TO any other. Unlike an absolute "/de/" path, this resolves
    // correctly both once deployed AND when opening the files directly
    // via file:// (an absolute path would resolve against the local
    // drive root in that case and fail to open). targetFile lets the
    // Terms page switcher land on the other language's terms.html
    // instead of its index.html.
    return `<a href="../${l.code}/${file}" hreflang="${l.code}" lang="${l.code}">${l.code.toUpperCase()}</a>`;
  }).join('');
  // Trigger and dropdown rows show the short two-letter code only
  // (EN, DE, FR, ES, IT, HU); the full language name is kept as an
  // aria-label for screen readers, not shown visually.
  return `<details class="lang-switcher" id="langSwitcher"><summary aria-label="Language: ${current.name}. Change language">${current.code.toUpperCase()}</summary><div class="lang-panel" role="menu">${items}</div></details>`;
}

// ---------------------------------------------------------------------
// 5. Generate one language's HTML
// ---------------------------------------------------------------------
function buildLanguage(lang, rawSource) {
  const t = loadLocale(lang.code);

  // --- text/content substitution ---
  const pairs = [];
  collectPairs(enData, t, pairs);

  // (The current template's hero <h1> is one plain sentence — no <em>
  // split — so collectPairs()'s generic string walk above handles it
  // with no special-casing needed. An earlier template version composed
  // the <h1> from separate h1Before/h1Em/h1After fragments and needed a
  // synthetic pair built here for that; if a future redesign reintroduces
  // an <em>-split headline, rebuild that composition here rather than
  // assuming hero.h1Em/h1After exist.)
  // De-dupe identical EN source strings (same phrase used in multiple
  // places must map to the same translation everywhere).
  const seen = new Map();
  pairs.forEach(([en, tr]) => {
    if (seen.has(en) && seen.get(en) !== tr) {
      console.warn(`  [warn] "${en}" maps to two different translations; using the first one seen.`);
      return;
    }
    seen.set(en, tr);
  });
  const uniquePairs = Array.from(seen.entries()).sort((a, b) => b[0].length - a[0].length);

  // The <script> block embeds ~15 of these strings inside JS string
  // literals (mostly single-quoted). A translation containing an
  // apostrophe (very common in French/Italian/Spanish) would otherwise
  // break the JS syntax, so the script region gets a JS-escaped version
  // of each replacement; the rest of the page (plain HTML text/attributes)
  // gets the replacement as-is.
  const scriptOpen = rawSource.indexOf('<script>');
  const scriptClose = rawSource.indexOf('</script>', scriptOpen) + '</script>'.length;
  if (scriptOpen === -1 || scriptClose === -1) throw new Error('Could not locate <script> block in template');

  const before = rawSource.slice(0, scriptOpen);
  const scriptBlock = rawSource.slice(scriptOpen, scriptClose);
  const after = rawSource.slice(scriptClose);

  function jsEscape(s) {
    return s.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  }
  function reEscape(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  // Single combined regex (longest-alternative-first, via sorted uniquePairs)
  // so every position in the text is matched against the LONGEST possible
  // source string exactly once. This avoids the classic cascading-substring
  // bug where translating "Engage" -> "Engager" via sequential split/join
  // would also corrupt an already-present (or not-yet-touched) "Engagement"
  // into "Engagerment".
  const lookup = new Map(uniquePairs);
  const pattern = new RegExp(uniquePairs.map(([en]) => reEscape(en)).join('|'), 'g');

  function substitute(text, escapeFn) {
    return text.replace(pattern, (match) => {
      const tr = lookup.get(match);
      return escapeFn ? escapeFn(tr) : tr;
    });
  }

  let html = substitute(before, null) + substitute(scriptBlock, jsEscape) + substitute(after, null);

  // --- <html lang="en"> swap ---
  html = html.replace(/<html lang="en">/, `<html lang="${lang.code}">`);

  // --- asset paths: template's "assets/..." -> "../assets/..." since each
  //     language page now lives one directory level below the site root
  //     (e.g. /en/index.html). A relative "../" path resolves correctly
  //     both in a real deployment AND when opening the file directly
  //     (file:///.../en/index.html) — an absolute "/assets/..." path would
  //     resolve against the local drive root in the file:// case and break.
  html = html.replace(/(?:src|href)="assets\//g, (m) => m.replace('assets/', '../assets/'));
  // Same rewrite for the one CSS reference to an asset (the .logo-c-tint
  // mask-image, which reuses the wordmark PNG as a pure alpha mask) —
  // it's a url(assets/...) inside the <style> block, not a src/href
  // attribute, so the rule above doesn't touch it.
  html = html.replace(/url\(assets\//g, 'url(../assets/');

  // --- localize the "connexiba.com" homepage link in Contact section ---
  html = html.replace(
    '<a href="https://connexiba.com" target="_blank" rel="noopener">connexiba.com</a>',
    `<a href="${SITE_URL}/${lang.code}/" target="_blank" rel="noopener">connexiba.com</a>`
  );

  // --- inject SEO head block right after the meta description tag ---
  const seoHead = buildSeoHead(lang, t);
  html = html.replace(
    /(<meta name="description"[^>]*>\n)/,
    `$1${seoHead}\n`
  );

  // --- inject language switcher into the nav, right after the Contact Us
  //     button (desktop: CTA then language, left to right; the navcta div
  //     itself is matched by its stable href/class attributes rather than
  //     its — already-translated by this point — link text, and is left
  //     completely untouched, per the i18n contract at the top of
  //     template.html). ---
  const switcherHtml = buildLangSwitcher(lang.code);
  html = html.replace(
    /(<div class="navcta"><a href="#contact" class="btn btn-solid">[^<]*<\/a><\/div>)/,
    `$1\n        ${switcherHtml}`
  );

  return html;
}

// ---------------------------------------------------------------------
// 5b. Generate one language's Terms & Conditions page
// ---------------------------------------------------------------------
function buildTermsLanguage(lang, rawTermsSource, termsEnData) {
  const t = loadLocale(lang.code, 'terms');

  const pairs = [];
  collectPairs(termsEnData, t, pairs);

  const seen = new Map();
  pairs.forEach(([en, tr]) => {
    if (seen.has(en) && seen.get(en) !== tr) {
      console.warn(`  [warn][terms] "${en}" maps to two different translations; using the first one seen.`);
      return;
    }
    seen.set(en, tr);
  });
  const uniquePairs = Array.from(seen.entries()).sort((a, b) => b[0].length - a[0].length);

  // Same script/non-script split + JS-escaping discipline as buildLanguage,
  // kept for consistency even though the terms page's own script block is
  // expected to be free of translated string literals.
  const scriptOpen = rawTermsSource.indexOf('<script>');
  const scriptClose = rawTermsSource.indexOf('</script>', scriptOpen) + '</script>'.length;
  if (scriptOpen === -1 || scriptClose === -1) throw new Error('Could not locate <script> block in terms template');

  const before = rawTermsSource.slice(0, scriptOpen);
  const scriptBlock = rawTermsSource.slice(scriptOpen, scriptClose);
  const after = rawTermsSource.slice(scriptClose);

  function jsEscape(s) { return s.replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }
  function reEscape(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

  const lookup = new Map(uniquePairs);
  const pattern = new RegExp(uniquePairs.map(([en]) => reEscape(en)).join('|'), 'g');
  function substitute(text, escapeFn) {
    return text.replace(pattern, (match) => {
      const tr = lookup.get(match);
      return escapeFn ? escapeFn(tr) : tr;
    });
  }

  let html = substitute(before, null) + substitute(scriptBlock, jsEscape) + substitute(after, null);

  html = html.replace(/<html lang="en">/, `<html lang="${lang.code}">`);
  html = html.replace(/(?:src|href)="assets\//g, (m) => m.replace('assets/', '../assets/'));
  html = html.replace(/url\(assets\//g, 'url(../assets/');

  const seoHead = buildSeoHead(lang, t, 'terms.html');
  html = html.replace(/(<meta name="description"[^>]*>\n)/, `$1${seoHead}\n`);

  // Terms page's CTA links back to the homepage contact section
  // (index.html#contact), not a same-page anchor, so it needs its own
  // fixed-anchor match rather than reusing buildLanguage's "#contact" one.
  const switcherHtml = buildLangSwitcher(lang.code, 'terms.html');
  html = html.replace(
    /(<div class="navcta"><a href="index\.html#contact" class="btn btn-solid">[^<]*<\/a><\/div>)/,
    `$1\n        ${switcherHtml}`
  );

  return html;
}

// ---------------------------------------------------------------------
// 6. Run
// ---------------------------------------------------------------------
const rawSource = fs.readFileSync(SOURCE_HTML, 'utf8');
const report = [];

LANGS.forEach((lang) => {
  const outDir = path.join(ROOT, lang.code);
  fs.mkdirSync(outDir, { recursive: true });
  const outFile = path.join(outDir, 'index.html');
  const html = buildLanguage(lang, rawSource);
  fs.writeFileSync(outFile, html, 'utf8');
  report.push({ lang: lang.code, bytes: Buffer.byteLength(html, 'utf8'), file: outFile });
  console.log(`Built /${lang.code}/index.html (${(Buffer.byteLength(html, 'utf8') / 1024).toFixed(1)} KB)`);
});

// --- Terms & Conditions pages (only if the terms template/locale files exist) ---
if (fs.existsSync(TERMS_SOURCE_HTML)) {
  const rawTermsSource = fs.readFileSync(TERMS_SOURCE_HTML, 'utf8');
  const termsEnData = loadLocale('en', 'terms');
  LANGS.forEach((lang) => {
    const outDir = path.join(ROOT, lang.code);
    fs.mkdirSync(outDir, { recursive: true });
    const outFile = path.join(outDir, 'terms.html');
    const html = buildTermsLanguage(lang, rawTermsSource, termsEnData);
    fs.writeFileSync(outFile, html, 'utf8');
    report.push({ lang: lang.code, page: 'terms', bytes: Buffer.byteLength(html, 'utf8'), file: outFile });
    console.log(`Built /${lang.code}/terms.html (${(Buffer.byteLength(html, 'utf8') / 1024).toFixed(1)} KB)`);
  });
}

// --- root redirect page (no JS required, works with or without .htaccess) ---
const rootHtml = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="0; url=en/index.html">
<link rel="canonical" href="${SITE_URL}/en/">
<title>Connexiba</title>
<meta name="robots" content="noindex">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16x16.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="48x48" href="assets/favicon-48x48.png">
<link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">
${LANGS.map((l) => `<link rel="alternate" hreflang="${l.code}" href="${SITE_URL}/${l.code}/">`).join('\n')}
<link rel="alternate" hreflang="x-default" href="${SITE_URL}/en/">
</head>
<body>
<p>Redirecting to <a href="en/index.html">connexiba.com/en/</a>&hellip;</p>
</body>
</html>
`;
fs.writeFileSync(path.join(ROOT, 'index.html'), rootHtml, 'utf8');
console.log('Built root redirect -> /index.html (redirects to /en/, with hreflang alternates)');

fs.writeFileSync(path.join(ROOT, 'build-report.json'), JSON.stringify(report, null, 2));
console.log('\nDone. ' + report.length + ' language pages generated.');
