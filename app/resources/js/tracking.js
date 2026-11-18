/*
 * Rastreamento no navegador.
 * Cada evento recebe um event_id único que vai para o Pixel/ttq E para o nosso servidor (/t),
 * que reenvia pela API de Conversões — as plataformas deduplicam pelo par (event_name, event_id).
 *
 * Os pixels só carregam se o ID estiver configurado em Admin → Configurações (window.SD_TRACK).
 * Páginas enfileiram eventos em window.sdq: sdq.push(['view_item', {items:[...]}])
 */
const cfg = window.SD_TRACK || {};
const META = { page_view: 'PageView', view_item: 'ViewContent', search: 'Search', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', add_payment_info: 'AddPaymentInfo', purchase: 'Purchase' };
const TIKTOK = { view_item: 'ViewContent', search: 'Search', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', add_payment_info: 'AddPaymentInfo', purchase: 'CompletePayment' };

const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'e' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
const brl = (cents) => Math.round(cents) / 100;

function loadScript(src) {
    const s = document.createElement('script');
    s.async = true;
    s.src = src;
    document.head.appendChild(s);
}

function initPixels() {
    if (cfg.meta) {
        /* eslint-disable */
        !function (f, b, e, v, n, t, s) { if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments) }; if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = []; t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s) }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
        /* eslint-enable */
        window.fbq('init', cfg.meta, cfg.am || {});
    }
    if (cfg.tiktok) {
        /* eslint-disable */
        !function (w, d, t) { w.TiktokAnalyticsObject = t; var ttq = w[t] = w[t] || []; ttq.methods = ["page", "track", "identify", "instances", "debug", "on", "off", "once", "ready", "alias", "group", "enableCookie", "disableCookie", "holdConsent", "revokeConsent", "grantConsent"], ttq.setAndDefer = function (t, e) { t[e] = function () { t.push([e].concat(Array.prototype.slice.call(arguments, 0))) } }; for (var i = 0; i < ttq.methods.length; i++)ttq.setAndDefer(ttq, ttq.methods[i]); ttq.instance = function (t) { for (var e = ttq._i[t] || [], n = 0; n < ttq.methods.length; n++)ttq.setAndDefer(e, ttq.methods[n]); return e }, ttq.load = function (e, n) { var r = "https://analytics.tiktok.com/i18n/pixel/events.js"; ttq._i = ttq._i || {}, ttq._i[e] = [], ttq._i[e]._u = r, ttq._t = ttq._t || {}, ttq._t[e] = +new Date, ttq._o = ttq._o || {}, ttq._o[e] = n || {}; var o = document.createElement("script"); o.type = "text/javascript", o.async = !0, o.src = r + "?sdkid=" + e + "&lib=" + t; var a = document.getElementsByTagName("script")[0]; a.parentNode.insertBefore(o, a) }; }(window, document, 'ttq');
        /* eslint-enable */
        window.ttq.load(cfg.tiktok);
        if (cfg.tt) window.ttq.identify(cfg.tt);
        window.ttq.page();
    }
    if (cfg.ga4 || cfg.ads || cfg.gtm) {
        window.dataLayer = window.dataLayer || [];
        window.gtag = function () { window.dataLayer.push(arguments); };
        window.gtag('js', new Date());
        if (cfg.ga4 || cfg.ads) loadScript('https://www.googletagmanager.com/gtag/js?id=' + (cfg.ga4 || cfg.ads));
        if (cfg.ga4) window.gtag('config', cfg.ga4);
        if (cfg.ads) window.gtag('config', cfg.ads, { allow_enhanced_conversions: true });
        if (cfg.gu) window.gtag('set', 'user_data', cfg.gu);
        if (cfg.gtm) {
            window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
            loadScript('https://www.googletagmanager.com/gtm.js?id=' + cfg.gtm);
        }
    }
}

