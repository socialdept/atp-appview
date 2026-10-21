<?php

namespace SocialDept\AtpAppview\Notifications;

use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Notifications\Contracts\Notifier;

/**
 * Tells subscribers when an account they asked to hear about writes a record
 * of a kind they chose. Added to the notifiers by itself while
 * atp-appview.notifications.activity names any kind. Each record notifies on
 * its own, its subject being the record.
 */
class ActivityNotifier implements Notifier
{
    public function __construct(
        protected ActivitySubscriptions $subscriptions,
    ) {
    }

    public function collections(): array
    {
        return array_values(array_unique(array_column(ActivitySubscriptions::kinds(), 'collection')));
    }

    public function notify(array $records): iterable
    {
        foreach (ActivitySubscriptions::kinds() as $kind => $activity) {
            $matching = array_filter($records, fn (Record $record) => $record->collection === $activity['collection']
                && (! isset($activity['with']) || filled(data_get($record->record, $activity['with'])))
                && (! isset($activity['without']) || blank(data_get($record->record, $activity['without']))));

            if ($matching === []) {
                continue;
            }

            $subscribers = $this->subscriptions->subscribers(array_map(fn (Record $record) => $record->did, $matching), $kind);

            foreach ($matching as $record) {
                foreach ($subscribers[$record->did] ?? [] as $subscriber) {
                    yield new Notify($record->uri, $subscriber, $activity['reason'], $record->uri);
                }
            }
        }
    }
}
