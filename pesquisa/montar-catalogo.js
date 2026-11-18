// Monta o catálogo da ForFit: 10 produtos de cada marca por segmento, na ordem de popularidade dos sites oficiais.
// Regra: avulsos em estoque primeiro → kits/packs do próprio segmento → kits da marca ligados ao segmento → avulsos esgotados.
// Saída: catalogo-forfit.json (lido pelo comando php artisan forfit:catalogo).
const fs = require('fs');
const parseNuxt = require('./nuxtparse');
const looseJson = require('./loosejson');

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const SEGMENTS = [
  ['creatina', 'Creatina', /creatina/i],
  ['whey', 'Whey Protein', /whey/i],
  ['pretreino', 'Pré-treino', /h[oó]rus|[eé]gide|pr[eé][- ]?treino|night train|haze/i],
  ['aminoacidos', 'Aminoácidos', /glutamina|bcaa|beta[- ]?alanina|col[aá]geno|amino/i],
  ['termogenicos', 'Termogênicos', /2hot|shot dry|fire|thermo|termo|carnitin|queima|seca/i],
  ['vitaminas', 'Vitaminas e Saúde', /vitamina|[oô]mega|zma|multi|imunidade|magn[eé]sio|dormir/i],
  ['massa', 'Hipercalórico e Carboidratos', /mass|gainer|hiper|carbo|palatinose/i],
  ['snacks', 'Barras e Snacks', /\bbar\b|protein bar|crisp|wafer|pasta de amendoim|snack/i],
];
const KIT = /(^|\s)(kit|pack|combo)\b/i;
const EXCL = {
  aminoacidos: /creatin|whey/i,
  termogenicos: /whey|creatina|bebida l[aá]ctea|col[aá]geno|dose /i,
  vitaminas: /creatina/i,
  massa: /protein|panqueca|ervilha|rice|soy/i,
  pretreino: /waxy|palatinose|maltodextrina/i,
};
const FLAVORS = /\s+(sabor\s.*|chocolate.*|morango|baunilha|natural|lim[aã]o|laranja|tangerina|caramelo|frutas vermelhas|a[cç]a[ií].*|limonada.*|pitaya.*|tropical|cappuccino|tradicional|jabuticaba.*|mel[aã]o.*|abacaxi.*|melancia|uva.*|ma[cç][aã].*|cacau.*|amendoim.*|cookies.*|doce de leite|banana|beijinho|brigadeiro|brownie|bacon|requeij[aã]o)$/i;
const norm = (s) => s.toLowerCase().replace('(top)', '').replace(/\s+-\s+display.*$/, '').replace(FLAVORS, '').replace(/\s+/g, ' ').trim();

const growthLists = JSON.parse(fs.readFileSync('growth-top.json', 'utf8'));
const maxLists = JSON.parse(fs.readFileSync('max-top.json', 'utf8'));
const maxKits = JSON.parse(fs.readFileSync('max-kits.json', 'utf8'));
const growthKits = Object.values(growthLists).flat().filter((p) => KIT.test(p.name));

function candidates(list, kitsPool, seg, re) {
  const ex = (p) => !(EXCL[seg] && EXCL[seg].test(p.name));
  return [
    ...list.filter((p) => !KIT.test(p.name) && p.avail !== false && ex(p)).map((p) => ({ ...p, origem: 'mais vendido' })),
    ...list.filter((p) => KIT.test(p.name) && p.avail !== false && ex(p)).map((p) => ({ ...p, origem: 'kit do segmento' })),
    ...kitsPool.filter((p) => re.test(p.name) && p.avail !== false && ex(p)).map((p) => ({ ...p, origem: 'kit da marca' })),
    ...list.filter((p) => !KIT.test(p.name) && p.avail === false && ex(p)).map((p) => ({ ...p, origem: 'esgotado no site da marca' })),
  ];
}
async function fill(cands, detail, used) {
  const out = [];
  for (const p of cands) {
    if (out.length >= 10) break;
    if (used.links.has(p.link) || used.names.has(norm(p.name))) continue;
    // falha de rede não pode "pular" para o próximo do ranking: para tudo e avisa
    const d = await detail(p);
    // Growth: cada sabor é uma página (id de produto) — irmãos repetidos são o mesmo produto. Max: só o productId.
    const ids = (d.brand === 'Max Titanium' ? [d.source_id] : [d.source_id, ...d.variants.map((v) => v.source_sku)]).filter(Boolean).map((x) => d.brand + ':' + x);
    const tkey = d.brand + ':' + norm(d.title);
    used.links.add(p.link); used.names.add(norm(p.name));
    if (ids.some((x) => used.ids.has(x)) || used.titles.has(tkey)) continue;
    ids.forEach((x) => used.ids.add(x)); used.titles.add(tkey);
    out.push({ rank: out.length + 1, origem: p.origem, ...d });
  }
  return out;
}

