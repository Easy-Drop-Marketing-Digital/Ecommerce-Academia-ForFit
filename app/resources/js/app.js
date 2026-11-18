import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import checkout, { masks, cardBrand } from './checkout';
import './tracking';

Alpine.plugin(collapse);
Alpine.data('checkout', checkout);

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

const money = (cents) =>
    'R$ ' + (cents / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function post(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(body),
        credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || data.message || 'Não foi possível atualizar o carrinho.');
    return data;
}

/* ------------------------------------------------------------------
 * Estado global: carrinho, gavetas e avisos
 * ------------------------------------------------------------------ */
document.addEventListener('alpine:init', () => {
    Alpine.store('ui', {
        menu: false,
        search: false,
        toast: null,
        _t: null,
        notify(msg) {
            this.toast = msg;
            clearTimeout(this._t);
            this._t = setTimeout(() => (this.toast = null), 3500);
        },
    });

    Alpine.store('cart', {
        open: false,
        busy: false,
        data: window.__CART__ || { count: 0, items: [], subtotal: 0 },
        money,

        async add(variantId, quantity = 1, meta = {}) {
            if (this.busy) return;
            this.busy = true;
            try {
                this.data = await post('/cart/add', { variant_id: variantId, quantity });
                this.open = true;
                // AddToCart: Pixel + API de Conversões com o mesmo event_id (tracking.js)
                const line = this.data.items.find((i) => i.variant_id === variantId);
                if (line) window.dispatchEvent(new CustomEvent('sd:add_to_cart', { detail: { items: [{ variant_id: variantId, content_id: line.content_id, name: line.title, price: line.price, quantity }] } }));
            } catch (e) {
                Alpine.store('ui').notify(e.message);
            } finally {
                this.busy = false;
            }
        },

        async update(itemId, quantity) {
            if (this.busy) return;
            this.busy = true;
            try {
                this.data = await post('/cart/update', { item_id: itemId, quantity });
            } catch (e) {
                Alpine.store('ui').notify(e.message);
            } finally {
                this.busy = false;
            }
        },
    });
});

/* ------------------------------------------------------------------
 * Slideshow (banners da home) — autoplay, swipe e pontos
 * ------------------------------------------------------------------ */
Alpine.data('slideshow', (count, autoplay = 0) => ({
    i: 0,
    count,
    timer: null,
    startX: null,
    init() {
        this.play();
        document.addEventListener('visibilitychange', () => (document.hidden ? this.stop() : this.play()));
    },
    play() {
        if (autoplay && this.count > 1) {
            this.stop();
            // valor pequeno foi digitado em segundos (ex.: 6) — sem isso o banner troca a cada 6 ms e "fica arrastando"
            const ms = autoplay < 1000 ? autoplay * 1000 : autoplay;
            this.timer = setInterval(() => this.next(), Math.max(ms, 2500));
        }
    },
    stop() { clearInterval(this.timer); },
    go(n) { this.i = (n + this.count) % this.count; this.play(); },
    next() { this.go(this.i + 1); },
    prev() { this.go(this.i - 1); },
    touchStart(e) { this.startX = e.touches[0].clientX; this.stop(); },
    touchEnd(e) {
        if (this.startX === null) return;
        const dx = e.changedTouches[0].clientX - this.startX;
        if (Math.abs(dx) > 40) (dx < 0 ? this.next() : this.prev());
        this.startX = null;
        this.play();
    },
}));

/* Setas dos carrosséis horizontais */
Alpine.data('scroller', () => ({
    scroll(dir) {
        const el = this.$refs.track;
        el.scrollBy({ left: dir * el.clientWidth * 0.8, behavior: 'smooth' });
    },
}));

/*
 * Arrastar os carrosséis com o mouse (no celular o dedo já rola nativamente).
 * Ao soltar, encaixa no item mais próximo na direção do arraste; um arraste não abre o link.
 */
