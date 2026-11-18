// Gera a identidade visual da ForFit: logos, símbolo, favicon, ícones, imagem de compartilhamento e banners.
// Uso: node gerar-marca.js   (precisa do Google Chrome instalado; fontes em ./fontes, fotos em ./fonte-imagens)
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe'].find(fs.existsSync);
const ROOT = __dirname;
const OUT = path.join(ROOT, 'arquivos');
const TMP = path.join(ROOT, '.render');
fs.mkdirSync(path.join(OUT, 'banners'), { recursive: true });
fs.mkdirSync(TMP, { recursive: true });

const C = { azul: '#0A4FD6', azulClaro: '#1E7BFF', marinho: '#06173D', laranja: '#FF6A13', laranjaClaro: '#FF8A3D', gelo: '#F2F6FC' };
const url = (p) => 'file:///' + path.join(ROOT, p).replace(/\\/g, '/');
const FONTS = `
@font-face{font-family:Saira;src:url('${url('fontes/Saira[wdth,wght].ttf')}');font-weight:100 900;font-stretch:50% 125%}
@font-face{font-family:Saira;font-style:italic;src:url('${url('fontes/Saira-Italic[wdth,wght].ttf')}');font-weight:100 900;font-stretch:50% 125%}
@font-face{font-family:Montserrat;src:url('${url('fontes/Montserrat[wght].ttf')}');font-weight:100 900}
*{margin:0;padding:0;box-sizing:border-box}html,body{background:transparent;overflow:hidden}`;

function render(name, w, h, body, css = '', transparent = true) {
  const file = path.join(TMP, name.replace(/[\\/]/g, '_') + '.html');
  fs.writeFileSync(file, `<!doctype html><html><head><meta charset="utf-8"><style>${FONTS}body{width:${w}px;height:${h}px}${css}</style></head><body>${body}</body></html>`);
  const out = path.join(OUT, name + '.png');
  execFileSync(CHROME, ['--headless=new', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1', `--window-size=${w},${h}`,
    ...(transparent ? ['--default-background-color=00000000'] : []), '--virtual-time-budget=3000', `--screenshot=${out}`, 'file:///' + file.replace(/\\/g, '/')], { stdio: 'ignore' });
  console.log('✓', path.relative(ROOT, out), `${w}×${h}`);
  return out;
}

// ---------- Símbolo: "4" itálico com faixa laranja de velocidade ----------
const simbolo = (size, { fundo = true, raio = 0.22 } = {}) => `
<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 100 100">
  <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${C.azulClaro}"/><stop offset="1" stop-color="${C.azul}"/></linearGradient></defs>
  ${fundo ? `<rect width="100" height="100" rx="${100 * raio}" fill="url(#g)"/>` : ''}
  <path d="M30 70 L76 70 L71 80 L25 80 Z" fill="${C.laranja}" transform="skewX(-12) translate(10 0)"/>
  <text x="52" y="69" text-anchor="middle" font-family="Saira" font-style="italic" font-weight="900" font-stretch="100%" font-size="78" fill="${fundo ? '#fff' : C.azul}">4</text>
</svg>`;

// ---------- Logotipo horizontal: símbolo + FORFIT ----------
const logo = ({ escuro = false, h = 300, tagline = true } = {}) => {
  const cor = escuro ? '#fff' : C.marinho;
  return `<div style="display:flex;align-items:center;gap:${h * 0.09}px;height:${h}px;padding:0 ${h * 0.06}px">
    ${simbolo(h * 0.86)}
    <div style="display:flex;flex-direction:column;justify-content:center;line-height:.86">
      <div style="font-family:Saira;font-style:italic;font-weight:900;font-stretch:96%;font-size:${h * 0.62}px;letter-spacing:-.01em;color:${cor}">FOR<span style="color:${C.laranja}">FIT</span></div>
      ${tagline ? `<div style="font-family:Montserrat;font-weight:700;font-size:${h * 0.105}px;letter-spacing:.32em;color:${escuro ? 'rgba(255,255,255,.82)' : C.azul};margin-top:${h * 0.05}px;padding-left:${h * 0.03}px">NUTRIÇÃO ESPORTIVA</div>` : ''}
    </div></div>`;
};

render('logo-forfit', 920, 300, logo(), 'body{display:flex;align-items:center}');
render('logo-forfit-branca', 920, 300, logo({ escuro: true }), 'body{display:flex;align-items:center}');
render('logo-forfit-sem-slogan', 900, 300, logo({ tagline: false }), 'body{display:flex;align-items:center}');
render('logo-forfit-sem-slogan-branca', 900, 300, logo({ escuro: true, tagline: false }), 'body{display:flex;align-items:center}');

// ---------- Símbolo e ícones ----------
for (const s of [16, 32, 48, 180, 192, 512, 1024]) render(`simbolo-${s}`, s, s, simbolo(s, { raio: s <= 48 ? 0.18 : 0.22 }));
render('perfil-redes-500', 500, 500, `<div style="width:500px;height:500px;background:#fff;display:grid;place-items:center">${simbolo(380)}</div>`, '', false);
render('perfil-redes-azul-500', 500, 500, `<div style="width:500px;height:500px;background:${C.azul};display:grid;place-items:center">${simbolo(500, { raio: 0 })}</div>`, '', false);

