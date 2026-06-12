@php
    /** @var \App\Models\Order $order */
    /** @var bool $autoprint */

    $brandSetting = \App\Models\BrandSetting::current();
    $storeName = $brandSetting?->business_name ?? config('app.name', 'Self Order');
    $logoUrl = $brandSetting?->logoLightUrl();

    $serviceLabel = ($order->service_type ?? 'dine_in') === 'takeout' ? 'TAKEOUT' : 'DINE IN';
    $tableLabel = $order->table_label ?: (($order->service_type ?? 'dine_in') === 'takeout' ? 'Takeout' : 'Walk-in');

    $paymentMethodLabel = match ((string) ($order->payment_method ?? '')) {
        'cash' => 'CASH',
        'qris_static' => 'QRIS (STATIC)',
        'qris_dynamic' => 'QRIS (DYNAMIC)',
        'qris' => 'QRIS',
        default => strtoupper((string) ($order->payment_method ?? '-')),
    };

    $paymentStatusLabel = strtoupper((string) ($order->payment_status ?? 'unpaid'));

    $subtotal = (int) ($order->total_amount ?? 0);
    $discount = (int) ($order->discount_amount ?? 0);
    $total = max(0, $subtotal - $discount);

    $fmt = fn (int $v) => number_format($v, 0, ',', '.');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Struk - {{ $order->order_number }}</title>
    <style>
        :root {
            --paper-width: 80mm;
        }

        @page {
            size: 80mm auto;
            margin: 3mm;
        }

        html, body {
            padding: 0;
            margin: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 12px;
            color: #111;
            background: #fff;
        }

        .receipt {
            width: var(--paper-width);
            margin: 0 auto;
            padding: 0;
        }

        .center { text-align: center; }
        .muted { color: #444; }

        .hr {
            border-top: 1px dashed #111;
            margin: 6px 0;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: flex-start;
        }

        .row .left {
            flex: 1;
            min-width: 0;
        }

        .row .right {
            width: 120px;
            text-align: right;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .row .right.num {
            white-space: nowrap;
            overflow-wrap: normal;
            word-break: normal;
        }

        .item-name { font-weight: 700; }
        .item-meta { font-size: 11px; }

        .btnbar {
            display: none;
        }

        @media screen {
            body {
                background: #f4f4f5;
                padding: 12px;
            }
            .receipt {
                background: #fff;
                padding: 10px;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                box-shadow: 0 8px 30px rgba(0,0,0,.07);
            }
            .btnbar {
                display: flex;
                gap: 8px;
                justify-content: center;
                margin: 10px 0 0;
            }
            .btn {
                padding: 8px 12px;
                border-radius: 10px;
                border: 1px solid #d1d5db;
                background: #fff;
                cursor: pointer;
                font-weight: 700;
            }
            .btn.primary {
                border-color: #059669;
                background: #059669;
                color: #fff;
            }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="center">
            @if($logoUrl)
                <div style="margin-bottom: 8px;">
                    <img src="{{ $logoUrl }}" alt="{{ $storeName }}" style="max-width: 60px; max-height: 40px; object-fit: contain;" />
                </div>
            @endif
            <div style="font-size: 14px; font-weight: 800;">{{ $storeName }}</div>
            <div class="muted">STRUK PEMBAYARAN</div>
        </div>

        <div class="hr"></div>

        <div class="row"><div class="left">Order</div><div class="right">{{ $order->order_number }}</div></div>
        <div class="row"><div class="left">Tanggal</div><div class="right">{{ optional($order->created_at)->format('d/m/Y H:i') }}</div></div>
        <div class="row"><div class="left">Tipe</div><div class="right">{{ $serviceLabel }}</div></div>
        <div class="row"><div class="left">Meja</div><div class="right">{{ $tableLabel }}</div></div>
        <div class="row"><div class="left">Customer</div><div class="right">{{ $order->customer_name }}</div></div>

        @if($order->notes)
            <div class="row"><div class="left">Catatan</div><div class="right"> </div></div>
            <div class="muted" style="white-space: pre-wrap;">{{ $order->notes }}</div>
        @endif

        <div class="hr"></div>

        @foreach($order->items as $it)
            <div class="row">
                <div class="left">
                    <div class="item-name">{{ $it->product_name }}</div>
                    <div class="item-meta muted">
                        {{ $fmt((int) $it->unit_price) }} x {{ (int) $it->qty }}
                        @if(($it->options ?? collect())->count())
                            <div>
                                @foreach($it->options as $op)
                                    - {{ $op->option_name }}: {{ $op->value_name }}@if((int) $op->price_delta !== 0) ({{ (int) $op->price_delta > 0 ? '+' : '-' }}{{ $fmt(abs((int) $op->price_delta)) }})@endif
                                    <br/>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
                <div class="right num">{{ $fmt((int) $it->line_total) }}</div>
            </div>
        @endforeach

        <div class="hr"></div>

        <div class="row"><div class="left">Subtotal</div><div class="right num">{{ $fmt($subtotal) }}</div></div>
        <div class="row"><div class="left">Diskon</div><div class="right num">{{ $fmt($discount) }}</div></div>
        <div class="row" style="font-weight: 800;"><div class="left">TOTAL</div><div class="right num">{{ $fmt($total) }}</div></div>

        <div class="hr"></div>

        <div class="row"><div class="left">Metode</div><div class="right">{{ $paymentMethodLabel }}</div></div>
        <div class="row"><div class="left">Status</div><div class="right">{{ $paymentStatusLabel }}</div></div>

        <div class="hr"></div>

        <div class="center muted">Terima kasih</div>

        <div class="btnbar">
            <button class="btn" onclick="window.close()">Tutup</button>
            <button class="btn primary" onclick="window.print()">Print</button>
        </div>
    </div>

    @if($autoprint)
        <script>
            // Give the browser a moment to render fonts/layout before printing.
            window.addEventListener('load', () => {
                setTimeout(() => {
                    try { window.print(); } catch (e) {}
                }, 350);
            });
        </script>
    @endif
</body>
</html>
