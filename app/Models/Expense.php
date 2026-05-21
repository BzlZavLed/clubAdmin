<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Expense extends Model
{
    protected $fillable = [
        'club_id',
        'event_id',
        'pay_to',
        'funds_location',
        'payment_concept_id',
        'payee_id',
        'amount',
        'expense_date',
        'description',
        'reimbursed_to',
        'reimbursement_payee_id',
        'created_by_user_id',
        'status',
        'receipt_path',
        'reimbursement_receipt_path',
        'reimbursement_receipt_token',
        'reimbursement_receipt_signed_at',
        'reimbursement_receipt_signature_path',
        'reimbursement_receipt_signer_name',
        'reimbursement_receipt_acknowledged',
        'reimbursement_receipt_ip',
        'reimbursement_receipt_user_agent',
        'reimbursement_receipt_validation_checksum',
        'settles_expense_id',
        'reimbursement_origin_expense_id',
        'reversed_expense_id',
        'is_cancelled',
        'related_canceled_movement_id',
        'canceling_id',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'is_cancelled' => 'boolean',
        'reimbursement_receipt_signed_at' => 'datetime',
        'reimbursement_receipt_acknowledged' => 'boolean',
    ];

    protected $appends = [
        'receipt_url',
        'reimbursement_receipt_url',
        'reimbursement_signature_url',
        'reimbursement_confirmation_url',
        'reimbursement_confirmation_qr_url',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reimbursementPayee()
    {
        return $this->belongsTo(FinanceReimbursementPayee::class, 'reimbursement_payee_id');
    }

    public function fundraiserInvestmentReceipts()
    {
        return $this->hasMany(FundraiserInvestmentReceipt::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function settlementExpense()
    {
        return $this->hasOne(self::class, 'settles_expense_id');
    }

    public function settledReimbursement()
    {
        return $this->belongsTo(self::class, 'settles_expense_id');
    }

    public function reimbursementOriginExpense()
    {
        return $this->belongsTo(self::class, 'reimbursement_origin_expense_id');
    }

    public function generatedReimbursementExpense()
    {
        return $this->hasOne(self::class, 'reimbursement_origin_expense_id')
            ->where('pay_to', 'reimbursement_to')
            ->whereNull('settles_expense_id');
    }

    public function reversedExpense()
    {
        return $this->belongsTo(self::class, 'reversed_expense_id');
    }

    public function reversalExpense()
    {
        return $this->hasOne(self::class, 'reversed_expense_id');
    }

    public function relatedCanceledMovement()
    {
        return $this->belongsTo(self::class, 'related_canceled_movement_id');
    }

    public function cancelingMovement()
    {
        return $this->belongsTo(self::class, 'canceling_id');
    }

    public function getReceiptUrlAttribute(): ?string
    {
        if (!$this->receipt_path) {
            return null;
        }

        return $this->buildPublicUrl($this->receipt_path);
    }

    public function getReimbursementReceiptUrlAttribute(): ?string
    {
        if (!$this->reimbursement_receipt_path) {
            return null;
        }

        return $this->buildPublicUrl($this->reimbursement_receipt_path);
    }

    public function getReimbursementSignatureUrlAttribute(): ?string
    {
        if (!$this->reimbursement_receipt_signature_path) {
            return null;
        }

        return $this->buildPublicUrl($this->reimbursement_receipt_signature_path);
    }

    public function getReimbursementConfirmationUrlAttribute(): ?string
    {
        if (!$this->reimbursement_receipt_token) {
            return null;
        }

        return route('reimbursement-receipts.show', [
            'expense' => $this,
            'token' => $this->reimbursement_receipt_token,
        ]);
    }

    public function getReimbursementConfirmationQrUrlAttribute(): ?string
    {
        if (!$this->reimbursement_receipt_token) {
            return null;
        }

        return route('reimbursement-receipts.qr', [
            'expense' => $this,
            'token' => $this->reimbursement_receipt_token,
        ]);
    }

    protected function buildPublicUrl(string $path): ?string
    {
        $relative = Storage::disk('public')->url($path);
        $host = request()?->getSchemeAndHttpHost();

        if ($host) {
            // If storage returned an absolute URL, replace host with the current request host (keeps dev ports like :8000)
            if (Str::startsWith($relative, ['http://', 'https://'])) {
                $urlPath = parse_url($relative, PHP_URL_PATH) ?? $relative;
                return rtrim($host, '/') . '/' . ltrim($urlPath, '/');
            }

            return rtrim($host, '/') . '/' . ltrim(Str::start($relative, '/'), '/');
        }

        return URL::to($relative);
    }
}
