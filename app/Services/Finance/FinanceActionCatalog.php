<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\Route;

class FinanceActionCatalog
{
    public function forRole(?string $roleKey = null): array
    {
        return [
            'cashbox' => [
                'label' => 'Caja',
                'description' => 'Captura operativa de ingresos y gastos.',
                'actions' => [
                    $this->action('cashbox.open', 'Abrir caja', 'Open cashbox', 'club.director.finance.cashbox', 'GET', 'cashbox'),
                    $this->action('cashbox.bootstrap', 'Datos de caja', 'Cashbox data', 'club.finance-engine.cashbox', 'GET', 'cashbox'),
                    $this->action('concept.store', 'Crear concepto', 'Create concept', 'club.finance-engine.concepts.store', 'POST', 'concept', writesLedger: false),
                    $this->action('income.store', 'Guardar ingreso', 'Save income', 'club.finance-engine.income.store', 'POST', 'income', writesLedger: true),
                    $this->action('expense.store', 'Guardar gasto', 'Save expense', 'club.finance-engine.expenses.store', 'POST', 'expense', writesLedger: true),
                    $this->action('expense.receipt_upload', 'Subir comprobante de gasto', 'Upload expense proof', 'club.finance-engine.expenses.receipt.upload', 'POST', 'expense', needsTarget: true, writesLedger: false),
                    $this->action('expense.receipt_remove', 'Quitar comprobante de gasto', 'Remove expense proof', 'club.finance-engine.expenses.receipt.remove', 'DELETE', 'expense', needsTarget: true, writesLedger: false),
                    $this->action('expense.reimbursement_receipt_upload', 'Subir comprobante de reembolso', 'Upload reimbursement proof', 'club.finance-engine.expenses.reimbursement-receipt.upload', 'POST', 'expense', needsTarget: true, writesLedger: false),
                    $this->action('expense.reimbursement_receipt_remove', 'Quitar comprobante de reembolso', 'Remove reimbursement proof', 'club.finance-engine.expenses.reimbursement-receipt.remove', 'DELETE', 'expense', needsTarget: true, writesLedger: false),
                    $this->action('expense.reimburse', 'Liquidar reembolso', 'Settle reimbursement', 'club.finance-engine.expenses.reimburse', 'POST', 'expense', needsTarget: true, writesLedger: true),
                ],
            ],
            'accounting' => [
                'label' => 'Contabilidad',
                'description' => 'Transferencias, correcciones, saldos y auditoria de movimientos.',
                'actions' => [
                    $this->action('accounting.open', 'Abrir contabilidad', 'Open accounting', 'club.director.finance.accounting', 'GET', 'accounting'),
                    $this->action('accounting.bootstrap', 'Datos de contabilidad', 'Accounting data', 'club.finance-engine.accounting', 'GET', 'accounting'),
                    $this->action('transfer.local_store', 'Guardar transferencia local', 'Save local transfer', 'club.finance-engine.transfers.store', 'POST', 'transfer', writesLedger: true),
                    $this->action('transfer.staff_remittance_validate', 'Validar entrega de staff', 'Validate staff remittance', 'club.finance-engine.staff-remittances.validate', 'POST', 'transfer', writesLedger: true),
                    $this->action('transfer.event_upstream_store', 'Transferir evento hacia arriba', 'Transfer event funds upstream', 'club.finance-engine.event-settlements.store', 'POST', 'transfer', true, true),
                    $this->action('correction.reverse_income', 'Revertir ingreso', 'Reverse income', 'club.finance-engine.corrections.payments.reverse', 'POST', 'correction', true, true),
                    $this->action('correction.reverse_expense', 'Revertir gasto', 'Reverse expense', 'club.finance-engine.corrections.expenses.reverse', 'POST', 'correction', true, true),
                    $this->action('correction.reverse_reimbursement', 'Revertir reembolso', 'Reverse reimbursement', 'club.finance-engine.corrections.reimbursements.reverse', 'POST', 'correction', true, true),
                ],
            ],
            'fundraisers' => [
                'label' => 'Fundraisers',
                'description' => 'Eventos de venta, productos, inventario opcional e ingresos con recibo.',
                'actions' => [
                    $this->action('fundraiser.open', 'Abrir fundraisers', 'Open fundraisers', 'club.director.finance.fundraisers', 'GET', 'fundraiser'),
                    $this->action('fundraiser.bootstrap', 'Datos de fundraisers', 'Fundraiser data', 'club.finance-engine.fundraisers', 'GET', 'fundraiser'),
                    $this->action('fundraiser.event_store', 'Crear fundraiser', 'Create fundraiser', 'club.finance-engine.fundraisers.store', 'POST', 'fundraiser', writesLedger: false),
                    $this->action('fundraiser.product_store', 'Guardar producto', 'Save product', 'club.finance-engine.fundraisers.products.store', 'POST', 'fundraiser', needsTarget: true, writesLedger: false),
                    $this->action('fundraiser.sale_store', 'Registrar venta', 'Record sale', 'club.finance-engine.fundraisers.sales.store', 'POST', 'fundraiser', needsTarget: true, writesLedger: true),
                    $this->action('fundraiser.close', 'Cerrar fundraiser', 'Close fundraiser', 'club.finance-engine.fundraisers.close', 'POST', 'fundraiser', needsTarget: true, writesLedger: true),
                    $this->action('fundraiser.partner_store', 'Asociar club', 'Add partner club', 'club.finance-engine.fundraisers.partners.store', 'POST', 'fundraiser', needsTarget: true, writesLedger: false),
                    $this->action('fundraiser.partner_contribution', 'Registrar aporte asociado', 'Record partner contribution', 'club.finance-engine.fundraisers.partners.contribution', 'POST', 'fundraiser', needsTarget: true, writesLedger: true),
                    $this->action('fundraiser.partner_distribution', 'Transferir ganancia asociada', 'Transfer partner earnings', 'club.finance-engine.fundraisers.partners.distribution', 'POST', 'fundraiser', needsTarget: true, writesLedger: true),
                ],
            ],
            'reports' => [
                'label' => 'Reportes financieros',
                'description' => 'Lecturas por cuenta, concepto, rango de fecha y comprobantes.',
                'actions' => [
                    $this->action('engine.movements', 'Motor: movimientos normalizados', 'Engine: normalized movements', 'club.finance-engine.movements', 'GET', 'report'),
                    $this->action('engine.actionables', 'Motor: acciones disponibles', 'Engine: available actions', 'club.finance-engine.actionables', 'GET', 'report'),
                ],
            ],
        ];
    }

    private function action(
        string $key,
        string $labelEs,
        string $labelEn,
        string $routeName,
        string $method,
        string $domain,
        bool $needsTarget = false,
        bool $writesLedger = false,
    ): array {
        return [
            'key' => $key,
            'label' => [
                'es' => $labelEs,
                'en' => $labelEn,
            ],
            'domain' => $domain,
            'method' => $method,
            'route_name' => $routeName,
            'path' => $needsTarget || !Route::has($routeName) ? null : route($routeName, [], false),
            'needs_target' => $needsTarget,
            'writes_ledger' => $writesLedger,
        ];
    }
}
