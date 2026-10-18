<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Views;

use SocialDept\AtpAppview\Images\Blobs;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Views\Parts\Count;
use SocialDept\AtpAppview\Views\View;

class ShelfView extends View
{
    public function collection(): string
    {
        return 'com.example.shelf';
    }

    public function parts(): array
    {
        return ['thingCount' => new Count('com.example.thing', 'shelf')];
    }

    public function summary(Record $record): array
    {
        return [
            'uri' => $record->uri,
            'name' => $record->record['name'] ?? null,
            'icon' => Blobs::url($record->record['icon'] ?? null, $record->did, 'icon'),
        ];
    }
}
