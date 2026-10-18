<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    /**
     * VERO's profile form is bilingual (name_en / name_ar) and also carries the
     * language and theme preferences, so the request must send those fields.
     */
    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'name_en'  => 'Test User',
            'name_ar'  => '',
            'email'    => 'test@example.com',
            'language' => 'en',
            'theme'    => 'dark',
        ], $overrides);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', $this->profilePayload());

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name_en);
        $this->assertSame('Test User', $user->name);   // legacy `name` is derived from name_en / name_ar
        $this->assertSame('test@example.com', $user->email);
        $this->assertSame('dark', $user->theme);
    }

    public function test_name_is_required_in_at_least_one_language(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', $this->profilePayload(['name_en' => '', 'name_ar' => '']));

        $response->assertSessionHasErrors('name_en');
    }

    public function test_arabic_only_name_is_accepted(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', $this->profilePayload(['name_en' => '', 'name_ar' => 'مستخدم تجريبي']));

        $response->assertSessionHasNoErrors();

        $this->assertSame('مستخدم تجريبي', $user->refresh()->name);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', $this->profilePayload(['email' => $user->email]));

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
