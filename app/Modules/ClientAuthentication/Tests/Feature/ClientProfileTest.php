<?php

namespace App\Modules\ClientAuthentication\Tests\Feature;

use App\Models\User;
use App\Modules\ClientAuthentication\Services\ClientSessionService;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientProfileTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'password' => Hash::make('password123'),
            'user_type' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function tokenFor(User $user): string
    {
        return app(ClientSessionService::class)->issue($user)['token'];
    }

    /**
     * A stand-in upload with a real image MIME type. `fake()->image()` needs
     * the GD extension, which the test runner does not always have.
     */
    private function fakeImage(string $name, int $kilobytes = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'image/jpeg');
    }

    public function test_the_signed_in_customer_can_update_their_profile(): void
    {
        $user = $this->customer();

        $this->withToken($this->tokenFor($user))
            ->patchJson('/api/client/v1/auth/me', [
                'first_name' => 'Juanito',
                'last_name' => 'Santos',
                'phone' => '09171234567',
                'address' => '123 Mabini St, Manila',
            ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Juanito')
            ->assertJsonPath('data.last_name', 'Santos')
            ->assertJsonPath('data.phone', '09171234567')
            ->assertJsonPath('data.address', '123 Mabini St, Manila')
            // The denormalised display name must follow the edited parts.
            ->assertJsonPath('data.name', 'Juanito Santos');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Juanito',
            'name' => 'Juanito Santos',
        ]);
    }

    public function test_a_partial_update_leaves_untouched_fields_alone(): void
    {
        $user = $this->customer(['phone' => '09170000000', 'address' => 'Old address']);

        $this->withToken($this->tokenFor($user))
            ->patchJson('/api/client/v1/auth/me', ['first_name' => 'Juanito'])
            ->assertOk()
            ->assertJsonPath('data.phone', '09170000000')
            ->assertJsonPath('data.address', 'Old address')
            ->assertJsonPath('data.name', 'Juanito Dela Cruz');
    }

    public function test_the_profile_update_validates_its_input(): void
    {
        $user = $this->customer();

        $this->withToken($this->tokenFor($user))
            ->patchJson('/api/client/v1/auth/me', [
                'first_name' => '',
                'phone' => str_repeat('9', 31),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'phone']);
    }

    public function test_the_profile_update_cannot_change_the_email_or_the_role(): void
    {
        $user = $this->customer();

        $this->withToken($this->tokenFor($user))
            ->patchJson('/api/client/v1/auth/me', [
                'first_name' => 'Juanito',
                'email' => 'someone.else@example.com',
                'role_id' => 1,
                'status' => 'suspended',
            ])
            ->assertOk();

        $user->refresh();
        $this->assertSame('juan@example.com', $user->email);
        $this->assertSame('customer', $user->user_type);
        $this->assertSame('active', $user->status);
    }

    public function test_the_profile_endpoints_reject_anonymous_callers(): void
    {
        $this->patchJson('/api/client/v1/auth/me', ['first_name' => 'Nope'])->assertStatus(401);
        $this->postJson('/api/client/v1/auth/me/photo')->assertStatus(401);
        $this->deleteJson('/api/client/v1/auth/me/photo')->assertStatus(401);
    }

    public function test_a_photo_can_be_uploaded_replaced_and_removed(): void
    {
        Storage::fake('public');
        $user = $this->customer();
        $token = $this->tokenFor($user);

        $first = $this->withToken($token)
            ->postJson('/api/client/v1/auth/me/photo', [
                'photo' => $this->fakeImage('me.jpg'),
            ])
            ->assertOk()
            ->json('data.profile_picture');

        $this->assertNotNull($first, 'The response must carry the photo URL.');
        $firstPath = $user->fresh()->profile_photo_path;
        Storage::disk('public')->assertExists($firstPath);

        // Replacing must not leave the previous file behind.
        $this->withToken($token)
            ->postJson('/api/client/v1/auth/me/photo', [
                'photo' => $this->fakeImage('new.jpg'),
            ])
            ->assertOk();

        $secondPath = $user->fresh()->profile_photo_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $this->withToken($token)
            ->deleteJson('/api/client/v1/auth/me/photo')
            ->assertOk()
            ->assertJsonPath('data.profile_picture', null);

        $this->assertNull($user->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing($secondPath);
    }

    public function test_removing_a_photo_that_was_never_set_is_a_no_op(): void
    {
        Storage::fake('public');
        $user = $this->customer();

        $this->withToken($this->tokenFor($user))
            ->deleteJson('/api/client/v1/auth/me/photo')
            ->assertOk()
            ->assertJsonPath('data.profile_picture', null);
    }

    public function test_the_photo_upload_rejects_non_images_and_oversized_files(): void
    {
        Storage::fake('public');
        $user = $this->customer();
        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->postJson('/api/client/v1/auth/me/photo', [
                'photo' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);

        $this->withToken($token)
            ->postJson('/api/client/v1/auth/me/photo', [
                'photo' => $this->fakeImage('huge.jpg', 6000),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);

        $this->assertNull($user->fresh()->profile_photo_path);
    }

    public function test_a_provider_can_also_maintain_their_profile(): void
    {
        $provider = $this->customer([
            'email' => 'provider@example.com',
            'user_type' => 'provider',
        ]);
        ProviderProfile::create([
            'user_id' => $provider->id,
            'specialization' => 'Plumbing',
            'verification_status' => 'pending',
        ]);

        $this->withToken($this->tokenFor($provider))
            ->patchJson('/api/client/v1/auth/me', ['address' => 'Quezon City'])
            ->assertOk()
            ->assertJsonPath('data.address', 'Quezon City')
            ->assertJsonPath('data.user_type', 'provider');
    }

    public function test_a_suspended_account_cannot_edit_its_profile(): void
    {
        $user = $this->customer();
        $token = $this->tokenFor($user);
        $user->update(['status' => 'suspended']);

        $this->withToken($token)
            ->patchJson('/api/client/v1/auth/me', ['first_name' => 'Nope'])
            ->assertStatus(403);
    }
}
