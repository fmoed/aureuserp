<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ ($isRtl ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <style>
        html,
        body,
        table,
        th,
        td,
        div,
        span,
        p,
        b,
        strong {
            font-family: 'DejaVu Sans', 'Helvetica', 'Arial', sans-serif !important;
        }

        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        body {
            margin: 0;
            padding: 0;
            background: #ffffff;
            color: #0f172a;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px;
            table-layout: fixed;
        }

        td.label-cell {
            width: 33.333%;
            vertical-align: middle;
            text-align: center;
            padding: 10px 8px;
            border: 1px dashed #cbd5e1;
            border-radius: 4px;
            background: #ffffff;
            page-break-inside: avoid;
        }

        td.empty-cell {
            width: 33.333%;
            border: none;
            background: transparent;
        }

        .company-name {
            font-size: 9px;
            color: #475569;
            margin-bottom: 3px;
            word-break: break-word;
        }

        .product-name {
            font-size: 11px;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 2px;
            word-break: break-word;
        }

        .model-name {
            font-size: 9px;
            color: #64748b;
            margin-bottom: 6px;
            word-break: break-word;
        }

        .barcode-container {
            margin: 4px auto;
            display: inline-block;
            text-align: center;
        }

        .asset-tag {
            font-size: 10px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 4px;
            letter-spacing: 0.4px;
            word-break: break-all;
        }

        .serial-no {
            font-size: 8.5px;
            color: #475569;
            margin-top: 3px;
            word-break: break-all;
        }
    </style>
</head>
<body>
    @php
        $columns = 3;
        $chunks = $labels->chunk($columns);
    @endphp

    <table>
        @foreach ($chunks as $row)
            <tr>
                @foreach ($row as $label)
                    <td class="label-cell">
                        @if (filled($label['company_name'] ?? null))
                            <div class="company-name">{{ $label['company_name'] }}</div>
                        @endif

                        <div class="product-name">{{ $label['product_name'] }}</div>

                        @if (filled($label['model'] ?? null))
                            <div class="model-name">{{ $label['model'] }}</div>
                        @endif

                        @if (filled($label['asset_tag'] ?? null))
                            <div class="barcode-container">
                                {!! DNS1D::getBarcodeHTML($label['asset_tag'], 'C128', 1.6, 36) !!}
                            </div>

                            <div class="asset-tag">{{ $label['asset_tag'] }}</div>
                        @endif

                        @if (filled($label['serial_no'] ?? null))
                            <div class="serial-no">
                                {{ __('assets::filament/resources/asset.table.columns.serial-no') }}: {{ $label['serial_no'] }}
                            </div>
                        @endif
                    </td>
                @endforeach

                @for ($emptyIndex = $row->count(); $emptyIndex < $columns; $emptyIndex++)
                    <td class="empty-cell"></td>
                @endfor
            </tr>
        @endforeach
    </table>
</body>
</html>
