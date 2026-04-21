<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte financiero</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 8mm 9mm 25mm;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            line-height: 1.35;
            color: #111827;
            margin: 0;
        }

        .sheet {
            width: 100%;
            box-sizing: border-box;
        }

        .page-header {
            display: table;
            width: 100%;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #d1d5db;
        }

        .page-header-main,
        .page-header-meta {
            display: table-cell;
            vertical-align: top;
        }

        .page-header-meta {
            width: 40%;
            text-align: right;
        }

        .brand-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        .brand-table td {
            border: 0;
            padding: 0;
            vertical-align: middle;
        }

        .brand-logo-cell {
            width: 18mm;
            padding-right: 8px !important;
        }

        .brand-logo {
            width: 15mm;
            height: 15mm;
            object-fit: contain;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 2px;
        }

        .title {
            font-size: 20px;
            font-weight: bold;
            margin: 0 0 4px;
        }

        .subtitle,
        .filters {
            margin: 0;
            color: #4b5563;
        }

        .filters {
            margin-top: 6px;
            font-size: 10px;
        }

        .meta-stack {
            margin: 0;
            color: #374151;
        }

        .meta-stack + .meta-stack {
            margin-top: 4px;
        }

        .account-block {
            margin-top: 14px;
        }

        .account-block + .account-block {
            page-break-before: always;
        }

        .account-head {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }

        .account-head > div {
            display: table-cell;
            vertical-align: bottom;
        }

        .account-meta-right {
            text-align: right;
        }

        .account-title {
            font-size: 16px;
            font-weight: bold;
            margin: 0;
        }

        .account-key {
            margin: 3px 0 0;
            color: #6b7280;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 5px 7px;
            vertical-align: top;
        }

        thead th {
            background: #f3f4f6;
            font-weight: bold;
            text-align: left;
        }

        .entry-row-income td {
            background: #f0fdf4;
        }

        .entry-row-expense td {
            background: #fffbeb;
        }

        .entry-row-income td:first-child {
            border-left: 4px solid #10b981;
        }

        .entry-row-expense td:first-child {
            border-left: 4px solid #f59e0b;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .nowrap {
            white-space: nowrap;
        }

        .wrap {
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .income {
            color: #047857;
            font-weight: bold;
        }

        .expense {
            color: #b45309;
            font-weight: bold;
        }

        tfoot td {
            font-weight: bold;
        }

        .totals-row td {
            background: #f9fafb;
        }

        .balance-row td {
            background: #eef2ff;
        }

        .empty-state {
            margin-top: 24px;
            padding: 16px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
        }

        .appendix-page {
            page-break-before: always;
            min-height: 100vh;
        }

        .appendix-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-height: calc(100vh - 40px);
        }

        .appendix-item {
            border: 1px solid #d1d5db;
            padding: 10px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            min-height: 0;
            flex: 1 1 0;
            overflow: hidden;
        }

        .appendix-image-wrap {
            margin-top: 8px;
            text-align: center;
            flex: 1 1 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 0;
        }

        .appendix-image {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .validation-footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -19mm;
            height: 17mm;
            border-top: 1px solid #d1d5db;
            padding-top: 2mm;
            font-size: 7px;
            color: #4b5563;
        }

        .validation-footer table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        .validation-footer td {
            border: 0;
            padding: 0;
            vertical-align: top;
        }

        .qr {
            width: 14mm;
            height: 14mm;
        }

        @media screen {
            body {
                padding: 16px;
                background: #f3f4f6;
            }

            .sheet {
                background: #fff;
                padding: 18px;
                box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
            }
        }

        @media print {
            .account-block {
                break-inside: auto;
                page-break-inside: auto;
            }

            .account-block + .account-block {
                break-before: page;
                page-break-before: always;
            }

            table {
                break-inside: auto;
                page-break-inside: auto;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-row-group;
            }

            .appendix-page {
                min-height: auto;
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .appendix-grid {
                min-height: 250mm;
                gap: 8mm;
            }

            .appendix-item {
                height: 121mm;
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    @if(!empty($qrCodeDataUri) && !empty($validationUrl))
        <div class="validation-footer">
            <table>
                <tr>
                    <td style="width: 17mm;">
                        <img class="qr" src="{{ $qrCodeDataUri }}" alt="QR de validación">
                    </td>
                    <td>
                        <div><strong>Validación digital:</strong> escanee el QR para confirmar este reporte contra el sistema.</div>
                        <div class="wrap"><strong>URL:</strong> {{ $validationUrl }}</div>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    <div class="sheet">
        <div class="page-header">
            <div class="page-header-main">
                <table class="brand-table">
                    <tr>
                        @if(!empty($clubLogoDataUri))
                            <td class="brand-logo-cell">
                                <img class="brand-logo" src="{{ $clubLogoDataUri }}" alt="Logo del club">
                            </td>
                        @endif
                        <td>
                            <p class="title">Reporte financiero por cuenta</p>
                            <p class="subtitle">{{ $club->club_name ?? 'Club' }}</p>
                            <p class="filters">
                                Generado: {{ $generatedAt->format('m-d-Y h:i A') }}
                                @if(!empty($filters['pay_to']))
                                    | Cuenta: {{ $accounts->first()['label'] ?? $filters['pay_to'] }}
                                @endif
                                @if(!empty($filters['concept']))
                                    | Concepto: {{ $filters['concept']->concept }}
                                @endif
                                @if(!empty($filters['date_from']) || !empty($filters['date_to']))
                                    | Fechas: {{ $filters['date_from'] ?: 'Inicio' }} a {{ $filters['date_to'] ?: 'Hoy' }}
                                @elseif(!empty($filters['date']))
                                    | Fecha: {{ $filters['date'] }}
                                @endif
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="page-header-meta">
                <p class="meta-stack">Cuentas: {{ count($accounts) }}</p>
                <p class="meta-stack">
                    Movimientos:
                    {{ collect($accounts)->sum(fn ($account) => count($account['entries'] ?? [])) }}
                </p>
            </div>
        </div>

        @forelse($accounts as $acc)
            <section class="account-block">
                <div class="account-head">
                    <div>
                        <p class="account-title">{{ $acc['label'] }}</p>
                        <p class="account-key">{{ $acc['pay_to'] }}</p>
                    </div>
                    <div class="account-meta-right">
                        <div>Ingresos: ${{ number_format($acc['totals']['paid'] ?? 0, 2) }}</div>
                        <div>Gastos: ${{ number_format($acc['totals']['spent'] ?? 0, 2) }}</div>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 9%" class="nowrap">Fecha</th>
                            <th style="width: 8%" class="nowrap">Tipo</th>
                            <th style="width: 55%">Concepto</th>
                            <th style="width: 8%" class="text-center nowrap">Ref.</th>
                            <th style="width: 10%" class="text-right nowrap">Gastos</th>
                            <th style="width: 10%" class="text-right nowrap">Ingresos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(($acc['entries'] ?? []) as $entry)
                            <tr class="{{ $entry['entry_type'] === 'payment' ? 'entry-row-income' : 'entry-row-expense' }}">
                                <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($entry['date'])->format('m-d-Y') }}</td>
                                <td class="nowrap">{{ $entry['entry_type'] === 'payment' ? 'Ingreso' : 'Gasto' }}</td>
                                <td class="wrap">
                                    {{ $entry['concept'] ?? '—' }}
                                    @if(!empty($entry['member']) || !empty($entry['staff']))
                                        <div style="margin-top: 3px; color: #4b5563;">
                                            {{ $entry['member'] ?? $entry['staff'] }}
                                        </div>
                                    @endif
                                </td>
                                <td class="text-center nowrap">{{ $entry['receipt_ref'] ?? '—' }}</td>
                                <td class="text-right nowrap">
                                    @if($entry['entry_type'] === 'expense')
                                        <span class="expense">-${{ number_format($entry['amount'] ?? 0, 2) }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-right nowrap">
                                    @if($entry['entry_type'] === 'payment')
                                        <span class="income">${{ number_format($entry['amount'] ?? 0, 2) }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="totals-row">
                            <td colspan="4">Totales</td>
                            <td class="text-right expense">-${{ number_format($acc['totals']['spent'] ?? 0, 2) }}</td>
                            <td class="text-right income">${{ number_format($acc['totals']['paid'] ?? 0, 2) }}</td>
                        </tr>
                        <tr class="balance-row">
                            <td colspan="4">Saldo final</td>
                            <td colspan="2" class="text-right">${{ number_format($acc['totals']['net'] ?? 0, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>
        @empty
            <div class="empty-state">
                No se encontraron movimientos para los filtros seleccionados.
            </div>
        @endforelse

        @if(!empty($receipts) && count($receipts))
            @foreach(collect($receipts)->chunk(2) as $receiptPage)
                <section class="appendix-page">
                    <div class="appendix-grid">
                        @foreach($receiptPage as $receipt)
                            <article class="appendix-item">
                                <p class="title" style="font-size: 18px; margin-bottom: 4px;">Apéndice {{ $receipt['ref'] ?? '' }}</p>
                                <p class="subtitle">
                                    {{ $receipt['source'] ?? 'Movimiento' }} ID: {{ $receipt['record_id'] ?? '' }}
                                    @if(!empty($receipt['filename']))
                                        | Archivo: {{ $receipt['filename'] }}
                                    @endif
                                </p>

                                @if(!empty($receipt['data_uri']))
                                    <div class="appendix-image-wrap">
                                        <img src="{{ $receipt['data_uri'] }}" alt="Receipt {{ $receipt['ref'] ?? '' }}" class="appendix-image" />
                                    </div>
                                @else
                                    <div class="empty-state">La imagen del recibo no esta disponible.</div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </div>

</body>
</html>