// páginas já baixadas ficam em cache/ (cada rodada só busca o que falta; apague a pasta para atualizar preços)
const CACHE = require('path').join(__dirname, 'cache');
fs.mkdirSync(CACHE, { recursive: true });
let session = '';
async function get(url, jar) {
  const key = require('path').join(CACHE, require('crypto').createHash('md5').update(url).digest('hex') + '.txt');
  if (fs.existsSync(key)) return { text: fs.readFileSync(key, 'utf8'), cookie: null };
  // educado com os sites: pausa entre páginas e espera crescente quando recusam
  await new Promise((r) => setTimeout(r, 800));
  for (let i = 0; i < 6; i++) {
    try {
      const cookie = url.includes('gsuplementos') ? (session || jar || '') : '';
      const r = await fetch(url, { headers: { 'User-Agent': UA, 'Accept-Language': 'pt-BR', Cookie: cookie }, signal: AbortSignal.timeout(30000) });
      if (r.ok) {
        const text = await r.text();
        // página de produto da Growth sem dados = sessão vencida: renova e tenta de novo
        if (url.includes('gsuplementos') && /-p\d+$|^https:\/\/www\.gsuplementos\.com\.br\/[a-z0-9-]+$/.test(url) && !text.includes('descricoes')) throw new Error('sem sessão');
        fs.writeFileSync(key, text);
        return { text, cookie: r.headers.get('set-cookie') };
      }
    } catch (e) { /* tenta de novo */ }
    if (url.includes('gsuplementos')) {
      try { const h = await fetch('https://www.gsuplementos.com.br/', { headers: { 'User-Agent': UA } }); session = (h.headers.get('set-cookie') || '').split(';')[0]; } catch (e) { /* segue */ }
    }
    await new Promise((r) => setTimeout(r, 4000 * (i + 1)));
  }
  throw new Error('Falhou: ' + url);
}

const esc = (x) => x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
// "Hiper Mass 1Kg Sabor Chocolate" → "Hiper Mass 1Kg" quando o sabor vira opção do produto
function cleanTitle(t, options) {
  for (const v of options[0]?.values || []) t = t.replace(new RegExp('\\s+(sabor\\s+)?' + esc(v) + '(?=\\s|$)', 'i'), '');
  return t.replace(/\s+sabor$/i, '').replace(/\s{2,}/g, ' ').trim();
}
const grams = (s) => { const m = s.match(/(\d+(?:[.,]\d+)?)\s*(kg|g)\b/i); if (!m) return 0; const v = parseFloat(m[1].replace(',', '.')); return Math.round(/kg/i.test(m[2]) ? v * 1000 : v); };

