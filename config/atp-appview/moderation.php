<?php

/*
|--------------------------------------------------------------------------
| Moderation
|--------------------------------------------------------------------------
|
| What happens to accounts an operator, or a label, acts on. A banned
| account's records are hidden at once and pruned this many days after the
| ban, unless the pruning is cancelled. Null keeps them for as long as the
| ban lasts. Schedule appview:moderation:prune to prune them.
|
*/

return [
    'prune_banned_after_days' => env('ATP_APPVIEW_PRUNE_BANNED_AFTER_DAYS', 365),
];
