<?php

/*
|--------------------------------------------------------------------------
| Blocklist
|--------------------------------------------------------------------------
|
| Rules that keep records out of the index, by repo or by PDS host, are
| managed with appview:block and appview:unblock and live in atp_blocks.
| Host rules need each repo's PDS, resolved from its DID document and
| kept on atp_identities for pds_ttl seconds.
|
*/

return [
    'pds_ttl' => (int) env('ATP_APPVIEW_PDS_TTL', 86400),
];
