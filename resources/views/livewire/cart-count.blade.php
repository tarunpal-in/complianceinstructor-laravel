<a class="icon-button cart-link" href="{{ route('cart.index') }}"
   aria-label="Shopping cart{{ $count > 0 ? ', '.$count.' '.\Illuminate\Support\Str::plural('item', $count) : '' }}">
    <x-ui.icon name="shopping-cart" :size="20" />
    @if ($count > 0)
        <span class="cart-badge" aria-hidden="true">{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</a>
