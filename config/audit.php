<?php

return [
    // Set AUDIT_INTEGRITY_KEY in production so audit fingerprints survive APP_KEY rotation.
    'integrity_key' => env('AUDIT_INTEGRITY_KEY', env('APP_KEY')),
];
