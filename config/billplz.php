<?php

declare(strict_types=1);

return [

    'enabled' => env('BILLPLZ_ENABLED', false),

    'sandbox' => env('BILLPLZ_SANDBOX', true),

    'api_key' => env('BILLPLZ_API_KEY'),

    'x_signature_key' => env('BILLPLZ_X_SIGNATURE_KEY'),

    'collection_id' => env('BILLPLZ_COLLECTION_ID'),

];