function dragScroll(el) {
    let down = false, dragging = false, startX = 0, startLeft = 0, lastX = 0, lastT = 0, velocity = 0, justDragged = false;

    el.addEventListener('pointerdown', (e) => {
        if (e.pointerType !== 'mouse' || e.button !== 0) return;
        down = true; dragging = false;
        startX = lastX = e.clientX; startLeft = el.scrollLeft; lastT = performance.now(); velocity = 0;
    });
    el.addEventListener('pointermove', (e) => {
        if (!down) return;
        const dx = e.clientX - startX;
        if (!dragging && Math.abs(dx) > 6) {
            dragging = true;
            el.classList.add('is-dragging');
            el.setPointerCapture(e.pointerId);
        }
        if (!dragging) return;
        const now = performance.now();
        velocity = (e.clientX - lastX) / Math.max(1, now - lastT); // px/ms
        lastX = e.clientX; lastT = now;
        el.scrollLeft = startLeft - dx;
    });
    const end = () => {
        if (!down) return;
        down = false;
        if (!dragging) return;
        dragging = false;
        justDragged = true;
        setTimeout(() => (justDragged = false), 50);
        // encaixe: projeta o embalo (~180ms) e para no card mais próximo
        const target = el.scrollLeft - velocity * 180;
        const items = [...el.children];
        const base = items[0]?.offsetLeft ?? 0;
        const best = items.reduce((a, c) => (Math.abs(c.offsetLeft - base - target) < Math.abs(a.offsetLeft - base - target) ? c : a), items[0]);
        el.classList.remove('is-dragging');
        if (best) el.scrollTo({ left: best.offsetLeft - base, behavior: 'smooth' });
    };
    el.addEventListener('pointerup', end);
    el.addEventListener('pointercancel', end);
    el.addEventListener('lostpointercapture', end);
    // depois de arrastar, o clique não navega
    el.addEventListener('click', (e) => { if (justDragged) { e.preventDefault(); e.stopPropagation(); } }, true);
    // impede o "arrastar imagem/link" nativo do navegador
    el.addEventListener('dragstart', (e) => e.preventDefault());
}
document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.scroller').forEach(dragScroll));

/* ------------------------------------------------------------------
 * Página de produto: variações, galeria, quantidade, barra fixa
 * ------------------------------------------------------------------ */
Alpine.data('productPage', (cfg) => ({
    options: cfg.options,
    variants: cfg.variants,
    selected: [...(cfg.selected?.options || [])],
    img: cfg.selected?.image ?? 0,
    qty: 1,
    payModal: false,
    showSticky: false,
    money,

    init() {
        const io = new IntersectionObserver(([e]) => (this.showSticky = !e.isIntersecting && e.boundingClientRect.top < 0));
        io.observe(this.$refs.buy);
        this.$watch('img', (i) => {
            const slides = this.$refs.slides;
            if (slides && slides.children[i]) slides.scrollTo({ left: slides.children[i].offsetLeft, behavior: 'smooth' });
        });
    },

    get variant() {
        return this.variants.find((v) => v.options.every((o, k) => o === this.selected[k])) || null;
    },
    get price() { return this.variant ? this.variant.price : cfg.fallbackPrice; },
    get compare() { return this.variant && this.variant.compare > this.variant.price ? this.variant.compare : null; },
    get off() { return this.compare ? Math.round(100 - (this.price * 100) / this.compare) : null; },
    get available() { return !!(this.variant && this.variant.available); },

    pick(optIndex, value) {
        this.selected[optIndex] = value;
        // Se a combinação não existe, ajusta as outras opções para a primeira disponível com esse valor
        if (!this.variant) {
            const match = this.variants.find((v) => v.options[optIndex] === value && v.available)
                || this.variants.find((v) => v.options[optIndex] === value);
            if (match) this.selected = [...match.options];
        }
        if (this.variant && this.variant.image !== null) this.img = this.variant.image;
        const url = new URL(window.location);
        if (this.variant) url.searchParams.set('variant', this.variant.id);
        history.replaceState(null, '', url);
    },

    /** Valor existe em alguma variação disponível, mantendo as demais escolhas? */
    isAvailable(optIndex, value) {
        return this.variants.some((v) => v.available && v.options[optIndex] === value
            && v.options.every((o, k) => k === optIndex || k > optIndex || o === this.selected[k]));
    },

    onScrollSlides(e) {
        const el = e.target;
        const i = Math.round(el.scrollLeft / el.clientWidth);
        if (i !== this.img) this.img = i;
    },

    buy() {
        if (!this.variant) return Alpine.store('ui').notify('Selecione as opções do produto.');
        if (!this.available) return Alpine.store('ui').notify('Esta opção está esgotada.');
        Alpine.store('cart').add(this.variant.id, this.qty, { productId: cfg.productId, price: this.price });
    },
}));

