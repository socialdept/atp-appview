<?php

namespace SocialDept\AtpAppview\Tests\Unit\Labels;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpAppview\Labels\Effect;
use SocialDept\AtpAppview\Labels\Target;

class TargetTest extends TestCase
{
    public function test_a_label_is_about_an_account_or_content(): void
    {
        $this->assertSame(Target::ACCOUNT, Target::of('did:plc:alice'));
        $this->assertSame(Target::ACCOUNT, Target::of('at://did:plc:alice/app.bsky.actor.profile/self'));
        $this->assertSame(Target::CONTENT, Target::of('at://did:plc:alice/app.bsky.feed.post/3k'));
        $this->assertSame(Target::CONTENT, Target::of('at://did:plc:alice/com.example.thing/self'));
    }

    public function test_the_subject_is_the_account(): void
    {
        $this->assertSame('did:plc:alice', Target::subject('did:plc:alice'));
        $this->assertSame('did:plc:alice', Target::subject('at://did:plc:alice/app.bsky.feed.post/3k'));
    }

    public function test_review_and_ban_are_for_accounts_only_and_the_strongest_effect_wins(): void
    {
        $this->assertFalse(Effect::Ban->appliesTo(Target::CONTENT));
        $this->assertTrue(Effect::Ban->appliesTo(Target::ACCOUNT));
        $this->assertTrue(Effect::Hide->appliesTo(Target::CONTENT));
        $this->assertGreaterThan(Effect::Warn->weight(), Effect::Hide->weight());
        $this->assertTrue(Effect::Ban->hides());
        $this->assertFalse(Effect::Review->hides());
    }
}
