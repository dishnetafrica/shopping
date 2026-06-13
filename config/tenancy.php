<?php
return [
    // Root domain that tenant subdomains hang off (acme.<root>).
    'root_domain' => env('APP_TENANT_ROOT_DOMAIN', 'localhost'),
    // Column used for row-level scoping on every tenant-owned table.
    'column' => 'tenant_id',
];
