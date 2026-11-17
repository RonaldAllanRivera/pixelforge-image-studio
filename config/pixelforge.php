<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pixelforge API
    |--------------------------------------------------------------------------
    |
    | Use one API key per environment. Each key has its own webhook URL, signing
    | secret and usage report, so the deployed app's numbers match Pixelforge's.
    |
    */

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Seconds to wait for one response. A timeout is an unknown outcome: the request is
    // retried later with the same Idempotency-Key, never treated as a failure.
    'timeout' => 15,

    /*
    |--------------------------------------------------------------------------
    | Published pricing (fallback)
    |--------------------------------------------------------------------------
    |
    | The GET /v1/pricing response as published. Estimates use it only when
    | Pixelforge's live pricing can't be fetched. A generation is always billed
    | at the rates Pixelforge fixes on the generation itself, never these.
    |
    */

    'published_pricing' => [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ],

];