// favicon.ico (16, 32, 48 em PNG dentro do ICO)
const ico = (files) => {
  const pngs = files.map((f) => fs.readFileSync(f));
  const head = Buffer.alloc(6 + 16 * pngs.length);
  head.writeUInt16LE(0, 0); head.writeUInt16LE(1, 2); head.writeUInt16LE(pngs.length, 4);
  let off = head.length;
  pngs.forEach((p, i) => {
    const s = [16, 32, 48][i]; const o = 6 + i * 16;
    head.writeUInt8(s, o); head.writeUInt8(s, o + 1); head.writeUInt16LE(1, o + 4); head.writeUInt16LE(32, o + 6);
    head.writeUInt32LE(p.length, o + 8); head.writeUInt32LE(off, o + 12); off += p.length;
  });
  return Buffer.concat([head, ...pngs]);
};
fs.writeFileSync(path.join(OUT, 'favicon.ico'), ico([16, 32, 48].map((s) => path.join(OUT, `simbolo-${s}.png`))));
fs.copyFileSync(path.join(OUT, 'simbolo-180.png'), path.join(OUT, 'apple-touch-icon.png'));
console.log('✓ arquivos/favicon.ico');

// ---------- Banners ----------
const img = (f) => url('fonte-imagens/' + f);
const fundoAzul = `background:radial-gradient(120% 140% at 85% 30%, ${C.azulClaro} 0%, ${C.azul} 38%, ${C.marinho} 100%)`;
const listras = `<div style="position:absolute;inset:0;background:repeating-linear-gradient(115deg, rgba(255,255,255,.045) 0 2px, transparent 2px 46px)"></div>`;
const faixaLaranja = (w) => `<div style="position:absolute;right:-60px;bottom:70px;width:${w}px;height:26px;background:${C.laranja};transform:skewX(-24deg)"></div>`;
const btn = (t, s = 1) => `<span style="display:inline-block;margin-top:${34 * s}px;background:${C.laranja};color:#fff;font:800 ${24 * s}px Montserrat;letter-spacing:.04em;padding:${18 * s}px ${38 * s}px;border-radius:999px;box-shadow:0 10px 30px rgba(255,106,19,.45)">${t}</span>`;
const kicker = (t, s = 1) => `<div style="display:inline-block;font:700 ${20 * s}px Montserrat;letter-spacing:.24em;color:${C.laranjaClaro};margin-bottom:${16 * s}px">${t}</div>`;
const titulo = (t, s = 1) => `<div style="font-family:Saira;font-style:italic;font-weight:900;font-stretch:92%;font-size:${92 * s}px;line-height:.92;color:#fff;text-transform:uppercase">${t}</div>`;
const sub = (t, s = 1) => `<div style="font:500 ${26 * s}px/1.35 Montserrat;color:rgba(255,255,255,.88);margin-top:${20 * s}px;max-width:${620 * s}px">${t}</div>`;
// fotos dos produtos num painel branco inclinado (as fotos das marcas têm fundo branco: multiply apaga o fundo)
const painel = (fotos, { left, top = 0, w, h, size, vertical = false }) => `<div style="position:absolute;left:${left}px;top:${top}px;width:${w}px;height:${h}px;background:#fff;clip-path:${vertical ? 'polygon(0 12%,100% 0,100% 100%,0 100%)' : 'polygon(12% 0,100% 0,100% 100%,0 100%)'}">
  <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding-left:${vertical ? 0 : w * 0.1}px;padding-top:${vertical ? h * 0.1 : 0}px">
  ${fotos.map((f) => `<img src="${img(f.src)}" style="height:${size * (f.scale || 1)}px;margin:0 ${-size * 0.12}px;mix-blend-mode:multiply;position:relative;z-index:${f.z || 1}">`).join('')}
  </div></div>`;
const borda = (left, w, h, vertical) => vertical
  ? `<div style="position:absolute;left:0;top:${left}px;width:${w}px;height:18px;background:${C.laranja};transform:skewY(-${Math.atan((h * 0.12) / w) * 57.3}deg);transform-origin:right"></div>`
  : `<div style="position:absolute;left:${left - 22}px;top:0;width:18px;height:${h}px;background:${C.laranja};transform:skewX(-${Math.atan((w * 0.12) / h) * 57.3}deg);transform-origin:bottom"></div>`;

