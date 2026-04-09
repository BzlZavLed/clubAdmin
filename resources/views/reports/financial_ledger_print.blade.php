<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte financiero</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 14mm;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111827;
            margin: 0;
        }

        .page-header {
            margin-bottom: 16px;
        }

        .title {
            font-size: 22px;
            font-weight: bold;
            margin: 0 0 4px;
        }

        .subtitle,
        .filters {
            margin: 0;
            color: #4b5563;
        }

        .account-block {
            margin-top: 18px;
        }

        .account-block + .account-block {
            page-break-before: auto;
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
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            vertical-align: top;
        }

        thead th {
            background: #f3f4f6;
            font-weight: bold;
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
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

        @media screen {
            body {
                padding: 24px;
                background: #f3f4f6;
            }

            .sheet {
                background: #fff;
                padding: 24px;
                box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
            }
        }

        @media print {
            .account-block {
                break-inside: auto;
                page-break-inside: auto;
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
    <div class="sheet">
        <div class="page-header">
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
                            <th style="width: 10%">Fecha</th>
                            <th style="width: 10%">Tipo</th>
                            <th style="width: 16%">Miembro / Personal</th>
                            <th>Concepto</th>
                            <th style="width: 10%" class="text-center">Ref.</th>
                            <th style="width: 12%" class="text-right">Gastos</th>
                            <th style="width: 12%" class="text-right">Ingresos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(($acc['entries'] ?? []) as $entry)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($entry['date'])->format('m-d-Y') }}</td>
                                <td>{{ $entry['entry_type'] === 'payment' ? 'Ingreso' : 'Gasto' }}</td>
                                <td>{{ $entry['member'] ?? $entry['staff'] ?? '—' }}</td>
                                <td>{{ $entry['concept'] ?? '—' }}</td>
                                <td class="text-center">{{ $entry['receipt_ref'] ?? '—' }}</td>
                                <td class="text-right">
                                    @if($entry['entry_type'] === 'expense')
                                        <span class="expense">-${{ number_format($entry['amount'] ?? 0, 2) }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-right">
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
                            <td colspan="5">Totales</td>
                            <td class="text-right expense">-${{ number_format($acc['totals']['spent'] ?? 0, 2) }}</td>
                            <td class="text-right income">${{ number_format($acc['totals']['paid'] ?? 0, 2) }}</td>
                        </tr>
                        <tr class="balance-row">
                            <td colspan="5">Saldo final</td>
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

    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
</body>
</html>
