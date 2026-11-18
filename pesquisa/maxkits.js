const UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36';
(async()=>{let all=[];for(let f=0;f<150;f+=50){const r=await fetch(`https://www.maxtitanium.com.br/api/catalog_system/pub/products/search?fq=C:/2/41/&O=OrderByTopSaleDESC&_from=${f}&_to=${f+49}`,{headers:{'User-Agent':UA}});const j=await r.json();if(!j.length)break;all.push(...j)}
const out=all.map(p=>{const o=p.items.map(i=>i.sellers[0].commertialOffer);return {name:p.productName,link:p.link,price:Math.min(...o.filter(x=>x.Price>0).map(x=>x.Price)),avail:o.some(x=>x.AvailableQuantity>0),cats:p.categories,id:p.productId}});
require('fs').writeFileSync('max-kits.json',JSON.stringify(out,null,1));out.forEach((p,i)=>console.log(i+1,p.avail?'':'[ESG]',p.name,'R$'+p.price))})();
