<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Club;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_reverse_payment_with_opposite_movement(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();

        $account = Account::create([
            'club_id' => $club->id,
            'pay_to' => 'club_budget',
            'label' => 'Club budget',
            'balance' => 25.00,
        ]);

        $payment = Payment::create([
            'club_id' => $club->id,
            'payment_concept_id' => null,
            'concept_text' => 'Registro',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'member_id' => null,
            'staff_id' => null,
            'amount_paid' => 25.00,
            'expected_amount' => null,
            'balance_due_after' => null,
            'payment_date' => '2026-04-01',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
            'notes' => 'Pago original',
        ]);

        $this->actingAs($director)
            ->postJson(route('club.director.accounting-corrections.payments.reverse', $payment), [
                'correction_date' => '2026-04-20',
                'reason' => 'Ingreso duplicado',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Ingreso revertido mediante movimiento opuesto.');

        $this->assertDatabaseHas('payments', [
            'reversed_payment_id' => $payment->id,
            'club_id' => $club->id,
            'amount_paid' => -25.00,
            'payment_type' => 'internal',
        ]);

        $this->assertSame('0.00', Account::findOrFail($account->id)->balance);
    }

    public function test_director_can_reverse_expense_with_opposite_movement(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();

        $account = Account::create([
            'club_id' => $club->id,
            'pay_to' => 'club_budget',
            'label' => 'Club budget',
            'balance' => 0.00,
        ]);

        $expense = Expense::create([
            'club_id' => $club->id,
            'pay_to' => 'club_budget',
            'amount' => 18.50,
            'expense_date' => '2026-04-05',
            'description' => 'Materiales',
            'created_by_user_id' => $director->id,
            'status' => 'completed',
        ]);

        $this->actingAs($director)
            ->postJson(route('club.director.accounting-corrections.expenses.reverse', $expense), [
                'correction_date' => '2026-04-20',
                'reason' => 'Gasto duplicado',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Gasto revertido mediante movimiento opuesto.');

        $this->assertDatabaseHas('expenses', [
            'reversed_expense_id' => $expense->id,
            'club_id' => $club->id,
            'amount' => -18.50,
            'status' => 'completed',
        ]);

        $this->assertSame('18.50', Account::findOrFail($account->id)->balance);
    }

    public function test_payment_delete_endpoint_is_blocked_in_favor_of_accounting_corrections(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();

        $account = Account::create([
            'club_id' => $club->id,
            'pay_to' => 'club_budget',
            'label' => 'Club budget',
            'balance' => 10.00,
        ]);

        $payment = Payment::create([
            'club_id' => $club->id,
            'payment_concept_id' => null,
            'concept_text' => 'Pago',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'member_id' => null,
            'staff_id' => null,
            'amount_paid' => 10.00,
            'expected_amount' => null,
            'balance_due_after' => null,
            'payment_date' => '2026-04-10',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);

        $this->actingAs($director)
            ->deleteJson(route('club.payments.destroy', $payment))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Los pagos ya no se eliminan. Usa el modulo de correcciones contables para generar el movimiento opuesto.');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
        ]);
    }

    protected function makeDirectorAndClub(): array
    {
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => 'North Star Club',
            'church_name' => 'North Star Church',
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'pastor_name' => 'Pastor Test',
            'conference_name' => 'Test Conference',
            'conference_region' => '1',
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);

        $director->update(['club_id' => $club->id]);

        return [$director->fresh(), $club->fresh()];
    }
}