async function growthDetail(p, jar) {
  const { text } = await get(p.link, jar);
  const N = parseNuxt(text);
  const c = N?.state?.detalhe?.dataProd?.conteudo;
  if (!c || !c.nome) throw new Error('Sem dados: ' + p.link);
  const img = (m) => (m?.imagens || []).map((i) => i.arquivos?.zoom || i.arquivos?.big).filter(Boolean);
  const attr = c.atributos?.unico;
  const simples = c.atributos?.simples;
  let options = []; let variants = [];
  if (simples && /sabor/i.test(simples.nome) && simples.valores?.length > 1) {
    // sabores do mesmo produto (cada um com SKU, estoque e preço próprios; fotos ligadas pelo idAtributoValor)
    const fotos = c.midias?.imagens || [];
    options = [{ name: 'Sabor', values: simples.valores.map((v) => v.label) }];
    variants = simples.valores.map((v) => ({
      title: v.label, option1: v.label, available: (v.estoque || 0) > 0, source_sku: 's' + v.sku,
      price: Math.round((v.precos?.por || 0) * 100), compare_at: v.precos?.de > v.precos?.por ? Math.round(v.precos.de * 100) : null,
      images: fotos.filter((f) => f.idAtributoValor === v.idAtributoValor).map((f) => f.arquivos?.zoom || f.arquivos?.big).filter(Boolean).slice(0, 1),
    }));
  } else if (attr && /sabor/i.test(attr.nome) && attr.valores?.length > 1) {
    options = [{ name: 'Sabor', values: attr.valores.map((v) => v.label) }];
    variants = attr.valores.map((v) => ({ title: v.label, option1: v.label, available: v.produto?.status === 'disponivel', images: img(v.produto?.midias).slice(0, 1), source_sku: String(v.produto?.id || '') }));
  } else {
    variants = [{ title: 'Padrão', option1: null, available: c.status === 'disponivel', images: [], source_sku: String(c.id) }];
  }
  // tabela nutricional e modo de uso ficam no bloco [VUEJSON] do layout da página
  let nutrition = null; let usage = null; const highlights = []; const info = []; const faq = [];
  const js = c.layoutPersonalizado?.codigoJs || '';
  const jsonStart = js.indexOf('[VUEJSON]');
  if (jsonStart >= 0) {
    try {
      const blocks = looseJson(js.slice(jsonStart + 9).trim());
      const strip = (h) => String(h || '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
      const walk = (o) => {
        if (!o || typeof o !== 'object') return;
        if (!nutrition && o.porcao && Array.isArray(o.valores)) nutrition = { porcao: o.porcao, porcoes: o.porcaoPorEmbalagem || null, texto_cima: o.textoCima || null, linhas: o.valores.map((v) => v.info), rodape: o.textoBaixo || null };
        if (typeof o.text === 'string' && typeof o.subText === 'string' && highlights.length < 6) highlights.push({ titulo: strip(o.text), texto: strip(o.subText) });
        if (typeof o.pergunta === 'string' && typeof o.resposta === 'string' && faq.length < 8) faq.push({ pergunta: strip(o.pergunta), resposta: strip(o.resposta) });
        if (Array.isArray(o) && !info.length && o.some((x) => typeof x === 'string' && /<b>\s*(INGREDIENTES|RECOMENDA)/i.test(x))) o.filter((x) => typeof x === 'string').forEach((x) => info.push(x.replace(/<(?!\/?b>)[^>]+>/g, '').trim()));
        if (!usage && typeof o.sugestaoUso === 'string') usage = o.sugestaoUso;
        Object.values(o).forEach(walk);
      };
      walk(blocks);
    } catch (e) { console.error('layout ilegível:', p.link, e.message.slice(0, 80)); }
  }
  const caracteristicas = (c.caracteristicas || []).filter((g) => g.visivelSite !== false).map((g) => ({ grupo: g.nome, itens: (g.itens || []).map((i) => (i.valor === 'true' ? i.label : `${i.label}: ${i.valor}`)) }));
  return {
    brand: 'Growth Supplements', title: cleanTitle(c.nome.replace(/^\(TOP\)\s*/i, ''), options), source_url: p.link, source_id: String(c.id), sku: c.sku,
    price: Math.round((c.precos?.por || 0) * 100), compare_at: c.precos?.de > c.precos?.por ? Math.round(c.precos.de * 100) : null,
    images: img(c.midias), options, variants, caracteristicas, nutrition, usage, highlights, info, faq,
    weight_grams: grams(c.nome) || 500,
  };
}

async function maxDetail(p) {
  const { text } = await get(`https://www.maxtitanium.com.br/api/catalog_system/pub/products/search?fq=productId:${p.id}`);
  const d = JSON.parse(text)[0];
  if (!d) throw new Error('Sem dados Max: ' + p.link);
  const items = d.items || [];
  const offer = (i) => i.sellers?.[0]?.commertialOffer || {};
  const flavors = items.map((i) => (i.variations?.length ? (i[i.variations[0]] || [i.name])[0] : i.name));
  const multi = items.length > 1;
  const variants = items.map((i, k) => ({ title: multi ? flavors[k] : 'Padrão', option1: multi ? flavors[k] : null, available: offer(i).AvailableQuantity > 0, images: (i.images || []).slice(0, 1).map((x) => x.imageUrl), source_sku: i.itemId, price: Math.round((offer(i).Price || 0) * 100), compare_at: offer(i).ListPrice > offer(i).Price ? Math.round(offer(i).ListPrice * 100) : null }));
  const specs = {};
  (d.allSpecifications || []).forEach((k) => { specs[k] = (d[k] || []).join(' '); });
  const prices = variants.map((v) => v.price).filter((x) => x > 0);
  return {
    brand: 'Max Titanium', title: d.productName.replace(/\s+[—-]\s+Max Titanium.*$/i, '').replace(/\s*\|\s*Max Titanium\s*$/i, '').trim(), source_url: p.link, source_id: String(d.productId), sku: d.productReference || null,
    price: prices.length ? Math.min(...prices) : 0, compare_at: Math.max(0, ...variants.map((v) => v.compare_at || 0)) || null,
    images: [...new Set(items.flatMap((i) => (i.images || []).map((x) => x.imageUrl)))].slice(0, 8), options: multi ? [{ name: 'Sabor', values: flavors }] : [], variants,
    description_html: d.description || null, specs, weight_grams: grams(d.productName) || 500,
  };
}

(async () => {
  // sessão da Growth (sem ela a página do produto vem sem os dados)
  const jar = ((await fetch('https://www.gsuplementos.com.br/', { headers: { 'User-Agent': UA } })).headers.get('set-cookie') || '').split(';')[0];
  session = jar;
  const used = { links: new Set(), names: new Set(), ids: new Set(), titles: new Set() }; const catalog = { gerado_em: new Date().toISOString(), segmentos: [] };
  for (const [key, name, re] of SEGMENTS) {
    const seg = { key, name };
    seg.growth = await fill(candidates(growthLists[key] || [], growthKits, key, re), (p) => growthDetail(p, jar), used);
    seg.max = await fill(candidates(maxLists[key] || [], maxKits, key, re), maxDetail, used);
    console.log(`${name}: Growth ${seg.growth.length} | Max ${seg.max.length}`);
    catalog.segmentos.push(seg);
  }
  fs.writeFileSync('catalogo-forfit.json', JSON.stringify(catalog, null, 1));
})();
