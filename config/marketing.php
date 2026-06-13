<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CloudBSS marketing / sales contact points
    |--------------------------------------------------------------------------
    | These fill the public landing page (resources/marketing/index.html).
    | The page ships with the placeholder 256700000000; set these env vars
    | once the real CloudBSS marketing WhatsApp line is connected and every
    | wa.me / tel: link on the page updates automatically — no HTML editing.
    |
    | MARKETING_WA_NUMBER : digits only, full international, no "+" (e.g. 256779123456)
    | MARKETING_PHONE     : human display for the "Call us" link (e.g. +256 779 123456)
    | MARKETING_EMAIL     : contact email shown in the footer
    */

    'whatsapp' => env('MARKETING_WA_NUMBER', '256700000000'),
    'phone'    => env('MARKETING_PHONE', '+256700000000'),
    'email'    => env('MARKETING_EMAIL', 'hello@mycloudbss.com'),

];
