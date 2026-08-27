<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ParentHelpTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_open_help_page_and_embedded_guide(): void
    {
        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($parent)
            ->get(route('parent.help'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Parent/Help')
                ->where('account_guide_url', route('parent.help.guide'))
                ->where('payments_guide_url', route('parent.help.payments-guide')));

        $this->get(route('parent.help.guide'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="parent-account-and-child-registration-guide.pdf"')
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->get(route('parent.help.payments-guide'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="parent-payments-guide.pdf"')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_guest_cannot_open_parent_help_or_guide(): void
    {
        $this->get(route('parent.help'))->assertRedirect(route('login'));
        $this->get(route('parent.help.guide'))->assertRedirect(route('login'));
        $this->get(route('parent.help.payments-guide'))->assertRedirect(route('login'));
    }

    public function test_non_parent_cannot_open_parent_help_or_guide(): void
    {
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'status' => 'active',
        ]);

        $this->actingAs($director)->get(route('parent.help'))->assertForbidden();
        $this->get(route('parent.help.guide'))->assertForbidden();
        $this->get(route('parent.help.payments-guide'))->assertForbidden();
    }
}
