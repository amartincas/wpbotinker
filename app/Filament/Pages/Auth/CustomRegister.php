<?php

namespace App\Filament\Pages\Auth;

use App\Models\Store;
use App\Models\User;
use Filament\Auth\Pages\Register;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CustomRegister extends Register
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getStoreNameFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    protected function getStoreNameFormComponent(): Component
    {
        return TextInput::make('store_name')
            ->label(__('Nombre de la Tienda'))
            ->hint(__('El nombre de tu tienda de WhatsApp'))
            ->required()
            ->maxLength(255);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        return $this->wrapInDatabaseTransaction(function () use ($data) {
            // 1. Create the Store with defaults
            $store = Store::create([
                'name' => $data['store_name'],
                'personality_type' => 'asesor',
                'system_prompt' => 'You are a helpful assistant.',
                'ai_provider' => 'openai',
                'ai_model' => 'gpt-4o',
                'wa_access_token' => null,
                'wa_phone_number_id' => null,
                'wa_business_account_id' => null,
                'ai_api_key' => null,
                'wa_verify_token' => Str::random(32),
            ]);

            // 2. Remove store_name from user data and add store_id
            unset($data['store_name']);
            $data['store_id'] = $store->id;
            $data['is_super_admin'] = false;

            // 3. Create the User and assign to the store
            return User::create($data);
        });
    }
}

