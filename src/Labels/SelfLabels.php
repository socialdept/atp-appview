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

            $removed = $connection->table('atp_labels')->where('self', true)->whereIn('uri', array_column($written, 'uri'));

            foreach ((clone $removed)->distinct()->pluck('subject') as $did) {
                $changed[$did] = true;
            }

            $removed->delete();

            $labels = [];

            foreach ($written as $row) {
                foreach (self::values($row['record'] === null ? null : json_decode((string) $row['record'], true)) as $val) {
                    $changed[$row['did']] = true;
                    $labels[] = [
                        'src' => $row['did'], 'uri' => $row['uri'], 'subject' => $row['did'], 'cid' => $row['cid'],
                        'val' => $val, 'cts' => str_replace(' ', 'T', $now).'.000Z', 'exp' => null, 'verified' => true, 'self' => true,
                    ];
                }
            }

            if ($labels !== []) {
                $connection->table('atp_labels')->insertOrIgnore($labels);
            }
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