/* ------------------------------------------------------------------
 * Página do pedido: polling do PIX, cópia do código, nova tentativa
 * ------------------------------------------------------------------ */
Alpine.data('orderPage', (cfg) => ({
    paid: !!cfg.paid,
    copied: false,
    remaining: '',
    method: 'pix',
    installments: 1,
    plan: cfg.plan,
    card: { number: '', holder: '', exp: '', cvv: '' },
    busy: false,
    error: null,
    retryOpen: false,
    masks,
    money,

    init() {
        if (cfg.polling) this.poll();
        if (cfg.pixExpiresAt) {
            const end = new Date(cfg.pixExpiresAt).getTime();
            const tick = () => {
                const s = Math.max(0, Math.floor((end - Date.now()) / 1000));
                const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
                this.remaining = s ? (h ? `${h}h ${String(m).padStart(2, '0')}min` : `${m}:${String(sec).padStart(2, '0')}`) : '';
            };
            tick();
            setInterval(tick, 1000);
        }
    },

    async poll() {
        let delay = 4000;
        const run = async () => {
            if (document.hidden) return setTimeout(run, delay);
            try {
                const r = await fetch(cfg.statusUrl, { headers: { Accept: 'application/json' } });
                const d = await r.json();
                if (d.paid) { this.paid = true; window.location.reload(); return; }
                if (['canceled', 'refunded'].includes(d.status) || ['refused', 'failed'].includes(d.payment_status)) { window.location.reload(); return; }
            } catch (e) { /* rede instável: tenta de novo */ }
            delay = Math.min(15000, delay + 1000);
            setTimeout(run, delay);
        };
        setTimeout(run, delay);
    },

    async copyPix() {
        try { await navigator.clipboard.writeText(cfg.pixCode); } catch (e) {
            const t = document.getElementById('pix-code'); t.select(); document.execCommand('copy');
        }
        this.copied = true;
        setTimeout(() => (this.copied = false), 2500);
    },

    async retry() {
        if (this.busy) return;
        this.busy = true; this.error = null;
        const [m, y] = this.card.exp.split('/');
        const body = { payment_method: this.method, installments: this.installments };
        if (this.method === 'credit_card') Object.assign(body, {
            card_number: this.card.number.replace(/\D/g, ''), card_holder: this.card.holder,
            card_exp_month: +m, card_exp_year: 2000 + (+y || 0), card_cvv: this.card.cvv,
        });
        const res = await fetch(cfg.retryUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify(body),
        });
        const d = await res.json().catch(() => ({}));
        this.card.cvv = '';
        if (res.ok && d.redirect) { window.location = d.redirect; return; }
        this.busy = false;
        this.error = d.error || (d.errors ? Object.values(d.errors)[0][0] : 'Não foi possível processar. Tente novamente.');
        if (d.redirect && !res.ok && res.status !== 402) window.location = d.redirect;
    },
}));

/* ------------------------------------------------------------------
 * Avaliações na página de produto: carregar mais e filtrar por nota
 * ------------------------------------------------------------------ */
Alpine.data('reviewsList', (cfg) => ({
    items: cfg.items,
    next: cfg.next,
    rating: null,
    loading: false,

    async load(page, replace) {
        this.loading = true;
        const params = new URLSearchParams({ page });
        if (this.rating) params.set('nota', this.rating);
        try {
            const d = await fetch(`${cfg.url}?${params}`, { headers: { Accept: 'application/json' } }).then((r) => r.json());
            this.items = replace ? d.data : this.items.concat(d.data);
            this.next = d.next;
        } finally {
            this.loading = false;
        }
    },
    more() { if (this.next) this.load(this.next, false); },
    filter(star) { this.rating = star; this.load(1, true); },
}));

window.Alpine = Alpine;
Alpine.start();
