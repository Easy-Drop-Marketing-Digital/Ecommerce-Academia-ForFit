// Lê o estado window.__NUXT__ de uma página salva SEM executar o código dela:
// analisa a expressão com acorn e só interpreta literais e atribuições simples (a.b = {...}).
const acorn=require('acorn');
module.exports=function parseNuxt(html){
  const m=html.match(/<script>window\.__NUXT__=([\s\S]*?)<\/script>/); if(!m) return null;
  const ast=acorn.parseExpressionAt(m[1],0,{ecmaVersion:2022});
  if(!(ast.type==='CallExpression'&&ast.callee.type==='FunctionExpression')) return ev(ast,{});
  const fn=ast.callee, s={};
  fn.params.forEach((p,i)=>s[p.name]=ast.arguments[i]?ev(ast.arguments[i],{}):undefined);
  for(const st of fn.body.body){
    if(st.type==='ExpressionStatement'&&st.expression.type==='AssignmentExpression') assign(st.expression.left, ev(st.expression.right,s), s);
    if(st.type==='ReturnStatement') return ev(st.argument,s);
  }
  function target(n,s){ if(n.type==='Identifier') return s[n.name]; if(n.type==='MemberExpression'){const o=target(n.object,s); return o==null?undefined:o[n.computed?ev(n.property,s):n.property.name];} }
  function assign(l,v,s){ if(l.type==='Identifier'){s[l.name]=v;return;} if(l.type==='MemberExpression'){const o=target(l.object,s); if(o&&typeof o==='object') o[l.computed?ev(l.property,s):l.property.name]=v;} }
  function ev(n,s){switch(n.type){
    case 'Literal': return n.value;
    case 'Identifier': return n.name==='undefined'?undefined:s[n.name];
    case 'UnaryExpression': if(n.operator==='void')return undefined; if(n.operator==='-')return -ev(n.argument,s); if(n.operator==='!')return !ev(n.argument,s); return undefined;
    case 'ArrayExpression': return n.elements.map(e=>e?ev(e,s):null);
    case 'ObjectExpression': {const o={}; for(const p of n.properties){ if(p.type!=='Property')continue; const k=p.computed?ev(p.key,s):(p.key.name??p.key.value); o[k]=ev(p.value,s);} return o;}
    case 'TemplateLiteral': return n.quasis.map(q=>q.value.cooked).join('');
    case 'MemberExpression': return target(n,s);
    default: return null;
  }}
};
