<?php

namespace Tests\Feature\Cattle;

// ══════════════════════════════════════════════════════════════════
//  UsersAndAccessTest
//
//  Who gets in, and what they can hand out:
//    • deactivated users / deactivated or expired companies cannot log in
//      and are thrown out of an open session
//    • user management stays inside the company; nobody edits, deletes or
//      deactivates users of another company, or themselves
//    • user quota, farms of the user's own company only, unknown
//      permissions ignored, no giving away more than you hold
//    • Super Admin company context: needed to act, entered / left by
//      the Super Admin only
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Models\Farm;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CattleScenario;
use Tests\TestCase;

class UsersAndAccessTest extends TestCase
{
    use RefreshDatabase;

    private CattleScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CattleScenario::seedPermissions();
        $this->s = CattleScenario::make();
    }

    private function newUserData(array $overrides = []): array
    {
        return array_merge([
            'company_id'            => $this->s->company->id,
            'name_en'               => 'New Person',
            'email'                 => 'new.person@example.test',
            'password'              => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'permissions'           => ['view animals'],
        ], $overrides);
    }

    private function login(User $user, string $password = 'password')
    {
        return $this->post(route('login'), ['email' => $user->email, 'password' => $password]);
    }

    // ── 1. getting in ─────────────────────────────────────────────

    public function test_an_active_user_can_log_in(): void
    {
        $user = $this->s->user(['view animals']);

        $this->login($user)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $user = $this->s->user(['view animals']);

        $this->login($user, 'wrong-password')->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_log_in(): void
    {
        $user = $this->s->user(['view animals']);
        $user->update(['is_active' => false]);

        $this->login($user)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_deactivated_company_cannot_log_in(): void
    {
        $user = $this->s->user(['view animals']);
        $this->s->company->update(['is_active' => false]);

        $this->login($user)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_an_expired_subscription_cannot_log_in(): void
    {
        $user = $this->s->user(['view animals']);
        $this->s->company->update(['subscription_ends_at' => now()->subDays(3)->toDateString()]);

        $this->login($user)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_user_deactivated_while_logged_in_is_thrown_out(): void
    {
        $user = $this->s->user(['view animals']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_an_expired_subscription_throws_out_an_open_session(): void
    {
        $user = $this->s->user(['view animals']);
        $this->s->company->update(['subscription_ends_at' => now()->subDay()->toDateString()]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    // ── 2. managing users ─────────────────────────────────────────

    public function test_an_admin_creates_a_user_with_chosen_permissions(): void
    {
        $this->actingAs($this->s->admin())
            ->post(route('users.store'), $this->newUserData(['permissions' => ['view animals', 'create animals']]))
            ->assertSessionHasNoErrors();

        $new = User::where('email', 'new.person@example.test')->firstOrFail();
        $this->assertSame($this->s->company->id, (int) $new->company_id);
        $this->assertTrue($new->hasPermissionTo('create animals'));
        $this->assertFalse($new->hasPermissionTo('delete animals'));
        $this->assertNotSame('secret-pass-1', $new->password);            // stored hashed
    }

    public function test_unknown_permission_names_are_ignored(): void
    {
        $this->actingAs($this->s->admin())
            ->post(route('users.store'), $this->newUserData(['permissions' => ['view animals', 'god mode']]))
            ->assertSessionHasNoErrors();

        $new = User::where('email', 'new.person@example.test')->firstOrFail();
        $this->assertSame(['view animals'], $new->getDirectPermissions()->pluck('name')->all());
    }

    public function test_a_duplicate_email_and_a_weak_password_are_refused(): void
    {
        $admin = $this->s->admin();
        $existing = $this->s->user([]);

        $this->actingAs($admin)->post(route('users.store'), $this->newUserData(['email' => $existing->email]))
            ->assertSessionHasErrors('email');
        $this->actingAs($admin)->post(route('users.store'), $this->newUserData(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');
    }

    public function test_a_company_admin_cannot_create_a_user_in_another_company(): void
    {
        $other = CattleScenario::make();

        $this->actingAs($this->s->admin())
            ->post(route('users.store'), $this->newUserData(['company_id' => $other->company->id]))
            ->assertForbidden();

        $this->assertSame(0, User::where('email', 'new.person@example.test')->count());
    }

    public function test_the_user_quota_is_enforced(): void
    {
        $admin = $this->s->admin();                       // 1 user
        $this->s->user([]);                               // 2 users
        $this->s->company->update(['max_users' => 2]);

        $this->actingAs($admin)->post(route('users.store'), $this->newUserData())
            ->assertSessionHasErrors('quota');

        $this->assertSame(0, User::where('email', 'new.person@example.test')->count());
    }

    public function test_farms_of_another_company_cannot_be_given_to_a_user(): void
    {
        $theirs = CattleScenario::make();

        $this->actingAs($this->s->admin())
            ->post(route('users.store'), $this->newUserData(['farm_ids' => [$theirs->farm->id]]))
            ->assertSessionHasErrors('farm_ids');

        $this->assertSame(0, User::where('email', 'new.person@example.test')->count());
    }

    public function test_own_company_farms_can_be_given_to_a_user(): void
    {
        $this->actingAs($this->s->admin())
            ->post(route('users.store'), $this->newUserData(['farm_ids' => [$this->s->farm->id]]))
            ->assertSessionHasNoErrors();

        $new = User::where('email', 'new.person@example.test')->firstOrFail();
        $this->assertSame([$this->s->farm->id], $new->farms()->pluck('farms.id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_a_user_with_only_create_users_cannot_hand_out_more_than_they_hold(): void
    {
        $manager = $this->s->user(['view users', 'create users', 'view animals']);

        $this->actingAs($manager)
            ->post(route('users.store'), $this->newUserData(['permissions' => ['view animals', 'delete sales', 'delete farms']]))
            ->assertSessionHasNoErrors();

        $new = User::where('email', 'new.person@example.test')->firstOrFail();
        $this->assertTrue($new->hasPermissionTo('view animals'));
        $this->assertFalse($new->hasPermissionTo('delete sales'));
        $this->assertFalse($new->hasPermissionTo('delete farms'));
    }

    public function test_only_an_admin_can_give_the_company_admin_role(): void
    {
        $manager = $this->s->user(['view users', 'create users']);

        $this->actingAs($manager)
            ->post(route('users.store'), $this->newUserData(['role' => 'Company Admin']))
            ->assertForbidden();

        $this->assertSame(0, User::where('email', 'new.person@example.test')->count());
    }

    public function test_a_view_only_user_cannot_manage_users(): void
    {
        $viewer = $this->s->user(['view users']);
        $target = $this->s->user([]);

        $this->actingAs($viewer)->post(route('users.store'), $this->newUserData())->assertForbidden();
        $this->actingAs($viewer)->put(route('users.update', $target), $this->newUserData(['email' => 'x@example.test']))->assertForbidden();
        $this->actingAs($viewer)->delete(route('users.destroy', $target))->assertForbidden();
        $this->actingAs($viewer)->patch(route('users.toggle-active', $target))->assertForbidden();

        $this->assertNotNull(User::find($target->id));
        $this->assertTrue((bool) $target->fresh()->is_active);
    }

    public function test_nobody_manages_users_of_another_company(): void
    {
        $admin  = $this->s->admin();
        $theirs = CattleScenario::make();
        $victim = $theirs->user(['view animals']);

        $this->actingAs($admin)->put(route('users.update', $victim), $this->newUserData(['company_id' => $theirs->company->id, 'email' => 'hijack@example.test']))->assertForbidden();
        $this->actingAs($admin)->delete(route('users.destroy', $victim))->assertForbidden();
        $this->actingAs($admin)->patch(route('users.toggle-active', $victim))->assertForbidden();
        $this->actingAs($admin)->get(route('users.edit', $victim))->assertForbidden();

        $fresh = $victim->fresh();
        $this->assertNotNull($fresh);
        $this->assertNotSame('hijack@example.test', $fresh->email);
        $this->assertTrue((bool) $fresh->is_active);
    }

    public function test_nobody_can_delete_or_deactivate_themselves(): void
    {
        $admin = $this->s->admin();

        $this->actingAs($admin)->delete(route('users.destroy', $admin))->assertForbidden();
        $this->actingAs($admin)->patch(route('users.toggle-active', $admin))->assertForbidden();

        $this->assertNotNull(User::find($admin->id));
        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    public function test_an_admin_can_deactivate_and_reactivate_a_colleague(): void
    {
        $admin  = $this->s->admin();
        $mate   = $this->s->user([]);

        $this->actingAs($admin)->patch(route('users.toggle-active', $mate))->assertSessionHasNoErrors();
        $this->assertFalse((bool) $mate->fresh()->is_active);

        $this->actingAs($admin)->patch(route('users.toggle-active', $mate))->assertSessionHasNoErrors();
        $this->assertTrue((bool) $mate->fresh()->is_active);
    }

    public function test_updating_a_user_without_a_password_keeps_the_old_one(): void
    {
        $admin = $this->s->admin();
        $mate  = $this->s->user([]);
        $old   = $mate->password;

        $this->actingAs($admin)->put(route('users.update', $mate), [
            'company_id' => $this->s->company->id,
            'name_en'    => 'Renamed',
            'email'      => $mate->email,
            'permissions' => ['view animals'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $mate->fresh()->name_en);
        $this->assertSame($old, $mate->fresh()->password);
        $this->assertTrue($mate->fresh()->hasPermissionTo('view animals'));
    }

    // ── 3. Super Admin company context ────────────────────────────

    public function test_a_super_admin_needs_a_company_context_to_act(): void
    {
        $sa = $this->s->superAdmin();

        $this->actingAs($sa)->post(route('fiscal-years.store'), [
            'year' => 2031, 'start_date' => '2031-01-01', 'end_date' => '2031-12-31',
        ])->assertStatus(422);

        $this->assertSame(0, FiscalYear::where('year', 2031)->count());
    }

    public function test_entering_a_company_makes_actions_land_in_that_company(): void
    {
        $sa = $this->s->superAdmin();
        $this->actingAs($sa)->post(route('companies.enter', $this->s->company))->assertRedirect(route('dashboard'));

        $this->actingAs($sa)->post(route('fiscal-years.store'), [
            'year' => 2031, 'start_date' => '2031-01-01', 'end_date' => '2031-12-31',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->s->company->id, (int) FiscalYear::where('year', 2031)->firstOrFail()->company_id);
    }

    public function test_exiting_clears_the_company_context(): void
    {
        $sa = $this->s->superAdmin();
        $this->actingAs($sa)->post(route('companies.enter', $this->s->company));
        $this->actingAs($sa)->post(route('companies.exit'))->assertRedirect(route('companies.index'));

        $this->actingAs($sa)->post(route('fiscal-years.store'), [
            'year' => 2031, 'start_date' => '2031-01-01', 'end_date' => '2031-12-31',
        ])->assertStatus(422);
    }

    public function test_only_a_super_admin_can_enter_or_leave_a_company(): void
    {
        $admin = $this->s->admin();
        $other = CattleScenario::make();

        $this->actingAs($admin)->post(route('companies.enter', $other->company))->assertForbidden();
        $this->actingAs($admin)->post(route('companies.exit'))->assertForbidden();
        $this->assertNull(session('selected_company_id'));
    }

    public function test_a_company_admin_cannot_open_company_management(): void
    {
        $admin = $this->s->admin();

        $this->actingAs($admin)->get(route('companies.index'))->assertForbidden();
        $this->actingAs($admin)->delete(route('companies.destroy', $this->s->company))->assertForbidden();
        $this->actingAs($admin)->patch(route('companies.toggle-active', $this->s->company))->assertForbidden();

        $this->assertTrue((bool) $this->s->company->fresh()->is_active);
    }

    public function test_a_company_admin_cannot_extend_its_own_subscription_or_quota(): void
    {
        $admin = $this->s->admin();
        $this->s->company->update(['subscription_ends_at' => now()->addDays(10)->toDateString(), 'max_users' => 5]);

        $this->actingAs($admin)->put(route('companies.update', $this->s->company), [
            'name_en'              => 'Renamed Co',
            'name_ar'              => 'شركة',
            'subscription_ends_at' => now()->addYears(20)->toDateString(),
            'max_users'            => 999,
            'is_active'            => true,
        ])->assertSessionHasNoErrors();

        $fresh = $this->s->company->fresh();
        $this->assertSame('Renamed Co', $fresh->name_en);
        $this->assertSame(now()->addDays(10)->toDateString(), $fresh->subscription_ends_at->toDateString());
        $this->assertSame(5, (int) $fresh->max_users);
    }

    public function test_a_super_admin_can_change_subscription_and_quota(): void
    {
        $sa = $this->s->superAdmin();

        $this->actingAs($sa)->put(route('companies.update', $this->s->company), [
            'name_en'              => 'Co',
            'name_ar'              => 'شركة',
            'subscription_ends_at' => '2030-01-01',
            'max_users'            => 12,
            'is_active'            => true,
        ])->assertSessionHasNoErrors();

        $fresh = $this->s->company->fresh();
        $this->assertSame('2030-01-01', $fresh->subscription_ends_at->toDateString());
        $this->assertSame(12, (int) $fresh->max_users);
    }
}
