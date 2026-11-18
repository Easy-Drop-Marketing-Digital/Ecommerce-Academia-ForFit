<section class="page-width">
    <div class="benefits" aria-label="Vantagens da loja">
        @foreach ($items as $b)
            <div class="benefit">
                <span class="benefit__icon"><x-ui-icon :name="$b['icon']" /></span>
                <div><strong>{{ $b['title'] }}</strong><span>{{ $b['text'] }}</span></div>
            </div>
        @endforeach
    </div>
</section>
