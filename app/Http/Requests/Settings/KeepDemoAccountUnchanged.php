<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Validation\Validator;

/**
 * Refuses any change to the demo account, whose login is public in the README,
 * so one visitor can't lock out the next. The refusal shows under $field.
 */
final readonly class KeepDemoAccountUnchanged
{
    public function __construct(
        private ?User $user,
        private string $field,
    ) {}

    public function __invoke(Validator $validator): void
    {
        if ($this->user?->isDemoAccount()) {
            $validator->errors()->add($this->field, __("This is the shared demo account, so it can't be changed or deleted."));
        }
    }
}
