<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Models\Block;

class BlocksCommand extends Command
{
    protected $signature = 'appview:blocks';

    protected $description = 'List the block rules and how many records each has dropped';

    public function handle(): int
    {
        $blocks = Block::query()->orderBy('kind')->orderBy('subject')->orderBy('collection')->get();

        if ($blocks->isEmpty()) {
            $this->components->info('No block rules.');

            return self::SUCCESS;
        }

        $this->table(
            ['Kind', 'Subject', 'Collection', 'Dropped', 'Last dropped', 'Note'],
            $blocks->map(fn (Block $block) => [
                $block->kind->value,
                $block->subject,
                $block->collection,
                number_format($block->blocked_count),
                $block->last_blocked_at?->diffForHumans() ?? 'never',
                $block->note,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
