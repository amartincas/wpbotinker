<?php

/*
 * Authentication is provided by the Filament panel (path '', login and
 * public registration) — not by Fortify, which this app does not install.
 */

use App\Filament\Pages\Auth\CustomRegister;
use App\Models\Store;
use App\Models\User;
use Livewire\Livewire;

it('redirects guests to the panel login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('renders the login page', function () {
    $this->get('/login')->assertOk();
});

it('shows the dashboard to an authenticated store operator', function () {
    $operator = User::factory()->create(['store_id' => makeStore()->id]);

    $this->actingAs($operator)->get('/')->assertOk();
});

it('keeps user management for super admins only', function () {
    $operator = User::factory()->create(['store_id' => makeStore()->id, 'is_super_admin' => false]);
    $admin = User::factory()->create(['store_id' => null, 'is_super_admin' => true]);

    $this->actingAs($operator)->get('/users')->assertForbidden();
    $this->actingAs($admin)->get('/users')->assertOk();
});

// Current behavior, on purpose: anyone can sign up and gets a new, empty
// store of their own. Whether BoTinker keeps public sign-up is an open
// business decision — see the phase 0 report.
it('lets anyone register, creating their own store and a non-admin user', function () {
    $this->get('/register')->assertOk();

    Livewire::test(CustomRegister::class)
        ->fillForm([
            'name' => 'Nueva Operadora',
            'email' => 'nueva@example.com',
            'store_name' => 'Tienda Nueva',
            'password' => 'password-segura-123',
            'passwordConfirmation' => 'password-segura-123',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'nueva@example.com')->sole();
    expect($user->is_super_admin)->toBeFalse()
        ->and($user->store_id)->toBe(Store::where('name', 'Tienda Nueva')->sole()->id);
});
