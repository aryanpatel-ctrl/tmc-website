'use strict';
/**
 * Helpers for the self-contained HTML reports (no external CSS/JS, readable offline, printable,
 * WCAG-conformant themselves: headings, table captions and headers, sufficient contrast).
 */

function esc(value) {
  return String(value === undefined || value === null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

const STYLE = `
  :root { color-scheme: light; --ink: #0b0c0c; --muted: #505a5f; --line: #b1b4b6; --pass: #00703c; --fail: #d4351c; --warn: #8a4b00; --manual: #1d70b8; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", sans-serif; color: var(--ink); background: #fff; }
  main { max-width: 72rem; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
  h1 { font-size: 1.75rem; margin: 0 0 0.5rem; }
  h2 { font-size: 1.3rem; margin: 2rem 0 0.75rem; border-bottom: 2px solid var(--ink); padding-bottom: 0.25rem; }
  h3 { font-size: 1.1rem; margin: 1.5rem 0 0.5rem; }
  p.meta { color: var(--muted); margin: 0 0 1rem; }
  table { border-collapse: collapse; width: 100%; margin: 0.5rem 0 1.5rem; font-size: 0.9rem; }
  caption { text-align: left; font-weight: 700; padding: 0.25rem 0; }
  th, td { border: 1px solid var(--line); padding: 0.4rem 0.5rem; text-align: left; vertical-align: top; }
  thead th { background: #f3f2f1; }
  code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.85em; }
  pre { white-space: pre-wrap; word-break: break-word; background: #f3f2f1; padding: 0.5rem; margin: 0.25rem 0; }
  a { color: #1d4ed8; }
  a:focus-visible { outline: 3px solid #ffbf47; outline-offset: 2px; }
  .status { display: inline-block; font-weight: 700; padding: 0.05rem 0.5rem; border-radius: 3px; color: #fff; white-space: nowrap; }
  .status-PASS { background: var(--pass); }
  .status-FAIL { background: var(--fail); }
  .status-WARN { background: var(--warn); }
  .status-MANUAL, .status-PENDING, .status-SKIPPED, .status-NOT-RUN { background: var(--manual); }
  .summary { display: flex; flex-wrap: wrap; gap: 0.75rem; list-style: none; padding: 0; margin: 1rem 0; }
  .summary li { border: 1px solid var(--line); padding: 0.5rem 0.75rem; min-width: 9rem; }
  .summary strong { display: block; font-size: 1.4rem; }
  @media print { a { color: inherit; } .status { color: #000; border: 1px solid #000; background: none !important; } }
`;

/** A complete, standalone HTML document. */
function page(title, body, lang = 'en') {
  return `<!doctype html>
<html lang="${esc(lang)}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(title)}</title>
<style>${STYLE}</style>
</head>
<body>
<main>
${body}
</main>
</body>
</html>
`;
}

function status(value) {
  const label = String(value || 'NOT-RUN').toUpperCase().replace(/\s+/g, '-');
  return `<span class="status status-${esc(label)}">${esc(label.replace(/-/g, ' '))}</span>`;
}

/**
 * Table helper. columns: [{ label, cell: (row) => html }]. Cells must already be escaped.
 */
function table(caption, columns, rows, empty = 'None.') {
  if (!rows.length) {
    return `<p>${esc(empty)}</p>`;
  }
  const head = columns.map((column) => `<th scope="col">${esc(column.label)}</th>`).join('');
  const body = rows
    .map((row) => `<tr>${columns.map((column) => `<td>${column.cell(row)}</td>`).join('')}</tr>`)
    .join('\n');
  return `<table><caption>${esc(caption)}</caption><thead><tr>${head}</tr></thead><tbody>\n${body}\n</tbody></table>`;
}

/** CSV with RFC 4180 quoting; cells that could be read as spreadsheet formulas are neutralised. */
function csv(header, rows) {
  const cell = (value) => {
    let text = String(value === undefined || value === null ? '' : value);
    if (/^[=+\-@\t\r]/.test(text)) {
      text = `'${text}`;
    }
    return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
  };
  return [header, ...rows].map((row) => row.map(cell).join(',')).join('\r\n') + '\r\n';
}

module.exports = { csv, esc, page, status, table };
