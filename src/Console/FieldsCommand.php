<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Features\Spaces as SpacesFeature;
use SocialDept\AtpAppview\Index\Field;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Spaces\SpaceRecordValues;

class FieldsCommand extends Command
{
    protected $signature = 'appview:fields
                            {--recount : Count every value again, to repair counts that drifted}';

    protected $description = 'Build the values of fields declared since the last run, and drop those of removed ones';

    /**
     * The name it had while it only built references.
     *
     * @var array<int, string>
     */
    protected $aliases = ['appview:refs'];

    public function handle(RecordValues $values): int
    {
        $started = microtime(true);

        if ($this->option('recount')) {
            $values->recount();
            $this->components->info(sprintf('Counted again in %.1f s.', microtime(true) - $started));

            return self::SUCCESS;
        }

        $result = $values->work(function (Field $field, int $done) {
            if ($done % 50000 < 1000) {
                $this->components->twoColumnDetail("{$field->collection} {$field->name}", number_format($done).' records');
            }
        });

        foreach ($result['built'] as $field) {
            $this->components->info("Built {$field}.");
        }

        // Space records' values, for the keys the public run settled.
        $spaces = SpacesFeature::on() ? app(SpaceRecordValues::class)->work(function (Field $field, int $done) {
            if ($done % 50000 < 1000) {
                $this->components->twoColumnDetail("{$field->collection} {$field->name} in spaces", number_format($done).' records');
            }
        }) : [];

        foreach ($spaces as $field) {
            $this->components->info("Built {$field} in spaces.");
        }

        foreach ($result['dropped'] as $field) {
            $this->components->info("Dropped {$field}.");
        }

        if ($result['built'] === [] && $result['dropped'] === [] && $spaces === []) {
            $this->components->info('Every field is built.');
        } else {
            $this->components->info(sprintf('Done in %.1f s.', microtime(true) - $started));
        }

        return self::SUCCESS;
    }
}
