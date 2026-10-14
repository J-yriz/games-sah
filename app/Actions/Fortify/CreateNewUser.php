<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\ChipTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'username' => $input['username'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $wallet = Wallet::create([
                'user_id' => $user->id,
                'balance' => config('chips.initial_grant'),
            ]);

            // Deterministic key per user: a retried registration for the
            // same account can never mint the grant twice.
            ChipTransaction::create([
                'from_wallet_id' => null,
                'to_wallet_id' => $wallet->id,
                'type' => ChipTransaction::TYPE_INITIAL_GRANT,
                'amount' => config('chips.initial_grant'),
                'idempotency_key' => 'initial-grant:'.$user->id,
                'metadata' => ['user_id' => $user->id, 'grant' => 'initial'],
            ]);

            return $user;
        });
    }
}
