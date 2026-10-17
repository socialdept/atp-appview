<?php

namespace SocialDept\AtpAppview\Ingest\Events;

enum RecordAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
