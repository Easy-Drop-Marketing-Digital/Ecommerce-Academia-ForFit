<?php

/*
|--------------------------------------------------------------------------
| Configurações editáveis no painel admin
|--------------------------------------------------------------------------
| Cada campo vira um input na tela "Configurações" do admin e é gravado na
| tabela `settings`. Campos com 'secret' => true são gravados criptografados
| (APP_KEY) e nunca são exibidos de volta na tela — só "••••1234".
|
| 'maps_to' sobrescreve a config do Laravel em tempo de execução, então o
| restante do código continua lendo config('services.nixfy.public_key') etc.
| O .env vale só como valor padrão (fallback) se o campo estiver vazio.
*/

return [
    // Cache de página da vitrine (ver App\Support\PageCache). Ligado em produção; PAGE_CACHE=false desliga.
    'page_cache' => (bool) env('PAGE_CACHE', env('APP_ENV') === 'production'),

    // Verificação em duas etapas obrigatória no admin (padrão: só em produção)
    'admin_require_2fa' => (bool) env('ADMIN_REQUIRE_2FA', env('APP_ENV') === 'production'),

    'groups' => [

        'store' => [
            'label' => 'Dados da loja',
            'icon' => 'heroicon-o-building-storefront',
            'description' => 'Nome, CNPJ, contatos, endereço e redes sociais (rodapé e políticas).',
            'check' => ['store.email'],
            'fields' => [
                'store.name' => ['label' => 'Nome da loja', 'default' => 'ForFit', 'maps_to' => 'app.name'],
                'store.legal_name' => ['label' => 'Razão social', 'default' => ''],
                'store.cnpj' => ['label' => 'CNPJ', 'default' => '', 'help' => 'Exibido no rodapé e nas políticas — recomendado para aprovação no Google Merchant e Meta.'],
                'store.email' => ['label' => 'E-mail de atendimento', 'default' => 'contato@lojaforfit.com.br'],
                'store.whatsapp' => ['label' => 'WhatsApp de atendimento', 'default' => ''],
                'store.address' => ['label' => 'Endereço administrativo', 'default' => '', 'help' => 'Aparece no rodapé e nas políticas. Vazio = linha oculta.'],
                'store.city_forum' => ['label' => 'Comarca (foro)', 'default' => '', 'help' => 'Cidade - UF do foro nos Termos de Uso. Vazio = vale o foro do domicílio da cliente.'],
                'store.hours' => ['label' => 'Horário de atendimento', 'default' => 'Segunda a sexta-feira, das 9h às 18h (exceto feriados nacionais)'],
                'store.response_time' => ['label' => 'Tempo de resposta', 'default' => 'até 1 dia útil'],
                'store.instagram' => ['label' => 'Instagram (URL)', 'default' => ''],
                'store.facebook' => ['label' => 'Facebook (URL)', 'default' => ''],
                'store.tiktok' => ['label' => 'TikTok (URL)', 'default' => ''],
            ],
        ],

        'delivery' => [
            'label' => 'Prazos de entrega',
            'icon' => 'heroicon-o-clock',
            'description' => 'Prazo de despacho, rastreio e entrega exibidos ao cliente.',
            'check' => ['delivery.transit_days'],
            'fields' => [
                'delivery.processing_days' => ['label' => 'Prazo de processamento/despacho', 'default' => 'até 1 dia útil'],
                'delivery.tracking_days' => ['label' => 'Envio do código de rastreio', 'default' => 'até 2 dias úteis após a confirmação do pagamento'],
                'delivery.transit_days' => ['label' => 'Prazo total estimado de entrega', 'default' => '3 a 10 dias úteis'],
            ],
            // Preço e prazo de cada modalidade (Frete Grátis / Expresso) ficam em Admin → Frete (tabela shipping_methods).
        ],

        'nixfy' => [
            'label' => 'Pagamentos — Nixfy',
            'icon' => 'heroicon-o-credit-card',
            'description' => 'Chaves da API, webhook, validade do PIX, parcelas e juros.',
            'check' => ['nixfy.public_key', 'nixfy.private_key', 'nixfy.webhook_secret'],
            'fields' => [
                'nixfy.public_key' => ['label' => 'Chave pública (pk_...)', 'maps_to' => 'services.nixfy.public_key'],
                'nixfy.private_key' => ['label' => 'Chave privada (sk_...)', 'secret' => true, 'maps_to' => 'services.nixfy.private_key'],
                'nixfy.webhook_secret' => ['label' => 'Segredo do webhook permanente', 'secret' => true, 'maps_to' => 'services.nixfy.webhook_secret'],
                'nixfy.postback_secret' => ['label' => 'POSTBACK_SECRET_KEY', 'secret' => true, 'maps_to' => 'services.nixfy.postback_secret'],
                'nixfy.pix_expires_in_days' => ['label' => 'Validade do PIX (dias)', 'default' => '1', 'maps_to' => 'services.nixfy.pix_expires_in_days'],
                'nixfy.pix_discount_percent' => ['label' => 'Desconto no PIX (%)', 'default' => '10'],
                'nixfy.max_installments' => ['label' => 'Máximo de parcelas no cartão', 'default' => '12'],
                'nixfy.interest_free_installments' => ['label' => 'Parcelas sem juros', 'default' => '6'],
                'nixfy.installment_rate' => ['label' => 'Juros ao mês nas parcelas com juros (%)', 'default' => '2.9', 'help' => 'Tabela Price. O total com juros é o valor cobrado no cartão.'],
                'nixfy.min_installment_value' => ['label' => 'Parcela mínima (R$)', 'default' => '5,00'],
            ],
        ],

        'antifraud' => [
            'label' => 'Antifraude (cartão)',
            'icon' => 'heroicon-o-shield-check',
            'description' => 'Limites contra cartões roubados e robôs de teste de cartão.',
            'check' => [],
            'fields' => [
                'antifraud.max_cards_per_customer_24h' => ['label' => 'Máx. cartões diferentes por CPF/e-mail em 24h', 'default' => '3'],
                'antifraud.max_customers_per_card_30d' => ['label' => 'Máx. CPFs diferentes usando o mesmo cartão em 30 dias', 'default' => '2'],
                'antifraud.max_refused_per_ip_1h' => ['label' => 'Máx. recusas por IP em 1h (anti teste de cartão)', 'default' => '5'],
                'antifraud.block_on_limit' => ['label' => 'Bloquear quando passar do limite (senão só sinaliza)', 'default' => '1', 'type' => 'boolean'],
            ],
        ],

        'reviews' => [
            'label' => 'Avaliações',
            'icon' => 'heroicon-o-star',
            'description' => 'Moderação e exibição das estrelas na vitrine.',
            'check' => [],
            'fields' => [
                'reviews.auto_approve_min' => [
                    'label' => 'Publicar automaticamente avaliações de compradoras a partir de',
                    'default' => '0',
                    'options' => ['0' => 'Nunca (toda avaliação passa por moderação)', '5' => '5 estrelas', '4' => '4 estrelas ou mais', '1' => 'Todas'],
                ],
                'reviews.show_on_cards' => ['label' => 'Mostrar estrelas nos cards de produto', 'default' => '1', 'type' => 'boolean'],
            ],
        ],

        'meta' => [
            'label' => 'Meta Ads (Pixel + API de Conversões)',
            'icon' => 'heroicon-o-megaphone',
            'description' => 'Pixel, token da API de Conversões e verificação de domínio.',
            'check' => ['meta.pixel_id', 'meta.capi_token'],
            'fields' => [
                'meta.pixel_id' => ['label' => 'ID do Pixel', 'maps_to' => 'services.meta.pixel_id'],
                'meta.capi_token' => ['label' => 'Token da API de Conversões', 'secret' => true, 'maps_to' => 'services.meta.capi_token'],
                'meta.test_event_code' => ['label' => 'Código de evento de teste (deixe vazio em produção)', 'maps_to' => 'services.meta.test_event_code'],
                'meta.domain_verification' => ['label' => 'Meta tag de verificação de domínio (conteúdo)'],
                'meta.capi_pageview' => ['label' => 'Enviar PageView também pela API de Conversões', 'default' => '1', 'type' => 'boolean'],
            ],
        ],

        'catalog' => [
            'label' => 'Catálogo de produtos',
            'icon' => 'heroicon-o-squares-2x2',
            'description' => 'Formato do ID dos produtos no feed e nos eventos, marca e categoria.',
            'check' => [],
            'fields' => [
                'catalog.content_id_format' => [
                    'label' => 'Formato do ID do produto nos eventos e no feed',
                    'default' => 'variant_id',
                    'options' => [
                        'variant_id' => 'ID da variação desta loja (padrão — catálogo novo)',
                        'sku' => 'SKU da variação (cai no ID quando não houver SKU)',
                    ],
                    'help' => 'Precisa ser o mesmo formato do catálogo conectado ao Pixel, senão os anúncios de catálogo não casam os produtos.',
                ],
                'catalog.google_product_category' => ['label' => 'Categoria Google padrão', 'default' => 'Health & Beauty > Health Care > Fitness & Nutrition > Vitamins & Supplements'],
                'catalog.brand' => ['label' => 'Marca no feed (produtos sem marca cadastrada)', 'default' => 'ForFit'],
            ],
        ],

        'google' => [
            'label' => 'Google (Analytics, Ads, Merchant)',
            'icon' => 'heroicon-o-chart-bar',
            'description' => 'GA4, Google Ads, Tag Manager e verificação do Merchant.',
            'check' => ['google.ga4_id'],
            'fields' => [
                'google.ga4_id' => ['label' => 'GA4 Measurement ID (G-...)', 'maps_to' => 'services.google.ga4_id'],
                'google.ga4_api_secret' => ['label' => 'GA4 Measurement Protocol API secret', 'secret' => true, 'maps_to' => 'services.google.ga4_api_secret'],
                'google.ads_id' => ['label' => 'Google Ads ID (AW-...)', 'maps_to' => 'services.google.ads_id'],
                'google.ads_purchase_label' => ['label' => 'Rótulo de conversão de compra', 'maps_to' => 'services.google.ads_purchase_label'],
                'google.gtm_id' => ['label' => 'Google Tag Manager (GTM-...) — opcional', 'maps_to' => 'services.google.gtm_id'],
                'google.site_verification' => ['label' => 'Meta tag de verificação do Search Console/Merchant'],
            ],
        ],

        'tiktok' => [
            'label' => 'TikTok Ads',
            'icon' => 'heroicon-o-play-circle',
            'description' => 'Pixel e token da Events API do TikTok.',
            'check' => ['tiktok.pixel_id', 'tiktok.access_token'],
            'fields' => [
                'tiktok.pixel_id' => ['label' => 'ID do Pixel', 'maps_to' => 'services.tiktok.pixel_id'],
                'tiktok.access_token' => ['label' => 'Access token da Events API', 'secret' => true, 'maps_to' => 'services.tiktok.access_token'],
                'tiktok.test_event_code' => ['label' => 'Código de evento de teste (deixe vazio em produção)', 'maps_to' => 'services.tiktok.test_event_code'],
            ],
        ],

        'utmify' => [
            'label' => 'UTMify (opcional)',
            'icon' => 'heroicon-o-arrows-right-left',
            'description' => 'Envio dos pedidos para atribuição de vendas por UTM.',
            'check' => ['utmify.api_token'],
            'fields' => [
                'utmify.api_token' => ['label' => 'Token da API', 'secret' => true, 'maps_to' => 'services.utmify.api_token'],
            ],
        ],

        'notifications' => [
            'label' => 'E-mails automáticos',
            'icon' => 'heroicon-o-bell-alert',
            'description' => 'Liga/desliga cada e-mail da loja, recuperação de carrinho e alertas internos.',
            'check' => [],
            'fields' => [
                'emails.order_pix' => ['label' => 'PIX gerado (com código copia e cola)', 'default' => '1', 'type' => 'boolean'],
                'emails.order_received' => ['label' => 'Pedido recebido (cartão em análise)', 'default' => '1', 'type' => 'boolean'],
                'emails.pix_reminder' => ['label' => 'Lembrete de PIX não pago (30 min depois)', 'default' => '1', 'type' => 'boolean'],
                'emails.order_paid' => ['label' => 'Pagamento aprovado', 'default' => '1', 'type' => 'boolean'],
                'emails.payment_refused' => ['label' => 'Cartão recusado (com link para tentar de novo)', 'default' => '1', 'type' => 'boolean'],
                'emails.order_shipped' => ['label' => 'Pedido enviado (código de rastreio)', 'default' => '1', 'type' => 'boolean'],
                'emails.order_delivered' => ['label' => 'Pedido entregue (pede avaliação)', 'default' => '1', 'type' => 'boolean'],
                'emails.order_canceled' => ['label' => 'Pedido cancelado', 'default' => '1', 'type' => 'boolean'],
                'emails.order_refunded' => ['label' => 'Reembolso', 'default' => '1', 'type' => 'boolean'],
                'emails.welcome' => ['label' => 'Boas-vindas (criou conta)', 'default' => '1', 'type' => 'boolean'],
                'emails.cart_recovery' => ['label' => 'Recuperação de carrinho abandonado (3 e-mails)', 'default' => '1', 'type' => 'boolean'],
                'cart_recovery.hours' => ['label' => 'Enviar os 3 lembretes após (horas, separadas por vírgula)', 'default' => '1,24,48'],
                'cart_recovery.coupon' => ['label' => 'Cupom oferecido no 3º lembrete (opcional)', 'help' => 'Cadastre o cupom em Marketing → Descontos. Em branco = 3º e-mail sem cupom.'],
                'emails.admin_new_order' => ['label' => 'Aviso interno de pedido pago', 'default' => '1', 'type' => 'boolean'],
                'emails.admin_low_stock' => ['label' => 'Aviso interno de estoque baixo', 'default' => '1', 'type' => 'boolean'],
                'emails.admin_card_blocked' => ['label' => 'Aviso interno de cartão bloqueado pelo antifraude', 'default' => '1', 'type' => 'boolean'],
                'stock.low_threshold' => ['label' => 'Considerar estoque baixo a partir de (unidades)', 'default' => '10'],
            ],
        ],

        'email' => [
            'label' => 'E-mails (Resend)',
            'icon' => 'heroicon-o-envelope',
            'description' => 'Chave da Resend, remetente e e-mails internos de aviso.',
            'check' => ['resend.api_key'],
            'fields' => [
                'resend.api_key' => ['label' => 'API key da Resend (re_...)', 'secret' => true, 'maps_to' => 'services.resend.key'],
                'mail.from_address' => ['label' => 'Remetente (e-mail)', 'default' => 'contato@lojaforfit.com.br', 'maps_to' => 'mail.from.address'],
                'mail.from_name' => ['label' => 'Remetente (nome)', 'default' => 'ForFit', 'maps_to' => 'mail.from.name'],
                'mail.reply_to' => ['label' => 'Responder para', 'default' => 'contato@lojaforfit.com.br'],
                'mail.admin_notify' => ['label' => 'E-mails internos (novo pedido, estoque baixo) — separados por vírgula'],
            ],
        ],
    ],
];
