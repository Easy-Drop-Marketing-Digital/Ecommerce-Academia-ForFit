# ForFit — publicação na Hostinger, passo a passo

Domínio usado neste guia: **lojaforfit.com.br** (provisório). Se o domínio for outro, troque só no `.env` (passo 2)
e no caminho dos comandos. Tudo o que vai para o servidor sai em **dois pacotes** gerados neste computador.

> **Lições do deploy da Aurea Santz (já resolvidas aqui):**
> - O terminal do seu computador é **PowerShell**: lá `&&` não funciona. Por isso cada comando abaixo vem **sozinho**,
>   num bloco próprio. Cole um, aperte Enter, espere o `$` voltar, cole o próximo.
> - Nenhum comando tem campo para preencher (o e-mail do dono é **perguntado** pelo instalador).
> - Erro de `proc_open` no fim do `composer install` é **esperado** na Hostinger — os dois comandos seguintes resolvem.
> - Cron da Hostinger: só funciona com o PHP de `/opt/alt/php83/...` (passo 4).

---

## 0. Antes de começar (no hPanel)

1. **Avançado → Configuração do PHP**: versão **8.3**; extensões `intl`, `gd`, `zip`, `pdo_mysql`, `mbstring`, `fileinfo`,
   `curl`, `openssl`, `bcmath`; `memory_limit` 512M; `max_execution_time` 120.
2. **Bancos de dados → MySQL**: crie banco + usuário e anote os três dados.
3. **Sites**: domínio adicionado; **Avançado → SSH**: ative e anote usuário, IP e porta (65002).

## 1. Gerar os pacotes (neste computador, já feito por nós a cada versão)

```powershell
cd "C:\Users\TH\Desktop\Academia ForFit\app"
```
```powershell
npm run build
```
```powershell
php artisan release:package
```
```powershell
php artisan catalog:export
```

Saem em `releases/`: `loja-AAAAMMDD-HHMM.zip` (código) e `catalogo-AAAAMMDD-HHMM.zip` (produtos, fotos, banners, marcas,
páginas, frete, cupom). O catálogo **não** leva chaves, pedidos, clientes nem usuários.

**Envie os dois .zip pelo Gerenciador de Arquivos da Hostinger** para a pasta `domains/lojaforfit.com.br/`
(é mais simples que `scp`).

## 2. Instalar (SSH — um comando por vez)

Entre no servidor pelo terminal (o hPanel mostra o comando `ssh` pronto em Avançado → SSH). Depois:

```bash
cd ~/domains/lojaforfit.com.br
```
```bash
mkdir loja
```
```bash
cd loja
```
```bash
unzip ../loja-*.zip
```
```bash
cp .env.production.example .env
```
```bash
nano .env
```
No `nano`, preencha só `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` (e `APP_URL`/`MAIL_FROM_ADDRESS` se o domínio for outro).
Salve com **Ctrl+O**, Enter, e saia com **Ctrl+X**.

```bash
composer install --no-dev --optimize-autoloader
```
(Erro de `proc_open` no final: normal. Siga.)
```bash
php artisan package:discover
```
```bash
php artisan filament:upgrade
```
```bash
php artisan key:generate
```
```bash
ln -s ../storage/app/public public/storage
```
```bash
php artisan store:install --catalogo=$(ls ../catalogo-*.zip | tail -1)
```
O instalador pergunta o **e-mail do dono do admin** e a **senha duas vezes** (a senha aparece na tela neste servidor —
rode `clear` em seguida). Pode ser repetido sem estragar nada.

## 3. Apontar o site para a loja

```bash
cd ~/domains/lojaforfit.com.br
```
```bash
mv public_html public_html_antigo
```
```bash
ln -s loja/public public_html
```

## 4. Cron (obrigatório)

hPanel → **Avançado → Cron Jobs** → “a cada minuto” (`* * * * *`), comando (troque só `SEU_USUARIO` pelo usuário do SSH,
o mesmo que aparece no prompt, ex.: `u123456789`):

```
/opt/alt/php83/usr/bin/php /home/SEU_USUARIO/domains/lojaforfit.com.br/loja/artisan schedule:run
```

Sem o cron: PIX vencido não devolve o estoque, lembretes não saem e eventos não chegam ao Meta.

## 5. Configurar no admin (`https://lojaforfit.com.br/admin`)

1. Ative a **verificação em duas etapas** (o admin exige).
2. **Configurações da loja**:
   - Dados da loja: razão social, CNPJ, WhatsApp (aparece no topo, rodapé e botão de dúvidas), endereço, Instagram.
   - Pagamentos — Nixfy: chaves, segredo do webhook e o **POSTBACK_SECRET_KEY**. Na Nixfy, webhook para
     `https://lojaforfit.com.br/webhooks/nixfy`.
   - E-mails (Resend): chave + remetente `contato@lojaforfit.com.br`.
   - Meta / Google / TikTok / UTMify: IDs e tokens da ForFit (nada vem preenchido).
3. **Catálogo → Estoque**: ajuste o estoque real (o catálogo entra com **20 unidades por sabor** só como ponto de partida).
4. **Catálogo → Produtos**: confira os preços (vieram do site de cada marca; o PIX dá 10% a mais de desconto).
5. **Configurações → Frete**: “Entrega padrão” (grátis acima de R$ 199), “Entrega Expressa” e “Retirar na academia ForFit”.
6. **Saúde da loja** toda verde e uma compra real no PIX e no cartão (cupom de teste: `FORFIT10`).

## 6. Domínio, SSL e catálogo de anúncios

- SSL grátis no hPanel (Segurança → SSL) depois que o DNS propagar.
- Meta: verificar domínio e criar catálogo com o feed `https://lojaforfit.com.br/feeds/produtos.xml`
  (já sai um campo por linha — o erro de “linha maior que 5 MB” da Aurea está corrigido).
- Google Merchant: mesmo feed. Search Console: `https://lojaforfit.com.br/sitemap.xml`.

## 7. Atualizações futuras

**Sem SSH (Gerenciador de Arquivos):** para trocar um texto de tela (`.blade.php`), edite o arquivo em
`loja/resources/views/...` e depois limpe o cache em **Admin → Saúde da loja → Limpar cache**.

**Com SSH (versão nova inteira):** envie o `loja-*.zip` novo, extraia por cima da pasta `loja` e rode:
```bash
cd ~/domains/lojaforfit.com.br/loja
```
```bash
bash deploy.sh
```
O catálogo, depois de instalado, é mantido pelo admin — não reinstale o pacote de catálogo (ele apagaria o que foi editado).
