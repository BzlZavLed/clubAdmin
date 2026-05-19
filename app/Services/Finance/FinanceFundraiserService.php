<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Club;
use App\Models\Expense;
use App\Models\FinanceReimbursementPayee;
use App\Models\FundraiserEvent;
use App\Models\FundraiserProduct;
use App\Models\FundraiserSale;
use App\Models\Payment;
use App\Models\PaymentConcept;
use App\Models\PaymentReceipt;
use App\Models\Staff;
use App\Services\ClubTreasuryService;
use App\Services\PaymentReceiptService;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceFundraiserService
{
    public const SOURCE_TYPE = 'fundraiser_sale';

    public function __construct(
        private readonly PaymentReceiptService $paymentReceiptService,
        private readonly ClubTreasuryService $treasuryService,
    ) {
    }

    public function data($user, Club $club): array
    {
        $clubs = ClubHelper::clubsForUser($user)
            ->map(fn (Club $allowedClub) => [
                'id' => (int) $allowedClub->id,
                'club_name' => $allowedClub->club_name,
            ])
            ->values();

        $accounts = Account::query()
            ->where('club_id', $club->id)
            ->orderBy('label')
            ->get(['id', 'club_id', 'pay_to', 'label', 'balance']);

        if ($accounts->isEmpty()) {
            $accounts = collect([
                Account::query()->firstOrCreate(
                    ['club_id' => $club->id, 'pay_to' => 'club_budget'],
                    ['label' => 'Club budget', 'balance' => 0]
                ),
            ]);
        }

        $accountLabels = $accounts->pluck('label', 'pay_to');

        $events = FundraiserEvent::query()
            ->where('club_id', $club->id)
            ->with([
                'products' => fn ($query) => $query->orderByDesc('is_active')->orderBy('name'),
                'products.investmentExpense:id,receipt_path,status',
                'investmentExpense:id,receipt_path,status',
                'sales' => fn ($query) => $query->latest('sale_date')->latest('id'),
                'sales.items',
                'sales.payment.receipt:id,payment_id,receipt_number',
            ])
            ->latest('event_date')
            ->latest('id')
            ->get()
            ->map(fn (FundraiserEvent $event) => $this->eventPayload($event, $accountLabels))
            ->values();

        $accountBalances = $this->treasuryService->locationBalancesByAccount($club)->values();

        return [
            'engine_version' => 'finance_engine_v1_fundraisers',
            'club' => ['id' => (int) $club->id, 'club_name' => $club->club_name],
            'clubs' => $clubs,
            'accounts' => $accounts->values(),
            'account_balances' => $accountBalances,
            'events' => $events,
            'payment_types' => ['cash', 'zelle', 'check', 'transfer'],
        ];
    }

    public function storeEvent(Request $request)
    {
        $validated = $request->validate([
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
            'name' => ['required', 'string', 'max:255'],
            'fundraiser_type' => ['required', Rule::in(['food', 'products', 'other'])],
            'event_date' => ['nullable', 'date'],
            'pay_to' => ['required', 'string', 'max:255'],
            'investment_total' => ['nullable', 'numeric', 'min:0'],
            'investment_pay_to' => ['nullable', 'string', 'max:255'],
            'investment_funds_location' => ['nullable', Rule::in(['cash', 'bank'])],
            'investment_receipt_image' => ['nullable', 'image', 'max:5120'],
            'planned_units' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['active', 'closed'])],
        ]);

        $club = ClubHelper::clubForUser($request->user(), $validated['club_id'] ?? null);
        $payTo = $validated['pay_to'];
        $this->assertOperatingAccount($payTo);
        $this->ensureAccount($club, $payTo);

        $event = null;
        DB::transaction(function () use ($request, $club, $validated, $payTo, &$event) {
            $investmentAmount = round((float) ($validated['investment_total'] ?? 0), 2);
            $investmentPayTo = $validated['investment_pay_to'] ?? $payTo;
            $investmentFundsLocation = $validated['investment_funds_location'] ?? 'cash';
            $investmentExpense = $investmentAmount > 0
                ? $this->recordInvestmentExpense(
                    $request,
                    $club,
                    $investmentPayTo,
                    $investmentFundsLocation,
                    $investmentAmount,
                    $validated['event_date'] ?? now()->toDateString(),
                    "Inversion fundraiser: {$validated['name']}",
                    'investment_receipt_image'
                )
                : null;

            $event = FundraiserEvent::query()->create([
                'club_id' => $club->id,
                'name' => $validated['name'],
                'fundraiser_type' => $validated['fundraiser_type'],
                'event_date' => $validated['event_date'] ?? null,
                'pay_to' => $payTo,
                'investment_total' => $investmentAmount,
                'investment_expense_id' => $investmentExpense?->id,
                'investment_pay_to' => $investmentExpense ? $investmentPayTo : null,
                'investment_funds_location' => $investmentExpense ? $investmentFundsLocation : null,
                'planned_units' => $validated['planned_units'] ?? null,
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 'active',
                'created_by_user_id' => $request->user()?->id,
            ]);
        });

        return response()->json([
            'message' => 'Fundraiser event created',
            'data' => $this->data($request->user(), $club),
            'event' => $this->eventPayload($event->fresh(['products.investmentExpense', 'investmentExpense', 'sales.items', 'sales.payment.receipt'])),
        ], 201);
    }

    public function kitchenEvent(FundraiserEvent $event): array
    {
        $this->assertKitchenEvent($event);

        return [
            'id' => (int) $event->id,
            'club_id' => (int) $event->club_id,
            'name' => $event->name,
            'fundraiser_type' => $event->fundraiser_type,
            'event_date' => optional($event->event_date)->toDateString(),
            'status' => $event->status,
        ];
    }

    public function kitchenData(FundraiserEvent $event): array
    {
        $this->assertKitchenEvent($event);

        $baseQuery = FundraiserSale::query()
            ->where('fundraiser_event_id', $event->id)
            ->with(['items', 'payment.receipt:id,payment_id,receipt_number']);

        $pending = (clone $baseQuery)
            ->where('kitchen_status', 'pending')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $finished = (clone $baseQuery)
            ->where('kitchen_status', 'finished')
            ->orderByDesc('kitchen_completed_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return [
            'event' => $this->kitchenEvent($event),
            'pending_orders' => $pending->map(fn (FundraiserSale $sale) => $this->kitchenSalePayload($sale))->values()->all(),
            'finished_orders' => $finished->map(fn (FundraiserSale $sale) => $this->kitchenSalePayload($sale))->values()->all(),
            'summary' => [
                'pending_count' => (int) $pending->count(),
                'finished_count' => (int) $finished->count(),
            ],
        ];
    }

    public function finishKitchenOrder(Request $request, FundraiserEvent $event, FundraiserSale $sale)
    {
        $this->assertKitchenEvent($event);

        if ((int) $sale->fundraiser_event_id !== (int) $event->id) {
            abort(404);
        }

        if ($sale->kitchen_status !== 'finished') {
            $sale->forceFill([
                'kitchen_status' => 'finished',
                'kitchen_completed_at' => now(),
                'kitchen_completed_by_user_id' => $request->user()?->id,
            ])->save();
        }

        return response()->json([
            'message' => 'Order finished',
            'data' => $this->kitchenData($event->fresh()),
        ]);
    }

    public function storeProduct(Request $request, FundraiserEvent $fundraiserEvent)
    {
        $event = $this->authorizedEvent($request, $fundraiserEvent);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sale_price' => ['required', 'numeric', 'min:0.01'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'investment_amount' => ['nullable', 'numeric', 'min:0'],
            'investment_pay_to' => ['nullable', 'string', 'max:255'],
            'investment_funds_location' => ['nullable', Rule::in(['cash', 'bank'])],
            'investment_date' => ['nullable', 'date'],
            'receipt_image' => ['nullable', 'image', 'max:5120'],
            'tracks_inventory' => ['nullable', 'boolean'],
            'quantity_available' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tracksInventory = (bool) ($validated['tracks_inventory'] ?? false);
        $investmentAmount = round((float) ($validated['investment_amount'] ?? 0), 2);
        $hasQuantity = array_key_exists('quantity_available', $validated) && $validated['quantity_available'] !== null && $validated['quantity_available'] !== '';

        if (($tracksInventory || $investmentAmount > 0) && !$hasQuantity) {
            throw ValidationException::withMessages([
                'quantity_available' => ['Ingresa la cantidad planeada para este producto.'],
            ]);
        }

        $plannedQuantity = (int) ($validated['quantity_available'] ?? 0);
        if ($investmentAmount > 0 && $plannedQuantity <= 0) {
            throw ValidationException::withMessages([
                'quantity_available' => ['Ingresa una cantidad mayor que 0 para calcular el costo unitario.'],
            ]);
        }

        $unitCost = $this->unitCostForProduct($validated, $investmentAmount, $plannedQuantity);
        $investmentPayTo = $validated['investment_pay_to'] ?? $event->pay_to;
        $investmentFundsLocation = $validated['investment_funds_location'] ?? 'cash';

        $investmentExpense = null;
        DB::transaction(function () use ($request, $event, $validated, $tracksInventory, $unitCost, $investmentAmount, $investmentPayTo, $investmentFundsLocation, $plannedQuantity, $hasQuantity, &$investmentExpense) {
            if ($investmentAmount > 0) {
                $investmentExpense = $this->recordInvestmentExpense(
                    $request,
                    $event->club,
                    $investmentPayTo,
                    $investmentFundsLocation,
                    $investmentAmount,
                    $validated['investment_date'] ?? optional($event->event_date)->toDateString() ?? now()->toDateString(),
                    "Inversion fundraiser: {$event->name} - {$validated['name']}",
                    'receipt_image'
                );
            }

            FundraiserProduct::query()->create([
                'fundraiser_event_id' => $event->id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'sale_price' => round((float) $validated['sale_price'], 2),
                'unit_cost' => $unitCost,
                'investment_amount' => $investmentAmount,
                'investment_expense_id' => $investmentExpense?->id,
                'tracks_inventory' => $tracksInventory,
                'quantity_available' => $hasQuantity ? $plannedQuantity : null,
                'quantity_sold' => 0,
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);
        });

        return response()->json([
            'message' => 'Fundraiser item saved',
            'data' => $this->data($request->user(), $event->club),
        ], 201);
    }

    public function updateProduct(Request $request, FundraiserProduct $fundraiserProduct)
    {
        $fundraiserProduct->loadMissing(['fundraiserEvent.club', 'investmentExpense']);
        $event = $this->authorizedEvent($request, $fundraiserProduct->fundraiserEvent);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sale_price' => ['required', 'numeric', 'min:0.01'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'investment_amount' => ['nullable', 'numeric', 'min:0'],
            'investment_pay_to' => ['nullable', 'string', 'max:255'],
            'investment_funds_location' => ['nullable', Rule::in(['cash', 'bank'])],
            'investment_date' => ['nullable', 'date'],
            'receipt_image' => ['nullable', 'image', 'max:5120'],
            'tracks_inventory' => ['nullable', 'boolean'],
            'quantity_available' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tracksInventory = (bool) ($validated['tracks_inventory'] ?? false);
        $existingInvestment = round((float) $fundraiserProduct->investment_amount, 2);
        $investmentAmount = round((float) ($validated['investment_amount'] ?? $existingInvestment), 2);
        $hasPostedInvestment = (bool) $fundraiserProduct->investment_expense_id;
        $hasQuantity = array_key_exists('quantity_available', $validated) && $validated['quantity_available'] !== null && $validated['quantity_available'] !== '';

        if ($hasPostedInvestment && abs($investmentAmount - $existingInvestment) > 0.0001) {
            throw ValidationException::withMessages([
                'investment_amount' => ['La inversion ya fue registrada en el libro financiero. Registra una correccion contable para cambiarla.'],
            ]);
        }

        if (($tracksInventory || $investmentAmount > 0) && !$hasQuantity) {
            throw ValidationException::withMessages([
                'quantity_available' => ['Ingresa la cantidad planeada para este producto.'],
            ]);
        }

        $plannedQuantity = (int) ($validated['quantity_available'] ?? 0);
        if ($investmentAmount > 0 && $plannedQuantity <= 0) {
            throw ValidationException::withMessages([
                'quantity_available' => ['Ingresa una cantidad mayor que 0 para calcular el costo unitario.'],
            ]);
        }

        if ($hasQuantity && $plannedQuantity < (int) $fundraiserProduct->quantity_sold) {
            throw ValidationException::withMessages([
                'quantity_available' => ['La cantidad planeada no puede ser menor que la cantidad ya vendida.'],
            ]);
        }

        $unitCost = $this->unitCostForProduct($validated, $investmentAmount, $plannedQuantity);
        $investmentPayTo = $validated['investment_pay_to'] ?? $event->pay_to;
        $investmentFundsLocation = $validated['investment_funds_location'] ?? 'cash';

        DB::transaction(function () use ($request, $event, $fundraiserProduct, $validated, $tracksInventory, $unitCost, $investmentAmount, $investmentPayTo, $investmentFundsLocation, $plannedQuantity, $hasQuantity, $hasPostedInvestment) {
            $investmentExpense = null;
            if (!$hasPostedInvestment && $investmentAmount > 0) {
                $investmentExpense = $this->recordInvestmentExpense(
                    $request,
                    $event->club,
                    $investmentPayTo,
                    $investmentFundsLocation,
                    $investmentAmount,
                    $validated['investment_date'] ?? optional($event->event_date)->toDateString() ?? now()->toDateString(),
                    "Inversion fundraiser: {$event->name} - {$validated['name']}",
                    'receipt_image'
                );
            }

            $fundraiserProduct->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'sale_price' => round((float) $validated['sale_price'], 2),
                'unit_cost' => $unitCost,
                'investment_amount' => $investmentAmount,
                'investment_expense_id' => $investmentExpense?->id ?: $fundraiserProduct->investment_expense_id,
                'tracks_inventory' => $tracksInventory,
                'quantity_available' => $hasQuantity ? $plannedQuantity : null,
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);
        });

        return response()->json([
            'message' => 'Fundraiser item updated',
            'data' => $this->data($request->user(), $event->club),
        ]);
    }

    public function storeSale(Request $request, FundraiserEvent $fundraiserEvent)
    {
        $event = $this->authorizedEvent($request, $fundraiserEvent);

        $validated = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'sale_date' => ['required', 'date'],
            'payment_type' => ['required', Rule::in(['cash', 'zelle', 'check', 'transfer'])],
            'zelle_phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fundraiser_product_id' => ['required', 'integer', 'exists:fundraiser_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($event->status !== 'active') {
            throw ValidationException::withMessages([
                'fundraiser_event_id' => ['Este fundraiser está cerrado.'],
            ]);
        }

        $club = $event->club;
        $this->assertPaymentTypeIsConfigured($club, $validated);

        $sale = null;
        $receipt = null;

        DB::transaction(function () use ($request, $validated, $event, $club, &$sale, &$receipt) {
            $event = FundraiserEvent::query()
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();

            $productIds = collect($validated['items'])
                ->pluck('fundraiser_product_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $products = FundraiserProduct::query()
                ->where('fundraiser_event_id', $event->id)
                ->whereIn('id', $productIds->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'items' => ['Uno de los productos no pertenece a este fundraiser.'],
                ]);
            }

            $lineRows = [];
            $totalAmount = 0.0;
            $totalCost = 0.0;
            $saleQuantitiesByProduct = [];
            $extendedFoodQuantities = [];
            $normalizedItems = [];

            foreach ($validated['items'] as $index => $item) {
                $product = $products->get((int) $item['fundraiser_product_id']);
                if (!$product || !$product->is_active) {
                    throw ValidationException::withMessages([
                        "items.{$index}.fundraiser_product_id" => ['Este producto no está activo.'],
                    ]);
                }

                $quantity = (int) $item['quantity'];
                $productId = (int) $product->id;
                $saleQuantitiesByProduct[$productId] = ($saleQuantitiesByProduct[$productId] ?? 0) + $quantity;
                $sold = (int) $product->quantity_sold;
                $available = $product->quantity_available === null ? 0 : (int) $product->quantity_available;
                $neededQuantity = $sold + $saleQuantitiesByProduct[$productId];

                if ($neededQuantity > $available) {
                    if ($event->fundraiser_type === 'food') {
                        $extendedFoodQuantities[$productId] = max($extendedFoodQuantities[$productId] ?? 0, $neededQuantity);
                    } elseif ($product->tracks_inventory) {
                        throw ValidationException::withMessages([
                            "items.{$index}.quantity" => ["Inventario insuficiente para {$product->name}. Disponible: " . max($available - $sold, 0) . '.'],
                        ]);
                    }
                }

                $unitPrice = array_key_exists('unit_price', $item) && $item['unit_price'] !== null
                    ? round((float) $item['unit_price'], 2)
                    : round((float) $product->sale_price, 2);

                $normalizedItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ];
            }

            $unitCostsByProduct = $this->unitCostsForEventProducts($event, $products, $extendedFoodQuantities);

            foreach ($normalizedItems as $item) {
                $product = $item['product'];
                $quantity = $item['quantity'];
                $unitPrice = $item['unit_price'];
                $unitCost = round((float) $product->unit_cost, 2);
                $unitCost = round((float) ($unitCostsByProduct[(int) $product->id] ?? $unitCost), 2);
                $lineTotal = round($unitPrice * $quantity, 2);
                $lineCost = round($unitCost * $quantity, 2);

                $lineRows[] = [
                    'product' => $product,
                    'payload' => [
                        'fundraiser_product_id' => $product->id,
                        'item_name' => $product->name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'unit_cost' => $unitCost,
                        'line_total' => $lineTotal,
                        'line_cost' => $lineCost,
                        'line_gain' => round($lineTotal - $lineCost, 2),
                    ],
                ];

                $totalAmount = round($totalAmount + $lineTotal, 2);
                $totalCost = round($totalCost + $lineCost, 2);
            }

            if ($totalAmount <= 0) {
                throw ValidationException::withMessages([
                    'items' => ['La venta debe tener un total mayor que 0.'],
                ]);
            }

            $sale = FundraiserSale::query()->create([
                'fundraiser_event_id' => $event->id,
                'club_id' => $club->id,
                'customer_name' => $validated['customer_name'] ?? null,
                'sale_date' => $validated['sale_date'],
                'payment_type' => $validated['payment_type'],
                'zelle_phone' => $validated['payment_type'] === 'zelle' ? ($validated['zelle_phone'] ?? null) : null,
                'total_amount' => $totalAmount,
                'total_cost' => $totalCost,
                'gain_amount' => round($totalAmount - $totalCost, 2),
                'notes' => $validated['notes'] ?? null,
                'created_by_user_id' => $request->user()?->id,
            ]);

            foreach ($lineRows as $line) {
                $sale->items()->create($line['payload']);
                $extendedQuantity = $extendedFoodQuantities[(int) $line['product']->id] ?? null;
                if ($extendedQuantity !== null) {
                    $line['product']->forceFill(['quantity_available' => $extendedQuantity])->save();
                    unset($extendedFoodQuantities[(int) $line['product']->id]);
                }
                $line['product']->increment('quantity_sold', $line['payload']['quantity']);
            }

            $account = $this->ensureAccount($club, $event->pay_to);
            $payment = Payment::query()->create([
                'club_id' => $club->id,
                'payment_concept_id' => null,
                'concept_text' => "Fundraiser: {$event->name}",
                'pay_to' => $event->pay_to,
                'account_id' => $account->id,
                'payer_name' => $validated['customer_name'] ?? 'Venta fundraiser',
                'amount_paid' => $totalAmount,
                'expected_amount' => $totalAmount,
                'balance_due_after' => 0,
                'payment_date' => $validated['sale_date'],
                'payment_type' => $validated['payment_type'],
                'zelle_phone' => $validated['payment_type'] === 'zelle' ? ($validated['zelle_phone'] ?? null) : null,
                'received_by_user_id' => $request->user()?->id,
                'notes' => $validated['notes'] ?? null,
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $sale->id,
            ]);

            $account->increment('balance', $totalAmount);
            $receipt = $this->paymentReceiptService->syncForPayment($payment);

            $sale->update(['payment_id' => $payment->id]);
        });

        $sale->load(['items', 'payment.receipt']);

        return response()->json([
            'message' => 'Fundraiser sale recorded',
            'data' => $this->data($request->user(), $event->club),
            'sale' => $this->salePayload($sale),
            'receipt' => $this->receiptPayload($receipt),
        ], 201);
    }

    private function authorizedEvent(Request $request, FundraiserEvent $event): FundraiserEvent
    {
        $event->loadMissing('club');
        $club = ClubHelper::clubForUser($request->user(), $event->club_id);

        if ((int) $club->id !== (int) $event->club_id) {
            abort(403, 'You cannot manage this fundraiser.');
        }

        return $event;
    }

    private function assertOperatingAccount(string $payTo): void
    {
        if ($payTo === 'reimbursement_to') {
            throw ValidationException::withMessages([
                'pay_to' => ['Selecciona una cuenta de fondos, no reembolsos pendientes.'],
            ]);
        }
    }

    private function assertPaymentTypeIsConfigured(Club $club, array $validated): void
    {
        if (!in_array($validated['payment_type'], $this->treasuryService->electronicPaymentTypes(), true)) {
            return;
        }

        $clubBankInfo = $this->treasuryService->clubBankInfo($club);
        if (!$clubBankInfo) {
            throw ValidationException::withMessages([
                'payment_type' => ['Registra la cuenta bancaria del club antes de recibir pagos electrónicos.'],
            ]);
        }

        if ($validated['payment_type'] === 'zelle' && empty($clubBankInfo->zelle_phone)) {
            throw ValidationException::withMessages([
                'payment_type' => ['La cuenta bancaria del club necesita un teléfono Zelle registrado.'],
            ]);
        }

        if ($validated['payment_type'] === 'zelle' && empty($validated['zelle_phone'])) {
            throw ValidationException::withMessages([
                'zelle_phone' => ['Ingresa el teléfono Zelle desde donde se envió el dinero.'],
            ]);
        }
    }

    private function ensureAccount(Club $club, string $payTo): Account
    {
        $this->assertOperatingAccount($payTo);

        return Account::query()->firstOrCreate(
            ['club_id' => $club->id, 'pay_to' => $payTo],
            ['label' => Str::title(str_replace('_', ' ', $payTo)), 'balance' => 0]
        );
    }

    private function eventPayload(FundraiserEvent $event, $accountLabels = null): array
    {
        $event->loadMissing([
            'products',
            'products.investmentExpense:id,receipt_path,status',
            'investmentExpense:id,receipt_path,status',
            'sales.items',
            'sales.payment.receipt:id,payment_id,receipt_number',
        ]);

        $sales = $event->sales->sortByDesc(fn (FundraiserSale $sale) => sprintf('%s-%010d', optional($sale->sale_date)->toDateString(), $sale->id));
        $products = $event->products->sortBy([
            fn (FundraiserProduct $product) => $product->is_active ? 0 : 1,
            fn (FundraiserProduct $product) => $product->name,
        ]);
        $unitCostsByProduct = $this->unitCostsForEventProducts($event, $products);
        $totalRevenue = round((float) $sales->sum(fn (FundraiserSale $sale) => (float) $sale->total_amount), 2);
        $totalCost = round((float) $sales->sum(fn (FundraiserSale $sale) => $this->saleCostForPayload($sale, $unitCostsByProduct)), 2);
        $totalGain = round($totalRevenue - $totalCost, 2);
        $productInvestmentTotal = round((float) $products->sum(fn (FundraiserProduct $product) => (float) $product->investment_amount), 2);
        $investmentTotal = round((float) $event->investment_total + $productInvestmentTotal, 2);
        $costBasis = max($investmentTotal, $totalCost);
        $remainingInventory = $products
            ->filter(fn (FundraiserProduct $product) => $product->quantity_available !== null)
            ->sum(fn (FundraiserProduct $product) => max((int) $product->quantity_available - (int) $product->quantity_sold, 0));

        return [
            'id' => (int) $event->id,
            'club_id' => (int) $event->club_id,
            'name' => $event->name,
            'fundraiser_type' => $event->fundraiser_type,
            'event_date' => optional($event->event_date)->toDateString(),
            'pay_to' => $event->pay_to,
            'account_label' => $accountLabels ? ($accountLabels[$event->pay_to] ?? $event->pay_to) : $event->pay_to,
            'kitchen_url' => $event->fundraiser_type === 'food'
                ? URL::signedRoute('fundraisers.kitchen.show', ['fundraiserEvent' => $event])
                : null,
            'investment_total' => (float) $event->investment_total,
            'investment_expense_id' => $event->investment_expense_id ? (int) $event->investment_expense_id : null,
            'investment_pay_to' => $event->investment_pay_to,
            'investment_funds_location' => $event->investment_funds_location,
            'investment_expense' => $this->expensePayload($event->investmentExpense),
            'planned_units' => $event->planned_units,
            'description' => $event->description,
            'status' => $event->status,
            'products' => $products->map(fn (FundraiserProduct $product) => $this->productPayload($product))->values()->all(),
            'sales' => $sales->take(40)->map(fn (FundraiserSale $sale) => $this->salePayload($sale, $unitCostsByProduct))->values()->all(),
            'totals' => [
                'revenue' => $totalRevenue,
                'allocated_cost' => $totalCost,
                'gross_gain' => $totalGain,
                'investment_total' => $investmentTotal,
                'event_investment' => (float) $event->investment_total,
                'product_investment' => $productInvestmentTotal,
                'cost_basis' => $costBasis,
                'net_gain' => round($totalRevenue - $costBasis, 2),
                'unallocated_investment' => max(round($investmentTotal - $totalCost, 2), 0),
                'items_sold' => (int) $sales->sum(fn (FundraiserSale $sale) => $sale->items->sum('quantity')),
                'remaining_inventory' => (int) $remainingInventory,
                'receipt_count' => (int) $sales->filter(fn (FundraiserSale $sale) => $sale->payment?->receipt)->count(),
                'sale_count' => (int) $sales->count(),
            ],
        ];
    }

    private function productPayload(FundraiserProduct $product): array
    {
        $remaining = $product->quantity_available !== null
            ? max((int) $product->quantity_available - (int) $product->quantity_sold, 0)
            : null;

        return [
            'id' => (int) $product->id,
            'fundraiser_event_id' => (int) $product->fundraiser_event_id,
            'name' => $product->name,
            'description' => $product->description,
            'sale_price' => (float) $product->sale_price,
            'unit_cost' => (float) $product->unit_cost,
            'investment_amount' => (float) $product->investment_amount,
            'investment_expense_id' => $product->investment_expense_id ? (int) $product->investment_expense_id : null,
            'investment_expense' => $this->expensePayload($product->investmentExpense),
            'tracks_inventory' => (bool) $product->tracks_inventory,
            'quantity_available' => $product->quantity_available,
            'quantity_sold' => (int) $product->quantity_sold,
            'quantity_remaining' => $remaining,
            'is_active' => (bool) $product->is_active,
        ];
    }

    private function unitCostForProduct(array $validated, float $investmentAmount, int $plannedQuantity): float
    {
        if ($investmentAmount > 0 && $plannedQuantity > 0) {
            return round($investmentAmount / $plannedQuantity, 2);
        }

        if (array_key_exists('unit_cost', $validated) && $validated['unit_cost'] !== null && $validated['unit_cost'] !== '') {
            return round((float) $validated['unit_cost'], 2);
        }

        return 0.0;
    }

    private function unitCostsForEventProducts(FundraiserEvent $event, $products, array $quantityOverrides = []): array
    {
        $products = collect($products);
        $generalInvestment = round((float) $event->investment_total, 2);
        $zeroCostProducts = $products->filter(fn (FundraiserProduct $product) => round((float) $product->unit_cost, 2) <= 0.0);
        $plannedUnits = (int) $zeroCostProducts->sum(fn (FundraiserProduct $product) => $this->plannedUnitsForCost($product, $quantityOverrides));
        $generalUnitCost = $generalInvestment > 0 && $plannedUnits > 0
            ? round($generalInvestment / $plannedUnits, 2)
            : 0.0;

        return $products
            ->mapWithKeys(fn (FundraiserProduct $product) => [
                (int) $product->id => round((float) $product->unit_cost, 2) > 0
                    ? round((float) $product->unit_cost, 2)
                    : $generalUnitCost,
            ])
            ->all();
    }

    private function plannedUnitsForCost(FundraiserProduct $product, array $quantityOverrides = []): int
    {
        return max(
            $product->quantity_available === null ? 0 : (int) $product->quantity_available,
            (int) $product->quantity_sold,
            (int) ($quantityOverrides[(int) $product->id] ?? 0),
        );
    }

    private function recordInvestmentExpense(
        Request $request,
        Club $club,
        string $payTo,
        string $fundsLocation,
        float $amount,
        string $expenseDate,
        string $description,
        string $receiptField
    ): Expense {
        $this->assertOperatingAccount($payTo);
        $amount = round($amount, 2);
        $fundingPlan = $this->treasuryService->expenseFundingPlan($club, $payTo, $fundsLocation, $amount);
        $account = $this->ensureAccount($club, $payTo);
        $fromAccount = (float) $fundingPlan['amount_from_account'];
        $shortfall = (float) $fundingPlan['reimbursement_shortfall'];
        $receiptPath = $request->hasFile($receiptField)
            ? $request->file($receiptField)->store('expense-receipts', 'public')
            : null;
        $expense = null;

        if ($fromAccount > 0) {
            $this->treasuryService->recordAutomaticExpenseFundingTransfer(
                $club,
                $fundingPlan,
                $request->user()?->id,
                $expenseDate,
                'Transferencia automatica para registrar inversion fundraiser desde ' . $fundsLocation . '.'
            );

            $expense = Expense::query()->create([
                'club_id' => $club->id,
                'pay_to' => $payTo,
                'funds_location' => $fundsLocation,
                'payment_concept_id' => null,
                'payee_id' => null,
                'amount' => $fromAccount,
                'expense_date' => $expenseDate,
                'description' => $description,
                'reimbursed_to' => null,
                'created_by_user_id' => $request->user()?->id,
                'status' => $receiptPath ? 'completed' : 'working',
                'receipt_path' => $receiptPath,
            ]);

            $account->decrement('balance', $fromAccount);
        }

        if ($shortfall > 0) {
            $reimbursementPayee = $this->resolveFundraiserReimbursementPayee($club, $request);
            $reimbursementConcept = $this->reimbursementConceptFor($club, $request, $reimbursementPayee);
            $reimbursementAccount = $this->resolveAccount($club->id, 'reimbursement_to');

            $reimbursementExpense = Expense::query()->create([
                'club_id' => $club->id,
                'pay_to' => 'reimbursement_to',
                'funds_location' => null,
                'payment_concept_id' => $reimbursementConcept->id,
                'payee_id' => $reimbursementConcept->payee_id,
                'reimbursement_payee_id' => $reimbursementPayee->id,
                'amount' => $shortfall,
                'expense_date' => $expenseDate,
                'description' => 'Reembolso pendiente por inversion fundraiser con saldo insuficiente.',
                'reimbursed_to' => $reimbursementPayee->name,
                'created_by_user_id' => $request->user()?->id,
                'status' => 'pending_reimbursement',
                'receipt_path' => $expense ? null : $receiptPath,
            ]);

            $reimbursementAccount->decrement('balance', $shortfall);

            return $expense ?: $reimbursementExpense;
        }

        return $expense;
    }

    private function reimbursementConceptFor(Club $club, Request $request, FinanceReimbursementPayee $payee): PaymentConcept
    {
        return PaymentConcept::query()->firstOrCreate(
            [
                'club_id' => $club->id,
                'pay_to' => 'reimbursement_to',
                'payee_type' => FinanceReimbursementPayee::class,
                'payee_id' => $payee->id,
            ],
            [
                'concept' => 'Reembolso a ' . $payee->name,
                'payment_expected_by' => null,
                'type' => 'optional',
                'status' => 'active',
                'amount' => 0,
                'created_by' => $request->user()?->id,
            ]
        );
    }

    private function resolveFundraiserReimbursementPayee(Club $club, Request $request): FinanceReimbursementPayee
    {
        $staff = Staff::query()
            ->where('user_id', $request->user()?->id)
            ->where('club_id', $club->id)
            ->first();

        $name = $staff
            ? (ClubHelper::staffDetail($staff)['name'] ?? $request->user()?->name ?? 'Personal')
            : ($request->user()?->name ?? 'Director');

        return $this->storeReimbursementPayee(
            $club,
            $request,
            $name,
            null,
            $this->normalizeEmail($request->user()?->email ?? null)
        );
    }

    private function storeReimbursementPayee(Club $club, Request $request, string $name, ?string $phone, ?string $email): FinanceReimbursementPayee
    {
        $identity = $email
            ? ['club_id' => $club->id, 'email' => $email]
            : ['club_id' => $club->id, 'name' => $name, 'phone' => $phone];

        $payee = FinanceReimbursementPayee::query()->firstOrNew($identity);
        $payee->fill([
            'club_id' => $club->id,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'created_by_user_id' => $payee->created_by_user_id ?: $request->user()?->id,
        ]);
        $payee->save();

        return $payee;
    }

    private function normalizeText(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeEmail(?string $value): ?string
    {
        $normalized = $this->normalizeText($value);

        return $normalized ? strtolower($normalized) : null;
    }

    private function resolveAccount(int $clubId, string $payTo): Account
    {
        return Account::query()->firstOrCreate(
            ['club_id' => $clubId, 'pay_to' => $payTo],
            ['label' => Str::title(str_replace('_', ' ', $payTo)), 'balance' => 0]
        );
    }

    private function expensePayload(?Expense $expense): ?array
    {
        if (!$expense) {
            return null;
        }

        return [
            'id' => (int) $expense->id,
            'status' => $expense->status,
            'receipt_url' => $expense->receipt_url,
        ];
    }

    private function salePayload(FundraiserSale $sale, ?array $unitCostsByProduct = null): array
    {
        $sale->loadMissing(['items', 'payment.receipt:id,payment_id,receipt_number']);
        $receipt = $sale->payment?->receipt;
        $totalCost = $unitCostsByProduct === null
            ? (float) $sale->total_cost
            : $this->saleCostForPayload($sale, $unitCostsByProduct);
        $totalAmount = (float) $sale->total_amount;

        return [
            'id' => (int) $sale->id,
            'fundraiser_event_id' => (int) $sale->fundraiser_event_id,
            'club_id' => (int) $sale->club_id,
            'payment_id' => $sale->payment_id ? (int) $sale->payment_id : null,
            'customer_name' => $sale->customer_name,
            'sale_date' => optional($sale->sale_date)->toDateString(),
            'payment_type' => $sale->payment_type,
            'created_at' => optional($sale->created_at)->toISOString(),
            'total_amount' => $totalAmount,
            'total_cost' => $totalCost,
            'gain_amount' => round($totalAmount - $totalCost, 2),
            'notes' => $sale->notes,
            'kitchen_status' => $sale->kitchen_status ?: 'pending',
            'kitchen_completed_at' => optional($sale->kitchen_completed_at)->toISOString(),
            'receipt' => $receipt ? $this->receiptPayload($receipt) : null,
            'items' => $sale->items->map(function ($item) use ($unitCostsByProduct) {
                $unitCost = $unitCostsByProduct === null
                    ? (float) $item->unit_cost
                    : $this->unitCostForSaleItem($item, $unitCostsByProduct);
                $lineTotal = (float) $item->line_total;
                $lineCost = round($unitCost * (int) $item->quantity, 2);

                return [
                    'id' => (int) $item->id,
                    'fundraiser_product_id' => $item->fundraiser_product_id ? (int) $item->fundraiser_product_id : null,
                    'item_name' => $item->item_name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'line_cost' => $lineCost,
                    'line_gain' => round($lineTotal - $lineCost, 2),
                ];
            })->values()->all(),
        ];
    }

    private function saleCostForPayload(FundraiserSale $sale, array $unitCostsByProduct): float
    {
        $sale->loadMissing('items');

        return round((float) $sale->items->sum(
            fn ($item) => round($this->unitCostForSaleItem($item, $unitCostsByProduct) * (int) $item->quantity, 2)
        ), 2);
    }

    private function unitCostForSaleItem($item, array $unitCostsByProduct): float
    {
        if ($item->fundraiser_product_id && array_key_exists((int) $item->fundraiser_product_id, $unitCostsByProduct)) {
            return round((float) $unitCostsByProduct[(int) $item->fundraiser_product_id], 2);
        }

        return round((float) $item->unit_cost, 2);
    }

    private function receiptPayload(PaymentReceipt $receipt): array
    {
        return [
            'id' => (int) $receipt->id,
            'number' => $receipt->receipt_number,
            'url' => route('payment-receipts.download', $receipt),
            'public_url' => $this->paymentReceiptService->publicDownloadUrl($receipt),
            'qr_url' => $this->paymentReceiptService->publicQrUrl($receipt),
        ];
    }

    private function assertKitchenEvent(FundraiserEvent $event): void
    {
        $event->loadMissing('club');

        abort_if($event->fundraiser_type !== 'food', 404);
    }

    private function kitchenSalePayload(FundraiserSale $sale): array
    {
        $sale->loadMissing(['items', 'payment.receipt:id,payment_id,receipt_number']);

        return [
            'id' => (int) $sale->id,
            'customer_name' => $sale->customer_name,
            'sale_date' => optional($sale->sale_date)->toDateString(),
            'created_at' => optional($sale->created_at)->toISOString(),
            'payment_type' => $sale->payment_type,
            'total_amount' => (float) $sale->total_amount,
            'kitchen_status' => $sale->kitchen_status ?: 'pending',
            'kitchen_completed_at' => optional($sale->kitchen_completed_at)->toISOString(),
            'finish_url' => $sale->kitchen_status === 'finished'
                ? null
                : URL::signedRoute('fundraisers.kitchen.orders.finish', [
                    'fundraiserEvent' => $sale->fundraiser_event_id,
                    'fundraiserSale' => $sale,
                ]),
            'receipt' => $sale->payment?->receipt ? $this->receiptPayload($sale->payment->receipt) : null,
            'items' => $sale->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'item_name' => $item->item_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->values()->all(),
        ];
    }
}
