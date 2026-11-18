<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Cart\CartService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Minha conta. Quem comprou como convidada já tem cadastro pelo e-mail do pedido —
 * basta "criar senha" (fluxo de recuperação) para ver o histórico.
 */
class AccountController extends Controller
{
    public function loginForm()
    {
        return view('store.account.login');
    }

    public function login(Request $request, CartService $carts)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $customer = Customer::where('email', mb_strtolower($data['email']))->first();

        if ($customer && ! $customer->password) {
            return back()->withInput()->withErrors(['email' => 'Você ainda não criou uma senha. Use “Criar ou recuperar senha” abaixo para receber o link por e-mail.']);
        }
        if (! Auth::guard('customer')->attempt(['email' => mb_strtolower($data['email']), 'password' => $data['password']], $request->boolean('remember'))) {
            return back()->withInput()->withErrors(['email' => 'E-mail ou senha incorretos.']);
        }
        $request->session()->regenerate();
        $this->attachCart($carts);

        return redirect()->intended(route('account.index'));
    }

    public function registerForm()
    {
        return view('store.account.register');
    }

    public function register(Request $request, CartService $carts)
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email:filter', 'max:190'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'accepts_marketing' => ['nullable', 'boolean'],
        ], [], ['name' => 'nome', 'password' => 'senha']);

        $customer = Customer::where('email', $data['email'])->first();
        if ($customer && $customer->password) {
            return back()->withInput()->withErrors(['email' => 'Já existe uma conta com este e-mail. Faça login ou recupere a senha.']);
        }
        if ($customer) {
            // Comprou como convidada: por segurança, a senha só pode ser definida pelo link enviado ao e-mail
            Password::broker('customers')->sendResetLink(['email' => $data['email']]);

            return redirect()->route('account.login')->with('status', 'Você já comprou com este e-mail! Enviamos um link para criar sua senha e acessar seus pedidos.');
        }

        $customer = Customer::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'accepts_marketing' => $request->boolean('accepts_marketing'),
        ]);
        Auth::guard('customer')->login($customer, true);
        $request->session()->regenerate();
        $this->attachCart($carts);
        $id = $customer->id;
        dispatch(fn () => app(\App\Services\Mail\OrderEmails::class)->welcome(Customer::find($id)))->afterResponse();

        return redirect()->route('account.index');
    }

    public function forgotForm()
    {
        return view('store.account.forgot');
    }

    public function forgot(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::broker('customers')->sendResetLink(['email' => mb_strtolower($request->input('email'))]);

        // Mesma resposta exista ou não a conta (não revela quais e-mails são clientes)
        return back()->with('status', 'Se houver uma conta com este e-mail, você receberá um link para criar uma nova senha em instantes.');
    }

    public function resetForm(Request $request, string $token)
    {
        return view('store.account.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [], ['password' => 'senha']);

        $status = Password::broker('customers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Customer $customer, string $password) {
                $customer->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($customer));
                Auth::guard('customer')->login($customer);
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('account.index')->with('status', 'Senha criada com sucesso!')
            : back()->withErrors(['email' => 'Link inválido ou expirado. Peça um novo.']);
    }

    public function index()
    {
        $customer = Auth::guard('customer')->user();

        return view('store.account.index', [
            'customer' => $customer,
            'orders' => $customer->orders()->with('items')->paginate(10),
        ]);
    }

    public function order(int $number)
    {
        $order = Auth::guard('customer')->user()->orders()->where('number', $number)->firstOrFail();

        return redirect($order->publicUrl());
    }

    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function attachCart(CartService $carts): void
    {
        if ($cart = $carts->current()) {
            $cart->forceFill(['customer_id' => Auth::guard('customer')->id()])->save();
        }
    }
}
