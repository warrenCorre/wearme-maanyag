<?php

return [
    'mode' => env('ASSISTANT_MODE', 'legacy'),
    'send_store_results_to_gemini' => env('ASSISTANT_SEND_STORE_RESULTS_TO_GEMINI', false),
    'paid_tier_reviewed' => env('ASSISTANT_PAID_TIER_REVIEWED', false),
];
