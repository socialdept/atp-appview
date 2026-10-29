<?php

namespace SocialDept\AtpAppview\Tests\Unit\Ingest;

use SocialDept\AtpAppview\Ingest\Collections;
use SocialDept\AtpAppview\Tests\TestCase;

class CollectionsTest extends TestCase
{
    public function test_companions_are_ingested_but_not_followed_and_a_followed_collection_wins(): void
    {
        config([
            'atp-appview.ingest.collections' => ['app.example.post', 'app.example.post'],
            'atp-appview.ingest.companions' => ['app.example.profile', 'app.example.post', ''],
        ]);

        $this->assertSame(['app.example.post'], Collections::followed());
        $this->assertSame(['app.example.profile'], Collections::companions());
        $this->assertSame(['app.example.post', 'app.example.profile'], Collections::all());
        $this->assertTrue(Collections::isCompanion('app.example.profile'));
        $this->assertFalse(Collections::isCompanion('app.example.post'));
        $this->assertTrue(Collections::ingests('app.example.profile'));
        $this->assertFalse(Collections::ingests('app.example.other'));
    }
}
