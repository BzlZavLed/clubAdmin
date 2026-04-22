<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->string('club_code', 12)->nullable()->after('club_id');
            $table->unsignedSmallInteger('receipt_year')->nullable()->after('club_code');
            $table->unsignedInteger('club_sequence')->nullable()->after('receipt_year');
            $table->unique(['club_id', 'receipt_year', 'club_sequence'], 'payment_receipts_club_year_sequence_unique');
        });

        DB::table('payment_receipts')
            ->join('clubs', 'payment_receipts.club_id', '=', 'clubs.id')
            ->orderBy('payment_receipts.id')
            ->select('payment_receipts.id', 'payment_receipts.club_id', 'payment_receipts.issued_at', 'clubs.club_name')
            ->get()
            ->groupBy(function ($receipt) {
                return $receipt->club_id . '|' . substr((string) $receipt->issued_at, 0, 4);
            })
            ->each(function ($receipts): void {
                $sequence = 1;
                foreach ($receipts as $receipt) {
                    $year = (int) substr((string) $receipt->issued_at, 0, 4);
                    $clubCode = $this->clubCode((string) $receipt->club_name, (int) $receipt->club_id);

                    DB::table('payment_receipts')
                        ->where('id', $receipt->id)
                        ->update([
                            'club_code' => $clubCode,
                            'receipt_year' => $year,
                            'club_sequence' => $sequence,
                            'receipt_number' => sprintf('RCPT-%s-%s-%06d', $year, $clubCode, $sequence),
                        ]);
                    $sequence++;
                }
            });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropUnique('payment_receipts_club_year_sequence_unique');
            $table->dropColumn(['club_code', 'receipt_year', 'club_sequence']);
        });
    }

    private function clubCode(string $name, int $clubId): string
    {
        $letters = Str::upper(preg_replace('/[^A-Z0-9]/i', '', $name));
        $prefix = substr($letters ?: 'CLUB', 0, 4);
        $suffix = str_pad((string) $clubId, 7, '0', STR_PAD_LEFT);

        return substr(str_pad($prefix, 4, 'X') . $suffix, 0, 12);
    }
};
