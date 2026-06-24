<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key and Organization
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API Key and organization. This will be
    | used to authenticate with the OpenAI API. The org is optional.
    |
    | Why this file exists: openai-php/laravel only merges its key when config
    | is NOT cached. On a cached-config deploy (or when the key was added after
    | the cache was built) config('openai.api_key') ends up null even though
    | env('OPENAI_API_KEY') is set — which makes the client throw
    | "API Key is missing" and AiBrain falls back to a blank reply. Publishing
    | this app-level config file makes the key load deterministically.
    |
    */

    'api_key'      => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Project
    |--------------------------------------------------------------------------
    |
    | For sk-proj-* keys you may scope requests to a project. Optional.
    |
    */

    'project' => env('OPENAI_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout (seconds) for an OpenAI request. Vision/long completions can
    | take a while, so keep this generous.
    |
    */

    'request_timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 30),

];
