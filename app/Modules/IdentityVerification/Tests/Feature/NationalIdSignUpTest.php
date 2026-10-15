<?php

namespace App\Modules\IdentityVerification\Tests\Feature;

use App\Models\User;
use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Modules\IdentityVerification\Services\NationalIdHasher;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * One National ID = one provider account + one customer account: sign-up
 * refuses a second account of the same type before it is created.
 */
class NationalIdSignUpTest extends TestCase
{
    use RefreshDatabase;

    private const ID = '1234567890123456';

    private const MESSAGE = 'A provider account already uses this National ID. One National ID can verify only one provider account and one customer account.';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_a_second_provider_account_on_a_verified_providers_id_is_refused_at_sign_up(): void
    {
        $this->holder('provider', IdentityVerification::VERIFIED);

        $this->postJson('/api/client/v1/auth/register-provider', $this->provider('second@example.com', '1234-5678-9012-3456'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.id_number.0', self::MESSAGE);

        $this->assertDatabaseMissing('pending_registrations', ['email' => 'second@example.com']);
    }

    public function test_a_pending_submission_also_holds_the_id(): void
    {
        $this->holder('provider', IdentityVerification::PENDING);

        $this->postJson('/api/client/v1/auth/register-provider', $this->provider('second@example.com', self::ID))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_number');
    }

    public function test_the_same_person_may_still_open_one_customer_account(): void
    {
        $this->holder('provider', IdentityVerification::VERIFIED);

        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'birthday' => '1995-04-02',
            'email' => 'juan.customer@example.com',
            'id_number' => self::ID,
        ])->assertStatus(202);

        $this->holder('customer', IdentityVerification::VERIFIED);

        $this->postJson('/api/client/v1/auth/register', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'birthday' => '1995-04-02',
            'email' => 'juan.again@example.com',
            'id_number' => self::ID,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.id_number.0', 'A customer account already uses this National ID. One National ID can verify only one provider account and one customer account.');
    }

    public function test_a_rejected_submission_or_an_unknown_id_does_not_block_sign_up(): void
    {
        $this->holder('provider', IdentityVerification::REJECTED);

        $this->postJson('/api/client/v1/auth/register-provider', $this->provider('fresh@example.com', self::ID))
            ->assertStatus(202);
    }

    public function test_older_apps_that_send_no_id_number_still_sign_up_and_the_number_is_never_stored(): void
    {
        $this->postJson('/api/client/v1/auth/register-provider', $this->provider('older@example.com'))
            ->assertStatus(202);

        $this->postJson('/api/client/v1/auth/register-provider', $this->provider('newer@example.com', self::ID))
            ->assertStatus(202);

        $stored = json_encode(DB::table('pending_registrations')->get());
        $this->assertStringNotContainsString(self::ID, $stored);
    }

    public function test_a_malformed_number_is_a_validation_error(): void
    {
        $this->postJson('/api/client/v1/auth/register-provider', $this->provider('typo@example.com', '1234'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_number');
    }

    /** An existing account of [$type] whose submission of the ID is in [$status]. */
    private function holder(string $type, string $status): void
    {
        $user = User::factory()->create(['user_type' => $type, 'status' => 'active']);
        if ($type === 'provider') {
            ProviderProfile::create(['user_id' => $user->id, 'business_name' => 'First Plumbing', 'verification_status' => 'verified']);
        }

        IdentityVerification::query()->create([
            'user_id' => $user->id,
            'account_type' => $type,
            'status' => $status,
            'id_number_hash' => app(NationalIdHasher::class)->hash(self::ID),
            'id_number_last4' => '3456',
        ]);
    }

    /** @return array<string, mixed> */
    private function provider(string $email, ?string $idNumber = null): array
    {
        return array_filter([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'birthday' => '1995-04-02',
            'email' => $email,
            'specialization' => 'Plumbing',
            'id_number' => $idNumber,
        ]);
    }
}
