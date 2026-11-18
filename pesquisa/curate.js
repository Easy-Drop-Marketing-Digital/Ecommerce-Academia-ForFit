const g=require('./growth-top.json'),m=require('./max-top.json');
const kit=/(^|\s)(kit|pack|display 100%|combo)\b/i;
const excl={aminoacidos:/creatina|whey/i,termogenicos:/whey|creatina|bebida l[aá]ctea|col[aá]geno|dose /i,vitaminas:/creatina|kit/i,massa:/protein|panqueca|ervilha|rice|soy/i,pretreino:/waxy|palatinose|maltodextrina/i};
const norm=s=>s.toLowerCase().replace(/\(top\)/,'').replace(/sabor .*$/,'').replace(/\s+(chocolate|morango|baunilha|natural|limão|laranja|tangerina|caramelo|frutas vermelhas|açaí com guaraná|limonada silvestre|pitaya.*|tropical|cappuccino|tradicional|jabuticaba.*|melão.*|abacaxi.*|melancia)\b.*$/,'').replace(/\s+/g,' ').trim();
const cur=(list,seg)=>{const out=[],seen=new Set();for(const p of list){if(kit.test(p.name))continue;if(excl[seg]&&excl[seg].test(p.name))continue;if(p.avail===false)continue;const n=norm(p.name);if(seen.has(n))continue;seen.add(n);out.push(p);if(out.length==10)break}return out};
const res={};for(const seg of Object.keys(g)){res[seg]={growth:cur(g[seg],seg),max:cur(m[seg]||[],seg)}}
require('fs').writeFileSync('curated.json',JSON.stringify(res,null,1));
for(const [s,v] of Object.entries(res)){console.log(`\n## ${s}  (Growth ${v.growth.length} | Max ${v.max.length})`);for(let i=0;i<10;i++){const a=v.growth[i],b=v.max[i];console.log(String(i+1).padStart(2),(a?a.name.replace('(TOP) ','')+' '+a.price:'—').padEnd(62).slice(0,62),'|',b?b.name.slice(0,55)+' R$'+b.price:'—')}}
