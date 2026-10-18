<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpSchema\Data\LexiconDocument;
use SocialDept\AtpSchema\Parser\SchemaLoader;
use SocialDept\AtpSchema\Validation\LexiconValidator;
use Throwable;

/**
 * Checks records against their collection's lexicon before they are indexed,
 * as atp-appview.index.validate says:
 *
 * - off: nothing is checked.
 * - log: invalid records are indexed, and counted and logged.
 * - drop: invalid records are left out, and counted and logged. An earlier
 *   valid version of the record stays indexed.
 *
 * Only collections with a lexicon atp-schema can load are checked. Fields a
 * lexicon does not mention are allowed, since a newer version of a lexicon
 * may add optional fields this app does not know yet. Refs to other
 * definitions are not followed.
 */
class RecordValidation
{
    public const OFF = 'off';

    public const LOG = 'log';

    public const DROP = 'drop';

    /**
     * Lexicons by collection, false for a collection without one.
     *
     * @var array<string, LexiconDocument|false>
     */
    protected array $documents = [];

    public function __construct(
        protected SchemaLoader $loader,
        protected LexiconValidator $validator,
        protected Cache $cache,
    ) {
    }

    public static function mode(): string
    {
        $mode = config('atp-appview.index.validate', self::OFF);

        return in_array($mode, [self::LOG, self::DROP], true) ? $mode : self::OFF;
    }

    /**
     * The events to index: all of them, or without the invalid records in
     * drop mode.
     *
     * @param  array<int, RecordEvent>  $events
     * @return array<int, RecordEvent>
     */
    public function filter(array $events): array
    {
        if (($mode = self::mode()) === self::OFF) {
            return $events;
        }

        $kept = [];
        $invalid = [];

        foreach ($events as $event) {
            $problem = $this->problem($event);

            if ($problem === null) {
                $kept[] = $event;

                continue;
            }

            $invalid[$event->collection][] = [$event->uri(), $problem];

            if ($mode === self::LOG) {
                $kept[] = $event;
            }
        }

        $this->report($invalid, $mode);

        return $kept;
    }

    /**
     * How many invalid records a collection has seen, since counting began.
     */
    public function count(string $collection): int
    {
        return (int) $this->cache->get(self::key($collection), 0);
    }

    /**
     * What is wrong with an event's record, or null when nothing is, it has
     * no record, or its collection has no lexicon.
     */
    protected function problem(RecordEvent $event): ?string
    {
        if ($event->action === RecordAction::Delete || $event->record === null) {
            return null;
        }

        $document = $this->document($event->collection);

        if ($document === null) {
            return null;
        }

        try {
            $errors = $this->validator->validateWithErrors($event->record, $document);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return $errors === [] ? null : implode('; ', array_merge(...array_values($errors)));
    }

    protected function document(string $collection): ?LexiconDocument
    {
        if (! array_key_exists($collection, $this->documents)) {
            try {
                $document = $this->loader->find($collection);
                $this->documents[$collection] = $document !== null && $document->isRecord() ? $document : false;
            } catch (Throwable) {
                $this->documents[$collection] = false;
            }
        }

        return $this->documents[$collection] ?: null;
    }

    /**
     * One log line per collection per batch, with the first problem as an
     * example, so a backfill of bad records cannot flood the log.
     *
     * @param  array<string, array<int, array{0: string, 1: string}>>  $invalid
     */
    protected function report(array $invalid, string $mode): void
    {
        foreach ($invalid as $collection => $records) {
            $this->cache->increment(self::key($collection), count($records));

            Log::warning(sprintf('[atp-appview] %d invalid %s records %s', count($records), $collection, $mode === self::DROP ? 'left out of the index' : 'indexed anyway'), [
                'example' => $records[0][0],
                'problem' => $records[0][1],
            ]);
        }
    }

    protected static function key(string $collection): string
    {
        return "atp-appview:invalid:{$collection}";
    }
}