const BANNERS = [
  {
    nome: 'revenda-autorizada',
    kicker: 'REVENDA AUTORIZADA', titulo: 'Growth e Max Titanium<br>num só lugar', sub: 'Os suplementos mais vendidos do Brasil, originais e com nota fiscal, direto da ForFit.', btn: 'COMPRAR AGORA',
    fotos: [{ src: 'whey-growth.webp', multiply: true }, { src: 'topwhey-max.png', scale: 1.1, z: 2 }, { src: 'creatina-max.png', scale: 0.78 }],
  },
  {
    nome: 'pix-10-off',
    kicker: 'PAGOU NO PIX, ECONOMIZOU', titulo: '10% OFF<br>no PIX', sub: 'Ou em até 6x sem juros no cartão. Desconto aplicado direto no checkout.', btn: 'APROVEITAR',
    fotos: [{ src: '3w-growth.webp', multiply: true }, { src: 'horus-max.png', scale: 0.95, z: 2 }],
  },
  {
    nome: 'creatina',
    kicker: 'O BÁSICO QUE FUNCIONA', titulo: 'Creatina<br>todo dia', sub: 'Mais força e desempenho nos treinos. Monohidratada e Creapure®, nas duas marcas.', btn: 'VER CREATINAS',
    fotos: [{ src: 'creatina-growth.webp', multiply: true }, { src: 'creatina-max.png', scale: 1.05, z: 2 }, { src: 'creapure-growth.webp', multiply: true, scale: 0.9 }],
  },
  {
    nome: 'frete-gratis',
    kicker: 'ENVIO RÁPIDO', titulo: 'Frete grátis<br>acima de R$ 199', sub: 'Estoque próprio e despacho em até 1 dia útil. Ou retire na sua unidade ForFit.', btn: 'MONTAR MEU PEDIDO',
    fotos: [{ src: '2hot-max.png', scale: 0.9 }, { src: 'omega-growth.webp', z: 2 }, { src: 'cafeina-growth.webp', multiply: true, scale: 0.85 }],
  },
];

for (const b of BANNERS) {
  // computador 1920×600: texto à esquerda, painel de produtos à direita
  render(`banners/${b.nome}-desktop`, 1920, 600, `<div style="position:relative;width:1920px;height:600px;overflow:hidden;${fundoAzul}">${listras}
    <div style="position:absolute;left:150px;top:50%;transform:translateY(-50%);width:820px;z-index:3">${kicker(b.kicker)}${titulo(b.titulo, 0.88)}${sub(b.sub)}${btn(b.btn)}</div>
    ${borda(1060, 860, 600)}${painel(b.fotos, { left: 1040, w: 880, h: 600, size: 400 })}</div>`, '', false);
  // celular 828×1000: texto em cima, painel de produtos embaixo
  render(`banners/${b.nome}-mobile`, 828, 1000, `<div style="position:relative;width:828px;height:1000px;overflow:hidden;${fundoAzul}">${listras}
    <div style="position:absolute;left:60px;right:60px;top:64px;z-index:3">${kicker(b.kicker, 1.05)}${titulo(b.titulo, 0.9)}${sub(b.sub, 1.05)}${btn(b.btn, 1.05)}</div>
    ${painel(b.fotos, { left: 0, top: 560, w: 828, h: 440, size: 330, vertical: true })}</div>`, '', false);
}

// Faixa das marcas (página de cada marca) 1920×360
for (const [slug, nome, cor, foto] of [['growth-supplements', 'Growth Supplements', '#0b74c4', 'whey-growth.webp'], ['max-titanium', 'Max Titanium', '#111111', 'topwhey-max.png']]) {
  render(`banners/marca-${slug}`, 1920, 360, `<div style="position:relative;width:1920px;height:360px;overflow:hidden;background:linear-gradient(100deg, ${cor} 0%, ${C.marinho} 75%)">${listras}
    <div style="position:absolute;left:150px;top:50%;transform:translateY(-50%)">${kicker('REVENDA AUTORIZADA FORFIT')}${titulo(nome, 0.85)}</div>
    <div style="position:absolute;left:150px;bottom:64px;font:600 22px Montserrat;color:rgba(255,255,255,.85)">Produtos originais · nota fiscal · ${foto ? '10% OFF no PIX' : ''}</div>
    ${faixaLaranja(760)}
    <div style="position:absolute;right:170px;top:50%;transform:translateY(-50%);opacity:.95">${simbolo(230)}</div></div>`, '', false);
}

// Imagem de compartilhamento (WhatsApp/Facebook) 1200×630
render('compartilhamento-1200x630', 1200, 630, `<div style="position:relative;width:1200px;height:630px;overflow:hidden;${fundoAzul}">${listras}${faixaLaranja(700)}
  <div style="position:absolute;left:90px;top:120px">${logo({ escuro: true, h: 190 })}</div>
  <div style="position:absolute;left:100px;bottom:120px;font:600 34px Montserrat;color:#fff">Revenda autorizada <b>Growth</b> e <b>Max Titanium</b></div></div>`, '', false);

// Capa do Facebook 1640×624 e destaques do Instagram
render('capa-facebook-1640x624', 1640, 624, `<div style="position:relative;width:1640px;height:624px;overflow:hidden;${fundoAzul}">${listras}${faixaLaranja(800)}
  <div style="position:absolute;left:520px;top:50%;transform:translateY(-50%)">${titulo('Seu treino merece<br>suplemento original', 0.8)}${sub('Growth e Max Titanium com 10% OFF no PIX', 1.1)}</div></div>`, '', false);

fs.rmSync(TMP, { recursive: true, force: true });
console.log('\nPronto: arquivos em forfit-marca/arquivos');
