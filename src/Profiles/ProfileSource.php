<?php

namespace SocialDept\AtpAppview\Profiles;

use Closure;

/**
 * One collection a profile can come from, such as app.bsky.actor.profile.
 *
 * Its record supplies the standard fields under the same names unless
 * `aliases` says otherwise, plus any extra fields it declares and computed
 * ones, which appear only when this source is the one used.
 */
class ProfileSource
{
    /** @var array<int, string> Extra fields passed through as they are */
    protected array $fields = [];

    /** @var array<string, string> Standard field => this lexicon's name for it */
    protected array $aliases = [];

    /** @var array<string, Closure(array<string, mixed>): mixed> Computed fields */
    protected array $maps = [];

    public function __construct(
        public readonly string $collection,
        public readonly string $rkey = 'self',
    ) {
    }

    /**
     * @param  array{collection: string, rkey?: string, fields?: array<int, string>, aliases?: array<string, string>}  $config
     */
    public static function fromConfig(array $config): self
    {
        return (new self($config['collection'], $config['rkey'] ?? 'self'))
            ->fields($config['fields'] ?? [])
            ->aliases($config['aliases'] ?? []);
    }

    /**
     * Extra fields from this source's record, passed through as they are.
     *
     * @param  array<int, string>  $fields
     */
    public function fields(array $fields): static
    {
        $this->fields = array_values(array_unique([...$this->fields, ...$fields]));

        return $this;
    }

    /**
     * Where this lexicon keeps a standard field, when it names it differently.
     *
     * @param  array<string, string>  $aliases  Standard field => field in this record
     */
    public function aliases(array $aliases): static
    {
        $this->aliases = [...$this->aliases, ...$aliases];

        return $this;
    }

    /**
     * A field computed from this source's record.
     *
     * @param  Closure(array<string, mixed> $record): mixed  $using
     */
    public function map(string $field, Closure $using): static
    {
        $this->maps[$field] = $using;

        return $this;
    }

    /**
     * A standard field's raw value in a record of this source.
     *
     * @param  array<string, mixed>  $record
     */
    public function standard(array $record, string $field): mixed
    {
        return $record[$this->aliases[$field] ?? $field] ?? null;
    }

    /**
     * The extra and computed fields of a record of this source.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function extra(array $record): array
    {
        $extra = [];

        foreach ($this->fields as $field) {
            $extra[$field] = $record[$field] ?? null;
        }

        foreach ($this->maps as $field => $using) {
            $extra[$field] = $using($record);
        }

        return $extra;
    }
}
