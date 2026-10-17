<?php

namespace SocialDept\AtpAppview\Tests\Unit\Blocklist;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialDept\AtpAppview\Blocklist\BlockKind;
use SocialDept\AtpAppview\Blocklist\Patterns;

class PatternsTest extends TestCase
{
    public function test_a_did_is_kept_as_given(): void
    {
        $this->assertSame([BlockKind::Did, 'did:plc:abc123'], Patterns::subject(' did:plc:abc123 '));
        $this->assertSame([BlockKind::Did, 'did:web:spam.example'], Patterns::subject('did:web:spam.example'));
    }

    public function test_a_host_loses_its_scheme_path_and_case(): void
    {
        $this->assertSame([BlockKind::Host, 'pds.spam.example'], Patterns::subject('https://PDS.Spam.Example/'));
        $this->assertSame([BlockKind::Host, '*.spam.example'], Patterns::subject('*.spam.example'));
        $this->assertSame([BlockKind::Host, 'pds.example:2583'], Patterns::subject('http://pds.example:2583/xrpc'));
    }

    #[DataProvider('invalidSubjects')]
    public function test_a_subject_that_is_neither_is_refused(string $subject): void
    {
        $this->expectException(InvalidArgumentException::class);

        Patterns::subject($subject);
    }

    public static function invalidSubjects(): array
    {
        return [
            'empty' => [''],
            'bare wildcard' => ['*'],
            'wildcard dots' => ['*.*'],
            'broken did' => ['did:plc:'],
            'spaces' => ['spam example'],
        ];
    }

    public function test_collection_patterns_are_every_an_nsid_or_a_prefix(): void
    {
        $this->assertSame('*', Patterns::collectionPattern(null));
        $this->assertSame('*', Patterns::collectionPattern(''));
        $this->assertSame('site.standard.document', Patterns::collectionPattern('site.standard.document'));
        $this->assertSame('site.standard.graph.*', Patterns::collectionPattern('site.standard.graph.*'));

        $this->expectException(InvalidArgumentException::class);
        Patterns::collectionPattern('site.standard.*.document');
    }

    public function test_a_prefix_covers_its_children_only(): void
    {
        $this->assertTrue(Patterns::collection('*', 'app.example.post'));
        $this->assertTrue(Patterns::collection('app.example.post', 'app.example.post'));
        $this->assertTrue(Patterns::collection('site.standard.graph.*', 'site.standard.graph.recommend'));
        $this->assertFalse(Patterns::collection('site.standard.graph.*', 'site.standard.graphic'));
        $this->assertFalse(Patterns::collection('app.example.post', 'app.example.postcard'));
    }

    public function test_a_host_wildcard_covers_subdomains_but_not_the_domain(): void
    {
        $this->assertTrue(Patterns::host('*.spam.example', 'https://a.spam.example'));
        $this->assertTrue(Patterns::host('*.spam.example', 'https://a.b.spam.example/'));
        $this->assertFalse(Patterns::host('*.spam.example', 'https://spam.example'));
        $this->assertFalse(Patterns::host('*.spam.example', 'https://notspam.example'));
        $this->assertTrue(Patterns::host('pds.example:2583', 'http://pds.example:2583'));
        $this->assertFalse(Patterns::host('pds.example', 'http://pds.example:2583'));
        $this->assertFalse(Patterns::host('pds.example', null));
    }
}
