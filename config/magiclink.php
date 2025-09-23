<?php

return [
    // waktu berlaku token dalam menit
    'ttl_minutes' => env('MAGICLINK_TTL', 30),

    // (opsional) untuk redirect FE nanti
    'frontend_url' => env('MAGICLINK_FRONTEND_URL', null),
];
