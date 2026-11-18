/*
 * Checkout de uma página: identificação → entrega → pagamento.
 * Valores sempre recalculados no servidor (/checkout/quote); o navegador só exibe.
 * Dados do cartão: enviados uma única vez no POST /checkout e nunca guardados no navegador.
 */
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;
const digits = (v) => String(v || '').replace(/\D/g, '');
const money = (c) => 'R$ ' + ((c || 0) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(body),
        credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data };
}

export const masks = {
    cpf: (v) => digits(v).slice(0, 11).replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2'),
    phone: (v) => {
        const d = digits(v).slice(0, 11);
        if (d.length <= 10) return d.replace(/(\d{2})(\d)/, '($1) $2').replace(/(\d{4})(\d)/, '$1-$2');
        return d.replace(/(\d{2})(\d)/, '($1) $2').replace(/(\d{5})(\d)/, '$1-$2');
    },
    cep: (v) => digits(v).slice(0, 8).replace(/(\d{5})(\d)/, '$1-$2'),
    card: (v) => digits(v).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 '),
    exp: (v) => digits(v).slice(0, 4).replace(/(\d{2})(\d)/, '$1/$2'),
};

export function validCpf(v) {
    const c = digits(v);
    if (c.length !== 11 || /^(\d)\1{10}$/.test(c)) return false;
    for (let t = 9; t < 11; t++) {
        let s = 0;
        for (let i = 0; i < t; i++) s += +c[i] * (t + 1 - i);
        if (+c[t] !== ((10 * s) % 11) % 10) return false;
    }
    return true;
}

function luhn(v) {
    const n = digits(v);
    if (n.length < 13) return false;
    let sum = 0, alt = false;
    for (let i = n.length - 1; i >= 0; i--) {
        let d = +n[i];
        if (alt) { d *= 2; if (d > 9) d -= 9; }
        sum += d; alt = !alt;
    }
    return sum % 10 === 0;
}

export function cardBrand(v) {
    const n = digits(v);
    if (/^4/.test(n)) return 'Visa';
    if (/^(5[1-5]|2[2-7])/.test(n)) return 'Mastercard';
    if (/^3[47]/.test(n)) return 'Amex';
    if (/^(4011|4312|4389|4514|4573|5041|5066|5067|509|6277|6362|6363|650|6516|6550)/.test(n)) return 'Elo';
    if (/^(606282|3841)/.test(n)) return 'Hipercard';
    return null;
}

