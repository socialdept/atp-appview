<?php

namespace SocialDept\AtpAppview\Labels;

use Illuminate\Database\Connection;

/**
 * Labels authors put on their own records, in labels.values
 * (com.atproto.label.defs#selfLabels), kept in atp_labels with the author
 * as their source, so they count like any label. A self-label can only make
 * a record's own presentation stricter.
 *
 * Only records a batch actually wrote are read: one the revision guard left
 * alone keeps the self-labels of the version stored.
 */
class SelfLabels
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  As RecordIndexer wrote them
     * @param  string  $now  The indexed_at the batch wrote
     * @return array<int, string> Accounts whose self-labels changed
     */
    public function sync(Connection $connection, array $rows, string $now): array
    {
        $changed = [];

        foreach (array_chunk($rows, 500) as $chunk) {
            $stored = $connection->table('atp_records')->whereIn('uri', array_column($chunk, 'uri'))
                ->get(['uri', 'indexed_at'])
                ->keyBy('uri');

            $written = array_values(array_filter($chunk, fn (array $row) => isset($stored[$row['uri']])
                && substr((string) $stored[$row['uri']]->indexed_at, 0, 19) === $now));

            if ($written === []) {
                continue;
            }

            $changed += array_fill_keys($this->replace($connection, $written, fn () => str_replace(' ', 'T', $now).'.000Z'), true);
        }

        return array_keys($changed);
    }

    /**
     * Read the self-labels of records indexed before they were kept, or while
     * labels.self_labels was off. Safe to run again: each record's labels are
     * replaced, not added to.
     *
     * @param  \Closure(int): void|null  $progress  Called with the records read so far
     * @return array<int, string> Accounts whose self-labels changed
     */
    public function rebuild(Connection $connection, ?string $collection = null, ?\Closure $progress = null): array
    {
        $changed = [];
        $read = 0;

        $connection->table('atp_records')
            ->when($collection !== null, fn ($query) => $query->where('collection', $collection))
            ->whereNull('deleted_at')
            // A cold record keeps the self-labels its body gave.
            ->whereNull('hollowed_at')
            ->select(['uri', 'did', 'cid', 'record', 'indexed_at'])
            ->orderBy('uri')
            ->chunk(500, function ($rows) use ($connection, $progress, &$changed, &$read) {
                $rows = array_map(fn ($row) => (array) $row, $rows->all());
                $changed += array_fill_keys($connection->transaction(fn () => $this->replace(
                    $connection,
                    $rows,
                    fn (array $row) => str_replace(' ', 'T', substr((string) $row['indexed_at'], 0, 19)).'.000Z',
                )), true);
                $read += count($rows);

                if ($progress !== null) {
                    $progress($read);
                }
            });

        return array_keys($changed);
    }

    /**
     * Swap the stored self-labels of these records for the ones their bodies carry.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  \Closure(array<string, mixed>): string  $cts
     * @return array<int, string> Accounts whose self-labels changed
     */
    protected function replace(Connection $connection, array $rows, \Closure $cts): array
    {
        $changed = [];
        $removed = $connection->table('atp_labels')->where('self', true)->whereIn('uri', array_column($rows, 'uri'));
        $before = [];

        foreach ((clone $removed)->get(['uri', 'val', 'subject']) as $label) {
            $before[$label->uri][$label->val] = $label->subject;
        }

        $removed->delete();

        $labels = [];

        foreach ($rows as $row) {
            $values = self::values($row['record'] === null ? null : json_decode((string) $row['record'], true));

            $had = $before[$row['uri']] ?? [];

            if (array_diff($values, array_keys($had)) !== [] || array_diff(array_keys($had), $values) !== []) {
                $changed += array_fill_keys([$row['did'], ...array_values($had)], true);
            }

            foreach ($values as $val) {
                $labels[] = [
                    'src' => $row['did'], 'uri' => $row['uri'], 'subject' => $row['did'], 'cid' => $row['cid'],
                    'val' => $val, 'cts' => $cts($row), 'exp' => null, 'verified' => true, 'self' => true,
                ];
            }
        }

        if ($labels !== []) {
            $connection->table('atp_labels')->insertOrIgnore($labels);
        }

        return array_keys($changed);
    }

    /**
     * The self-label values of a record body.
     *
     * @param  array<string, mixed>|null  $record
     * @return array<int, string>
     */
    public static function values(?array $record): array
    {
        $labels = $record['labels'] ?? null;

        if (! is_array($labels) || ($labels['$type'] ?? null) !== 'com.atproto.label.defs#selfLabels' || ! is_array($labels['values'] ?? null)) {
            return [];
        }

        $values = [];

        foreach ($labels['values'] as $value) {
            if (is_array($value) && is_string($value['val'] ?? null) && $value['val'] !== '' && strlen($value['val']) <= 128) {
                $values[$value['val']] = true;
            }
        }

        return array_keys($values);
    }
}
