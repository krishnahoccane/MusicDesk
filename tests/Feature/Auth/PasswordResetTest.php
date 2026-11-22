<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected const GENERIC = 'If an account exists for that email address, a password reset link has been sent to it.';

    public function test_login_page_links_to_password_reset(): void
    {
        $this->get('/login')->assertSee(route('password.request'));
        $this->get('/forgot-password')->assertOk()->assertSee('Forgot your password?');
    }

    public function test_reset_link_is_sent_to_an_active_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', self::GENERIC);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_response_is_identical_for_unknown_and_inactive_accounts(): void
    {
        Notification::fake();
        $inactive = User::factory()->inactive()->create();

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status', self::GENERIC);
        $this->post('/forgot-password', ['email' => $inactive->email])->assertSessionHas('status', self::GENERIC);

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))->assertOk();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ])->assertRedirect(route('login'))->assertSessionHas('success');

        $this->assertTrue(Hash::check('NewSecret123', $user->fresh()->password));
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth.password_reset', 'user_id' => $user->id]);
    }

    public function test_invalid_token_and_weak_password_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'invalid', 'email' => $user->email,
            'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123',
        ])->assertSessionHasErrors('email');

        $token = Password::broker()->createToken($user);
        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_inactive_users_cannot_reset_their_password(): void
    {
        $user = User::factory()->inactive()->create();
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123',
        ])->assertSessionHasErrors('email');
    }
}
