<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $order->number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #1f2937; font-size: 13px; line-height: 1.5; margin: 0; padding: 40px; }
        .inv-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 36px; }
        .inv-brand { font-size: 20px; font-weight: 700; color: #1e5684; }
        .inv-brand small { display: block; font-size: 11px; font-weight: 400; color: #6b7280; margin-top: 2px; }
        .inv-meta { text-align: right; font-size: 12px; color: #4b5563; }
        .inv-meta .inv-title { font-size: 22px; font-weight: 700; color: #111827; letter-spacing: 1px; }
        .inv-parties { display: flex; justify-content: space-between; margin-bottom: 28px; }
        .inv-parties h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin: 0 0 6px; }
        .inv-parties p { margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; border-bottom: 2px solid #e5e7eb; padding: 8px 6px; }
        th.num, td.num { text-align: right; }
        td { padding: 10px 6px; border-bottom: 1px solid #f1f3f5; vertical-align: top; }
        .inv-desc small { display: block; color: #6b7280; font-size: 11px; }
        .inv-totals { width: 44%; margin-left: auto; }
        .inv-totals td { border: none; padding: 4px 6px; }
        .inv-totals .inv-grand td { border-top: 2px solid #e5e7eb; font-weight: 700; font-size: 15px; color: #111827; padding-top: 10px; }
        .inv-foot { margin-top: 48px; padding-top: 16px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 11px; }
        .inv-status { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; background: #dcfce7; color: #166534; }
    </style>
</head>
<body>
    <div class="inv-head">
        <div>
            <div class="inv-brand">{{ $businessName }}@if ($businessTagline)<small>{{ $businessTagline }}</small>@endif</div>
        </div>
        <div class="inv-meta">
            <div class="inv-title">INVOICE</div>
            <div>{{ $order->number }}</div>
            <div>{{ optional($order->placed_at)->format('j M Y') ?? now()->format('j M Y') }}</div>
            <div style="margin-top:6px"><span class="inv-status">{{ $status }}</span></div>
        </div>
    </div>

    <div class="inv-parties">
        <div>
            <h3>From</h3>
            <p><strong>{{ $businessLegalName }}</strong></p>
            @foreach ($businessAddress as $line)
                <p>{{ $line }}</p>
            @endforeach
            @if ($businessEmail)<p>{{ $businessEmail }}</p>@endif
            @if ($businessPhone)<p>{{ $businessPhone }}</p>@endif
        </div>
        <div style="text-align:right">
            <h3>Billed to</h3>
            <p><strong>{{ $billTo['name'] }}</strong></p>
            @if (!empty($billTo['company']))<p>{{ $billTo['company'] }}</p>@endif
            <p>{{ $billTo['email'] }}</p>
            @if (!empty($billTo['country']))<p>{{ $billTo['country'] }}</p>@endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    @php $lineFormats = $item->formatEnums()->map(fn ($f) => $f->label())->implode(', '); @endphp
                    <td class="inv-desc">
                        {{ $item->description }}
                        @if ($lineFormats !== '')<small>{{ $lineFormats }}</small>@endif
                    </td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ \App\Support\Money::format($item->unit_price_cents, $order->currency) }}</td>
                    <td class="num">{{ \App\Support\Money::format($item->line_total_cents, $order->currency) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="inv-totals">
        <tr>
            <td>Subtotal</td>
            <td class="num">{{ \App\Support\Money::format($order->subtotal_cents, $order->currency) }}</td>
        </tr>
        @if ($order->discount_cents > 0)
            <tr>
                <td>Discount</td>
                <td class="num">&minus;{{ \App\Support\Money::format($order->discount_cents, $order->currency) }}</td>
            </tr>
        @endif
        @if ($order->tax_cents > 0)
            <tr>
                <td>Tax</td>
                <td class="num">{{ \App\Support\Money::format($order->tax_cents, $order->currency) }}</td>
            </tr>
        @endif
        <tr class="inv-grand">
            <td>Total {{ $order->currency }}</td>
            <td class="num">{{ \App\Support\Money::format($order->total_cents, $order->currency) }}</td>
        </tr>
    </table>

    <div class="inv-foot">
        <p>Thank you for your purchase. This invoice was generated for order {{ $order->number }}.</p>
    </div>
</body>
</html>
