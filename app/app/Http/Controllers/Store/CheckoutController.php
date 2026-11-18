<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutException;
use App\Services\Checkout\CheckoutService;
use App\Services\Payments\CheckoutPayment;
use App\Services\Payments\GatewayException;
use App\Support\Br;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __construct(private CartService $carts, private CheckoutService $checkout) {}

    public function show(Request $request)
    {
        $cart = $this->carts->current();
        if (! $cart || $cart->items->isEmpty()) {
            return redirect()->route('cart.show');
        }

        $customer = auth('customer')->user();
        $address = $customer?->addresses()->where('is_default', true)->latest()->first();
        $saved = $cart->checkout_data ?? [];

        $prefill = array_filter([
            'email' => $customer->email ?? $cart->email ?? $saved['email'] ?? null,
            'name' => $customer->name ?? $cart->name ?? $saved['name'] ?? null,
            'phone' => $customer->phone ?? $cart->phone ?? $saved['phone'] ?? null,
            'document' => $customer->document ?? $saved['document'] ?? null,
            'zip' => $address->zip ?? $saved['zip'] ?? null,
            'street' => $address->street ?? $saved['street'] ?? null,
            'number' => $address->number ?? $saved['number'] ?? null,
            'complement' => $address->complement ?? $saved['complement'] ?? null,
            'neighborhood' => $address->neighborhood ?? $saved['neighborhood'] ?? null,
            'city' => $address->city ?? $saved['city'] ?? null,
            'state' => $address->state ?? $saved['state'] ?? null,
        ]);

        // Cupom vindo de link (/discount/CUPOM ou /cart/...?discount=): já entra aplicado
        $code = $cart->discount_code ?: session('discount_code');
        $quote = $this->checkout->quote($cart, ['payment_method' => 'pix'] + $prefill + ($code ? ['code' => $code] : []));
        if ($code && ($quote['code_status'] ?? null) !== 'applied') {
            $code = null;
            $quote = $this->checkout->quote($cart, ['payment_method' => 'pix'] + $prefill);
        }

        return view('store.checkout', [
            'quote' => $quote,
            'cart' => $this->carts->toArray($cart),
            'prefill' => $prefill,
            'coupon' => $code,
        ]);
    }

    /** Recalcula valores (frete, cupom, forma de pagamento, parcelas). */
    public function quote(Request $request)
    {
        $cart = $this->carts->current();
        if (! $cart || $cart->items->isEmpty()) {
            return response()->json(['error' => 'Seu carrinho está vazio.', 'redirect' => route('cart.show')], 422);
        }

        return response()->json($this->publicQuote($this->checkout->quote($cart, $request->only(['email', 'code', 'payment_method', 'shipping_method_id', 'installments']))));
    }

    /**
     * Captura os dados conforme a cliente preenche (sem cartão!) — base da recuperação de carrinho abandonado.
     */
    public function capture(Request $request)
    {
        $cart = $this->carts->current();
        if (! $cart) {
            return response()->json(['ok' => false]);
        }
        $fields = $request->only(['email', 'name', 'phone', 'document', 'zip', 'street', 'number', 'complement', 'neighborhood', 'city', 'state']);
        $fields = array_map(fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 190) : null, $fields);

        $cart->forceFill([
            'checkout_data' => array_filter(array_merge($cart->checkout_data ?? [], $fields)),
            'email' => filter_var($fields['email'] ?? null, FILTER_VALIDATE_EMAIL) ? mb_strtolower($fields['email']) : $cart->email,
            'name' => $fields['name'] ?? $cart->name,
            'phone' => ! empty($fields['phone']) ? Br::digits($fields['phone']) : $cart->phone,
            'checkout_started_at' => $cart->checkout_started_at ?? now(),
        ])->save();

        return response()->json(['ok' => true]);
    }

    public function place(Request $request, CheckoutPayment $payment)
    {
        $cart = $this->carts->current();
        if (! $cart || $cart->items->isEmpty()) {
            return response()->json(['error' => 'Seu carrinho está vazio.', 'redirect' => route('cart.show')], 422);
        }

        $data = $this->validated($request);

        try {
            $order = $this->checkout->place($cart, $data, [
                'attribution' => $request->session()->get('attribution') ?? $cart->attribution,
                'fbp' => $request->cookie('_fbp'),
                'fbc' => $request->cookie('_fbc'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'visitor_id' => $request->cookie('sd_vid'),
                'ttclid' => $request->session()->get('attribution.ttclid'),
                'ttp' => $request->cookie('_ttp'),
                'ga_client_id' => self::gaClientId($request->cookie('_ga')),
                'page_url' => $request->headers->get('referer'),
            ]);
        } catch (CheckoutException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Carrinho vira pedido: o próximo acesso começa um carrinho novo
        $cart->forceFill(['status' => 'converted'])->save();

        try {
            if ($data['payment_method'] === 'credit_card') {
                $payment->card($order, [
                    'number' => $data['card_number'],
                    'holder_name' => $data['card_holder'],
                    'exp_month' => $data['card_exp_month'],
                    'exp_year' => $data['card_exp_year'],
                    'cvv' => $data['card_cvv'],
                ], $request->ip());
            } else {
                $payment->pix($order);
            }
        } catch (GatewayException $e) {
            // Pedido fica criado e pendente: a cliente tenta de novo na página do pedido (outro cartão ou PIX)
            return response()->json(['error' => $e->getMessage(), 'redirect' => $order->publicUrl().'?erro=1'], 402);
        }

        return response()->json(['redirect' => $order->publicUrl()]);
    }

    private function validated(Request $request): array
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'document' => Br::digits($request->input('document')),
            'phone' => Br::digits($request->input('phone')),
            'zip' => Br::digits($request->input('zip')),
            'state' => strtoupper((string) $request->input('state')),
            'card_number' => Br::digits($request->input('card_number')),
        ]);

        $isCard = $request->input('payment_method') === 'credit_card';

        $data = $request->validate([
            'email' => ['required', 'email:filter', 'max:190'],
            'name' => ['required', 'string', 'min:5', 'max:120', 'regex:/\S+\s+\S+/u'],
            'phone' => ['required'],
            'document' => ['required', 'digits:11'],
            'zip' => ['required', 'digits:8'],
            'street' => ['required', 'string', 'max:190'],
            'number' => ['required', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:120'],
            'neighborhood' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO'],
            'shipping_method_id' => ['nullable', 'integer'],
            'coupon' => ['nullable', 'string', 'max:60'],
            'payment_method' => ['required', 'in:pix,credit_card'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:12'],
            'accepts_marketing' => ['nullable', 'boolean'],
            'card_number' => [$isCard ? 'required' : 'nullable', 'digits_between:13,19'],
            'card_holder' => [$isCard ? 'required' : 'nullable', 'string', 'max:60'],
            'card_exp_month' => [$isCard ? 'required' : 'nullable', 'integer', 'between:1,12'],
            'card_exp_year' => [$isCard ? 'required' : 'nullable', 'integer'],
            'card_cvv' => [$isCard ? 'required' : 'nullable', 'digits_between:3,4'],
        ], [
            'name.regex' => 'Informe nome e sobrenome.',
            'document.digits' => 'CPF inválido.',
            'zip.digits' => 'CEP inválido.',
            'card_number.digits_between' => 'Número do cartão inválido.',
            'card_cvv.digits_between' => 'Código de segurança inválido.',
        ], [
            'email' => 'e-mail', 'name' => 'nome completo', 'phone' => 'celular', 'document' => 'CPF', 'zip' => 'CEP',
            'street' => 'endereço', 'number' => 'número', 'neighborhood' => 'bairro', 'city' => 'cidade', 'state' => 'estado',
            'card_number' => 'número do cartão', 'card_holder' => 'nome impresso no cartão', 'card_exp_month' => 'mês de validade',
            'card_exp_year' => 'ano de validade', 'card_cvv' => 'código de segurança',
        ]);

        $errors = [];
        if (! Br::validCpf($data['document'])) {
            $errors['document'] = 'CPF inválido.';
        }
        if (! Br::validPhone($data['phone'])) {
            $errors['phone'] = 'Celular inválido. Informe com DDD.';
        }
        if ($isCard) {
            if (! Br::luhn($data['card_number'])) {
                $errors['card_number'] = 'Número do cartão inválido.';
            }
            $year = (int) $data['card_exp_year'] < 100 ? 2000 + (int) $data['card_exp_year'] : (int) $data['card_exp_year'];
            $data['card_exp_year'] = $year;
            if ($year < (int) now()->year || ($year === (int) now()->year && (int) $data['card_exp_month'] < (int) now()->month) || $year > (int) now()->year + 20) {
                $errors['card_exp_month'] = 'Cartão vencido ou validade inválida.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /** Cookie _ga "GA1.1.123456789.1700000000" → client_id "123456789.1700000000" */
    private static function gaClientId(?string $ga): ?string
    {
        return $ga && preg_match('/^GA\d\.\d+\.(\d+\.\d+)$/', $ga, $m) ? $m[1] : null;
    }

    /** Remove dados internos antes de mandar a cotação ao navegador. */
    private function publicQuote(array $q): array
    {
        unset($q['lines'], $q['line_discounts']);
        $q['discounts'] = array_map(fn ($d) => ['title' => $d['title'], 'code' => $d['code'], 'amount' => $d['amount'], 'free_shipping' => $d['free_shipping']], $q['discounts']);

        return $q;
    }
}
