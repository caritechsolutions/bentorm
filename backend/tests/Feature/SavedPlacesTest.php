<?php

namespace Tests\Feature;

use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedPlacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_work_and_others(): void
    {
        $rider = $this->makeUser();
        $this->actingAs($rider, 'sanctum');
        $this->postJson('/api/v1/me/places', ['kind' => 'home', 'lat' => 13.08, 'lng' => -59.58, 'address' => 'Pegwell'])->assertCreated()->assertJsonPath('label', 'Home');
        $this->postJson('/api/v1/me/places', ['kind' => 'home', 'lat' => 13.09, 'lng' => -59.59, 'address' => 'Moved'])->assertCreated();
        $this->postJson('/api/v1/me/places', ['kind' => 'other', 'lat' => 13.1, 'lng' => -59.6])->assertUnprocessable()->assertJsonValidationErrors('label');
        $gym = $this->postJson('/api/v1/me/places', ['kind' => 'other', 'label' => 'Gym', 'lat' => 13.1, 'lng' => -419.6])->assertCreated()->json();
        $this->assertEqualsWithDelta(-59.6, $gym['lng'], 0.0001, 'longitude wrapped');
        $this->postJson('/api/v1/me/places', ['kind' => 'work', 'lat' => 13.1, 'lng' => -59.61])->assertCreated();

        $list = $this->getJson('/api/v1/me/places')->assertOk()->json();
        $this->assertSame(['Home', 'Work', 'Gym'], array_column($list, 'label'));
        $this->assertSame('Moved', $list[0]['address'], 'saving Home again replaces it');

        $this->patchJson("/api/v1/me/places/{$gym['id']}", ['label' => 'Gym (Warrens)'])->assertOk()->assertJsonPath('label', 'Gym (Warrens)');
        $this->actingAs($this->makeUser(), 'sanctum')->deleteJson("/api/v1/me/places/{$gym['id']}")->assertNotFound();
        $this->actingAs($rider, 'sanctum')->deleteJson("/api/v1/me/places/{$gym['id']}")->assertOk()->assertJsonCount(2);

        Features::put('saved_places', false);
        $this->postJson('/api/v1/me/places', ['kind' => 'other', 'label' => 'X', 'lat' => 13.1, 'lng' => -59.6])->assertForbidden();
    }
}
