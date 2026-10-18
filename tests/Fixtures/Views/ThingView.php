<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Views;

use SocialDept\AtpAppview\Views\Parts\Author;
use SocialDept\AtpAppview\Views\Parts\Count;
use SocialDept\AtpAppview\Views\Parts\Embed;
use SocialDept\AtpAppview\Views\Parts\ViewerRef;
use SocialDept\AtpAppview\Views\View;

class ThingView extends View
{
    public function collection(): string
    {
        return 'com.example.thing';
    }

    public function parts(): array
    {
        return [
            'author' => new Author(),
            'shelf' => new Embed('shelf', ShelfView::class),
            'likeCount' => new Count('com.example.like', 'subject.uri'),
            'repostCount' => new Count('com.example.repost', 'subject.uri'),
            'viewer' => [
                'like' => new ViewerRef('com.example.like', 'subject.uri'),
                'repost' => new ViewerRef('com.example.repost', 'subject.uri'),
            ],
        ];
    }
}
