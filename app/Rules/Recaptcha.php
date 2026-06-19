<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Recaptcha implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $response = Http::timeout(10)->asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => env('RECAPTCHA_SECRET_KEY'),
                'response' => $value,
                'remoteip' => request()->ip(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Recaptcha verification request failed.', [
                'error' => $exception->getMessage(),
            ]);
            $fail('The reCAPTCHA verification failed. Please try again.');

            return;
        }

        if (! $response->json('success')) {
            $fail('The reCAPTCHA verification failed. Please try again.');
        }
    }
}