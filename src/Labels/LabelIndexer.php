<?php

namespace SocialDept\AtpAppview\Labels;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Events\LabelerEvent;
use SocialDept\AtpAppview\Ingest\Events\LabelEvent;

/**
 * Writes the labels and labeler declarations Tack delivers.
 *
 * - The newest label per labeler, uri and value is kept, by cts, so a label
 *   delivered twice or out of order changes nothing.
 * - A withdrawal (neg) removes a label at least as old as itself, so one
 *   that arrives late cannot remove a label applied again since.
 * - A labeler removed from Tack takes its labels with it.
 *
 * Then what the labels do is applied to the accounts they are about.
 */
class LabelIndexer implements BatchHandler
{
    public function handle(Batch $batch): void
    {
        $labels = $batch->labels();
        $labelers = $batch->labelers();

        if ($labels === [] && $labelers === []) {
            return;
        }

        $connection = DB::connection(config('atp-appview.index.connection'));
        $subjects = [];

        foreach ($labelers as $labeler) {
            $subjects = [...$subjects, ...$this->labeler($connection, $labeler)];
        }

        foreach ($this->newest($labels) as $label) {
            if ($this->label($connection, $label)) {
                $subjects[] = $label->did;
            }
        }

        if ($subjects !== []) {
            app(Visibility::class)->refresh(array_values(array_unique($subjects)));
        }
    }

    /**
     * The newest event for each label in the batch.
     *
     * @param  array<int, LabelEvent>  $labels
     * @return array<int, LabelEvent>
     */
    protected function newest(array $labels): array
    {
        $newest = [];

        foreach ($labels as $label) {
            $key = "{$label->src}\0{$label->uri}\0{$label->val}";

            if (! isset($newest[$key]) || $label->cts >= $newest[$key]->cts) {
                $newest[$key] = $label;
            }
        }

        return array_values($newest);
    }

    /**
     * @return bool Whether the labels in force changed
     */
    protected function label(Connection $connection, LabelEvent $label): bool
    {
        $table = $connection->table('atp_labels');
        $key = fn () => $connection->table('atp_labels')->where('src', $label->src)->where('uri', $label->uri)->where('val', $label->val);

        if ($label->neg) {
            return $key()->where('cts', '<=', $label->cts)->delete() > 0;
        }

        $stored = $key()->value('cts');

        if ($stored !== null && $stored >= $label->cts) {
            return false;
        }

        $row = [
            'subject' => $label->did,
            'cid' => $label->cid,
            'cts' => $label->cts,
            'exp' => self::time($label->exp),
            'verified' => $label->verified,
            'self' => false,
        ];

        $stored === null
            ? $table->insert(['src' => $label->src, 'uri' => $label->uri, 'val' => $label->val, ...$row])
            : $key()->update($row);

        return true;
    }

    /**
     * @return array<int, string> Accounts whose labels changed
     */
    protected function labeler(Connection $connection, LabelerEvent $labeler): array
    {
        $now = Carbon::now('UTC');

        if ($labeler->removed) {
            $subjects = $connection->table('atp_labels')->where('src', $labeler->did)->distinct()->pluck('subject')->all();
            $connection->table('atp_labels')->where('src', $labeler->did)->delete();
            $connection->table('atp_labelers')->where('did', $labeler->did)->delete();

            return $subjects;
        }

        $connection->table('atp_labelers')->upsert([[
            'did' => $labeler->did,
            'endpoint' => $labeler->endpoint,
            'declaration' => $labeler->declaration === null ? null : json_encode($labeler->declaration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['did'], ['endpoint', 'declaration', 'updated_at']);

        // A new declaration can change what every label of this labeler does.
        app(Policies::class)->forget();

        return $connection->table('atp_labels')->where('src', $labeler->did)->distinct()->pluck('subject')->all();
    }

    protected static function time(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
