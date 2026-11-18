# ForFit — identidade visual

## Cores

| Cor | Código | Uso |
|---|---|---|
| Azul ForFit | `#0A4FD6` | cabeçalho, símbolo, links, botões secundários |
| Azul claro | `#1E7BFF` | degradê do símbolo e dos banners |
| Marinho | `#06173D` | “FOR” na logo, faixa do topo, rodapé |
| Laranja | `#FF6A13` | “FIT” na logo, botão comprar, selos de desconto |

Tipografia: **Saira Black Itálico** (logo e títulos) e **Montserrat** (textos). As duas são de licença livre (SIL OFL),
inclusive para logotipo — arquivos em `fontes/`.

## Arquivos prontos (`arquivos/`)

| Arquivo | Uso |
|---|---|
| `logo-forfit.png` | Logo principal (fundo claro): checkout, e-mails, admin, documentos |
| `logo-forfit-branca.png` | Fundos escuros/azuis: cabeçalho e rodapé da loja, banners, redes |
| `logo-forfit-sem-slogan*.png` | Versão curta, sem “Nutrição esportiva” |
| `simbolo-*.png` | Símbolo “4” (16 a 1024 px): ícones, carimbo, etiqueta |
| `favicon.ico`, `apple-touch-icon.png` | Ícone da aba do navegador e do celular |
| `perfil-redes-500.png` / `perfil-redes-azul-500.png` | Foto de perfil do Instagram/Facebook/WhatsApp |
| `capa-facebook-1640x624.png` | Capa do Facebook |
| `compartilhamento-1200x630.png` | Imagem que aparece ao compartilhar o link no WhatsApp/Facebook |
| `banners/*-desktop.png` (1920×600) e `*-mobile.png` (828×1000) | 4 banners da home: revenda autorizada, 10% OFF no PIX, creatina, frete grátis |
| `banners/marca-*.png` (1920×360) | Topo das páginas de cada marca |

Os banners já entram na loja pelo comando `php artisan db:seed --class=ForFitStorefrontSeeder`.
Para trocar um banner depois: **Admin → Marketing → Banners**.

## Regerar

Com Node e o Google Chrome instalados:

```powershell
node gerar-marca.js
```

Textos, cores e produtos dos banners ficam no próprio `gerar-marca.js` (lista `BANNERS`). As fotos dos produtos
usadas nos banners ficam em `fonte-imagens/` (fotos oficiais das marcas — uso permitido à revenda autorizada).
