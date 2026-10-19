<?php

/*
|--------------------------------------------------------------------------
| Images
|--------------------------------------------------------------------------
|
| Where views point browsers for blobs such as avatars. `infra` resizes
| them through img.infra.coop (https://img.infra.coop/presets), a free
| image CDN for atproto that asks for a small credit linking to
| infra.coop, or a private deployment at `url`. `pds` links the original
| blob on the account's PDS. Each use maps to an img.infra.coop preset.
|
*/

return [
    'driver' => env('ATP_APPVIEW_IMAGES', 'infra'),
    'url' => env('ATP_APPVIEW_IMAGES_URL', 'https://img.infra.coop'),
    'presets' => [
        // An author embedded in another view.
        'author' => 'avatar-sm',
        // The avatar on a profile view.
        'avatar' => 'avatar',
        'banner' => 'banner',
        // A small square image embedded in another view, such as an icon.
        'icon' => 'avatar-sm',
    ],
];
