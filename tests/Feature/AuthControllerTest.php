<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_password_reset_records_only_an_action_event(): void
    {
        $user = $this->createOwner();
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'New-synthetic-password-123',
            'password_confirmation' => 'New-synthetic-password-123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('New-synthetic-password-123', $user->fresh()->password));
        $this->assertDatabaseHas('tbl_store_activity_events', [
            'event_type' => 'password_reset', 'subject_type' => 'user',
            'subject_id' => $user->id, 'actor_id' => $user->id,
        ]);
        $this->assertSame(
            ['id', 'event_type', 'subject_type', 'subject_id', 'actor_id', 'created_at'],
            array_keys((array) DB::table('tbl_store_activity_events')->first()),
        );
    }

    public function test_invalid_reset_token_does_not_record_activity(): void
    {
        $user = $this->createOwner();

        $this->post(route('password.update'), [
            'token' => 'invalid', 'email' => $user->email,
            'password' => 'New-synthetic-password-123',
            'password_confirmation' => 'New-synthetic-password-123',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('tbl_store_activity_events', 0);
    }

    private function createOwner(): User
    {
        return User::query()->create([
            'name' => 'Synthetic Owner', 'email' => 'owner@example.test',
            'password' => 'old-synthetic-password', 'phone' => '0000000000',
            'address' => 'Synthetic address', 'role' => 'store_owner',
            'profile_image' => '', 'status' => 'active',
        ]);
    }
}
