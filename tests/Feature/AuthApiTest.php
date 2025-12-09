<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{

    use RefreshDatabase;

    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    ## Test Setup ##

    protected function setUp(): void
    {
        parent::setUp();
        // Create a user for testing authenticated routes
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);
    }

    // Helper function to get a JWT token
    protected function getJwtToken(): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);
        return $response->json('token');
    }

    ## User Authentication (JWT) Tests ##


    public function test_user_can_sign_up()
    {
        $response = $this->postJson('/api/auth/signup', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'securepass',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['token', 'user']);

        $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);
    }

    public function test_user_can_login_and_get_jwt_token()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token']);
    }

    public function test_user_cannot_login_with_invalid_credentials()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['error' => 'Unauthorized']);
    }

    public function test_protected_route_is_accessible_with_jwt()
    {
        $token = $this->getJwtToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user-resource');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Accessed with JWT (User)']);
    }

    public function test_user_can_logout()
    {
        $token = $this->getJwtToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Successfully logged out']);

        // Ensure the token is blacklisted by trying to access a protected route again
        $reaccess = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user-resource');

        $reaccess->assertStatus(401);
    }

    ## API Key System (Sanctum) Tests ##

    public function test_authenticated_user_can_create_an_api_key()
    {
        $token = $this->getJwtToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/keys/create', [
                'name' => 'Test Service Key',
                'expires_in_days' => 7,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['plain_text_token', 'expires_at']);

        // Assert that the token was created in the database
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_service_resource_is_accessible_with_api_key()
    {
        $user = User::where('email', 'test@example.com')->first();

        // Use Sanctum's createToken method (as done in the KeyController)
        $apiKey = $user->createToken('Test Service Key', ['service:access'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $apiKey)
            ->getJson('/api/service-resource');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Accessed with API Key (Service)']);
    }

    public function test_api_key_can_be_revoked()
    {
        $user = User::where('email', 'test@example.com')->first();

        // 1. Create the API Key
        $token = $user->createToken('Key to Revoke');
        $tokenId = $token->accessToken->id;

        // 2. Get the user's JWT for authentication to the /keys route
        $jwtToken = $this->getJwtToken();

        // 3. Revoke the key using the authenticated user's JWT
        $response = $this->withHeader('Authorization', 'Bearer ' . $jwtToken)
            ->deleteJson("/api/keys/{$tokenId}/revoke");

        $response->assertStatus(200)
            ->assertJson(['message' => 'API Key revoked successfully']);

        // 4. Verify revocation (token should no longer be in the database)
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        // 5. Attempt to use the revoked key (should fail)
        $reaccess = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->getJson('/api/service-resource');

        $reaccess->assertStatus(401);
    }
}
