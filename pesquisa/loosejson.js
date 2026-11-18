// JSON.parse tolerante: escapa aspas soltas dentro de textos (ex.: determina "Este produto..." sem \").
module.exports = function looseJson(src) {
  try { return JSON.parse(src); } catch (e) { /* conserta abaixo */ }
  let out = ''; let inStr = false;
  for (let i = 0; i < src.length; i++) {
    const ch = src[i];
    if (inStr && ch === '\\') { out += ch + (src[i + 1] ?? ''); i++; continue; }
    if (ch === '"') {
      if (!inStr) { inStr = true; out += ch; continue; }
      // só fecha o texto se depois vier estrutura de JSON: "a": / "a", "b" / "a" } / fim de linha
      const rest = src.slice(i + 1, i + 200);
      const closes = /^[ \t]*[:\]}]/.test(rest) || /^[ \t]*,\s*["{[\]}\d-tfn]/.test(rest) || /^[ \t]*,?[ \t]*(\r?\n|$)/.test(rest);
      if (closes) { inStr = false; out += ch; } else { out += '\\"'; }
      continue;
    }
    if (inStr && ch === '\n') { out += '\\n'; continue; }
    if (inStr && (ch === '\r' || ch === '\t')) { out += ' '; continue; }
    out += ch;
  }
  return JSON.parse(out);
};
