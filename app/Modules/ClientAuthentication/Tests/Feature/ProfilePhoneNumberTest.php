<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Shared\Helpers\PhilippineMobileNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phone numbers are Philippine mobile numbers kept as 11 digits: 09123456789.
 */
class ProfilePhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_way_of_typing_a_number_is_stored_as_eleven_digits(): void
    {
        foreach (['09123456789', '+63 912 345 6789', '639123456789', '9123456789', '0912-345-6789'] as $typed) {
            $this->assertSame('09123456789', PhilippineMobileNumber::normalise($typed), $typed);
        }

        $this->assertNull(PhilippineMobileNumber::normalise('  '));
        // Not a number at all: kept as typed so validation refuses it.
        $this->assertSame('call me', PhilippineMobileNumber::normalise('call me'));
    }

    public function test_the_profile_phone_is_normalised_and_anything_else_is_refused(): void
    {
        $user = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $token = $user->createToken('client', ['client:auth'])->plainTextToken;

        $this->withToken($token)->patchJson('/api/client/v1/auth/me', ['phone' => '+63 912 345 6789'])
            ->assertOk()
            ->assertJsonPath('data.phone', '09123456789');

        foreach (['0912345678', '091234567890', '08123456789', '+1 555 0100', 'call me'] as $wrong) {
            $this->withToken($token)->patchJson('/api/client/v1/auth/me', ['phone' => $wrong])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['phone' => PhilippineMobileNumber::MESSAGE]);
        }

        // Clearing the number is still allowed.
        $this->withToken($token)->patchJson('/api/client/v1/auth/me', ['phone' => ''])
            ->assertOk()
            ->assertJsonPath('data.phone', null);
    }
}
