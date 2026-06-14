<?php

return [
    /*
     * Allow users to have the AI write a story entirely from a prompt.
     * Set FEATURE_AI_WRITES=true in .env to enable.
     * Default: false (disabled until ready to monetize/test).
     */
    'ai_writes' => env('FEATURE_AI_WRITES', false),
];