/**
 * @param {string} name   page_view | view_item | search | add_to_cart | begin_checkout | add_payment_info | purchase
 * @param {object} data   { items: [{variant_id, content_id, name, price, quantity}], value, search, event_id, transaction_id }
 */
export function track(name, data = {}) {
    const eventId = data.event_id || uuid();
    const items = data.items || [];
    const value = data.value ?? items.reduce((s, i) => s + i.price * (i.quantity || 1), 0);
    const contents = items.map((i) => ({ id: i.content_id, quantity: i.quantity || 1, item_price: brl(i.price) }));

    try {
        if (window.fbq && META[name]) {
            const params = name === 'page_view' ? {} : {
                currency: 'BRL', value: brl(value), content_type: 'product',
                content_ids: [...new Set(items.map((i) => i.content_id))], contents, num_items: items.reduce((s, i) => s + (i.quantity || 1), 0),
            };
            if (data.search) params.search_string = data.search;
            if (data.transaction_id) params.order_id = String(data.transaction_id);
            window.fbq('track', META[name], params, { eventID: eventId });
        }
        if (window.ttq && TIKTOK[name]) {
            window.ttq.track(TIKTOK[name], {
                currency: 'BRL', value: brl(value), content_type: 'product',
                contents: items.map((i) => ({ content_id: i.content_id, content_name: i.name, quantity: i.quantity || 1, price: brl(i.price) })),
                ...(data.search ? { query: data.search } : {}),
            }, { event_id: eventId });
        }
        if (window.gtag && name !== 'page_view') {
            const ga = {
                currency: 'BRL', value: brl(value),
                items: items.map((i) => ({ item_id: i.content_id, item_name: i.name, price: brl(i.price), quantity: i.quantity || 1 })),
            };
            if (data.transaction_id) ga.transaction_id = String(data.transaction_id);
            if (data.search) ga.search_term = data.search;
            window.gtag('event', name, ga);
            if (name === 'purchase' && cfg.ads && cfg.adsLabel) {
                window.gtag('event', 'conversion', { send_to: `${cfg.ads}/${cfg.adsLabel}`, value: brl(value), currency: 'BRL', transaction_id: String(data.transaction_id || '') });
            }
        }
        if (window.dataLayer && cfg.gtm) window.dataLayer.push({ event: name, event_id: eventId, ecommerce: { currency: 'BRL', value: brl(value), items } });
    } catch (e) { /* pixel bloqueado por extensão: segue o servidor */ }

    // Servidor (analytics próprio + API de Conversões). Purchase vai só pelo servidor (webhook).
    if (name !== 'purchase') {
        const body = JSON.stringify({
            name, event_id: eventId, url: location.href, referrer: document.referrer || null,
            items: items.map((i) => ({ variant_id: i.variant_id, quantity: i.quantity || 1 })), search: data.search || null,
        });
        const blob = new Blob([body], { type: 'application/json' });
        if (!(navigator.sendBeacon && navigator.sendBeacon('/t', blob))) {
            fetch('/t', { method: 'POST', body, headers: { 'Content-Type': 'application/json' }, keepalive: true, credentials: 'same-origin' }).catch(() => {});
        }
    }
    return eventId;
}

function boot() {
    initPixels();
    track('page_view');

    // Fila de eventos declarados pela página (produto, busca, compra...)
    const q = window.sdq || [];
    window.sdq = { push: ([n, d]) => track(n, d) };
    q.forEach(([n, d]) => track(n, d));

    // Eventos de interação disparados pelo restante do JS da loja
    window.addEventListener('sd:add_to_cart', (e) => track('add_to_cart', { items: e.detail.items }));
    window.addEventListener('sd:begin_checkout', (e) => track('begin_checkout', { items: e.detail.items, value: e.detail.value }));
    window.addEventListener('sd:add_payment_info', (e) => track('add_payment_info', { items: e.detail.items, value: e.detail.value }));
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
else boot();