export default (cfg) => ({
    f: Object.assign({
        email: '', name: '', phone: '', document: '', zip: '', street: '', number: '', complement: '',
        neighborhood: '', city: '', state: '', accepts_marketing: true,
    }, cfg.prefill || {}),
    card: { number: '', holder: '', exp: '', cvv: '' },
    coupon: cfg.coupon || '',
    couponOpen: !!cfg.coupon,
    method: 'pix',
    shippingId: cfg.quote.shipping_method_id,
    installments: 1,
    q: cfg.quote,
    errors: {},
    loading: false,
    submitting: false,
    cepLoading: false,
    addressLocked: false,
    summaryOpen: false,
    money,
    masks,

    init() {
        if (this.f.document) this.f.document = masks.cpf(this.f.document);
        if (this.f.phone) this.f.phone = masks.phone(this.f.phone);
        if (this.f.zip) { this.f.zip = masks.cep(this.f.zip); this.addressLocked = !!this.f.street; }
        this.$watch('method', () => this.refresh());
        this.$watch('shippingId', () => this.refresh());
        this.$watch('installments', () => this.refresh());
        window.dispatchEvent(new CustomEvent('sd:begin_checkout', { detail: { value: this.q.total, items: cfg.items || [] } }));
    },

    get brand() { return cardBrand(this.card.number); },
    get installmentOptions() { return this.q.installment_plan || []; },

    async refresh() {
        this.loading = true;
        const { ok, data } = await postJson('/checkout/quote', {
            email: this.f.email, code: this.coupon || null, payment_method: this.method,
            shipping_method_id: this.shippingId, installments: this.installments,
        });
        this.loading = false;
        if (!ok) {
            if (data.redirect) window.location = data.redirect;
            return;
        }
        this.q = data;
        if (this.installments > data.installment_plan.length) this.installments = data.installment_plan.length;
    },

    async applyCoupon() {
        if (!this.coupon.trim()) return;
        await this.refresh();
        if (this.q.code_status && this.q.code_status !== 'applied') Alpine.store('ui').notify(this.q.code_message);
    },
    removeCoupon() { this.coupon = ''; this.refresh(); },

    capture() {
        // Captura para recuperação de carrinho (sem dados de cartão)
        const { email, name, phone, document, zip, street, number, complement, neighborhood, city, state } = this.f;
        postJson('/checkout/capture', { email, name, phone, document, zip, street, number, complement, neighborhood, city, state });
    },

    async lookupCep() {
        const cep = digits(this.f.zip);
        if (cep.length !== 8) return;
        this.cepLoading = true;
        try {
            const r = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
            const d = await r.json();
            if (d.erro) {
                this.errors.zip = 'CEP não encontrado. Confira ou preencha o endereço.';
                this.addressLocked = true;
            } else {
                delete this.errors.zip;
                this.f.street = d.logradouro || this.f.street;
                this.f.neighborhood = d.bairro || this.f.neighborhood;
                this.f.city = d.localidade || this.f.city;
                this.f.state = d.uf || this.f.state;
                this.addressLocked = true;
                this.$nextTick(() => (this.f.street ? this.$refs.number : this.$refs.street)?.focus());
            }
        } catch (e) {
            this.addressLocked = true; // sem internet para o ViaCEP: libera digitação manual
        } finally {
            this.cepLoading = false;
            this.capture();
        }
    },

    validate() {
        const e = {};
        const f = this.f;
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(f.email.trim())) e.email = 'Informe um e-mail válido.';
        if (!/\S+\s+\S+/.test(f.name.trim())) e.name = 'Informe nome e sobrenome.';
        if (!validCpf(f.document)) e.document = 'CPF inválido.';
        if (!/^[1-9]{2}\d{8,9}$/.test(digits(f.phone))) e.phone = 'Celular inválido. Informe com DDD.';
        if (digits(f.zip).length !== 8) e.zip = 'CEP inválido.';
        if (!f.street.trim()) e.street = 'Informe o endereço.';
        if (!String(f.number).trim()) e.number = 'Informe o número (ou "S/N").';
        if (!f.neighborhood.trim()) e.neighborhood = 'Informe o bairro.';
        if (!f.city.trim()) e.city = 'Informe a cidade.';
        if (!/^[A-Za-z]{2}$/.test(f.state)) e.state = 'UF inválida.';
        if (this.method === 'credit_card') {
            if (!luhn(this.card.number)) e.card_number = 'Número do cartão inválido.';
            if (this.card.holder.trim().length < 3) e.card_holder = 'Informe o nome impresso no cartão.';
            const [m, y] = this.card.exp.split('/');
            const now = new Date();
            const yy = 2000 + (+y || 0);
            if (!(+m >= 1 && +m <= 12) || yy < now.getFullYear() || (yy === now.getFullYear() && +m < now.getMonth() + 1)) e.card_exp = 'Validade inválida.';
            if (!/^\d{3,4}$/.test(this.card.cvv)) e.card_cvv = 'CVV inválido.';
        }
        this.errors = e;
        if (Object.keys(e).length) {
            this.$nextTick(() => document.querySelector('[data-error="true"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
            return false;
        }
        return true;
    },

    async submit() {
        if (this.submitting || !this.validate()) return;
        this.submitting = true;
        const [m, y] = this.card.exp.split('/');
        const body = {
            ...this.f,
            document: digits(this.f.document), phone: digits(this.f.phone), zip: digits(this.f.zip),
            coupon: this.coupon || null, payment_method: this.method,
            shipping_method_id: this.shippingId, installments: this.installments,
        };
        if (this.method === 'credit_card') {
            Object.assign(body, {
                card_number: digits(this.card.number), card_holder: this.card.holder,
                card_exp_month: +m, card_exp_year: 2000 + +y, card_cvv: this.card.cvv,
            });
        }
        window.dispatchEvent(new CustomEvent('sd:add_payment_info', { detail: { method: this.method, value: this.q.total, items: cfg.items || [] } }));

        const { ok, status, data } = await postJson('/checkout', body);
        // Limpa o cartão da memória do navegador assim que enviado
        this.card.cvv = '';
        if (ok && data.redirect) {
            window.location = data.redirect;
            return;
        }
        this.submitting = false;
        if (status === 422 && data.errors) {
            this.errors = Object.fromEntries(Object.entries(data.errors).map(([k, v]) => [k === 'card_exp_month' || k === 'card_exp_year' ? 'card_exp' : k, v[0]]));
            this.$nextTick(() => document.querySelector('[data-error="true"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
            return;
        }
        if (data.redirect) {
            // Pedido criado mas pagamento não aprovado: segue para a página do pedido com nova tentativa
            Alpine.store('ui').notify(data.error);
            setTimeout(() => (window.location = data.redirect), 1800);
            return;
        }
        Alpine.store('ui').notify(status >= 500 || !data.error
            ? 'Tivemos uma instabilidade ao finalizar. Nenhum valor foi cobrado — tente novamente em instantes.'
            : data.error);
    },
});
