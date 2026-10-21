<?php

namespace SocialDept\AtpAppview\Index;

use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * One indexed field of a collection: where its value is in the record, its
 * type, and whether it holds a list.
 *
 * Declared in atp-appview.fields by type, `'tags' => 'string[]'`, or with
 * options, `'parent' => ['type' => 'uri', 'path' => 'reply.parent.uri']`.
 * Strings match case-insensitively unless `'case' => 'exact'`.
 */
final class Field
{
    public const TYPES = ['string', 'uri', 'datetime', 'number', 'bool'];

    public function __construct(
        public readonly string $collection,
        public readonly string $name,
        public readonly string $type,
        public readonly string $path,
        public readonly bool $many = false,
        public readonly bool $exactCase = false,
        // Text split into its words, each kept as a value, for matching
        // words such as muted ones.
        public readonly bool $words = false,
        // A class implementing Search\Contracts\SearchText whose text is
        // read in place of the path.
        public readonly ?string $text = null,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("atp-appview.fields.{$collection}.{$name} has an unknown type {$type}. Use one of ".implode(', ', self::TYPES).', with [] for a list.');
        }
    }

    /**
     * @param  string|array{type: string, path?: string, case?: string, words?: bool, text?: class-string}  $declared
     */
    public static function declared(string $collection, string $name, string|array $declared): self
    {
        $type = is_string($declared) ? $declared : (string) ($declared['type'] ?? 'string');
        $many = str_ends_with($type, '[]');

        return new self(
            $collection,
            $name,
            $many ? substr($type, 0, -2) : $type,
            is_array($declared) ? (string) ($declared['path'] ?? $name) : $name,
            $many,
            is_array($declared) && ($declared['case'] ?? null) === 'exact',
            is_array($declared) && ($declared['words'] ?? false) === true,
            is_array($declared) && is_string($declared['text'] ?? null) ? $declared['text'] : null,
        );
    }

    /**
     * Every value this field has in a record, ready to store: its hash, the
     * date or number it holds, and its text for counts. Values that cannot be
     * read as the field's type are left out.
     *
     * @param  array<string, mixed>  $record
     * @return array<int, array{hash: int, time: string|null, num: float|null, text: string}>
     */
    public function values(array $record): array
    {
        $raw = $this->text === null ? data_get($record, $this->path) : app($this->text)->text($record, $this->collection);
        $values = [];
        $items = $this->many ? Arr::wrap($raw) : [$raw];

        if ($this->words) {
            $items = array_merge(...array_map(
                fn ($item) => is_string($item) ? self::words($item) : [],
                [...Arr::flatten($items), ''],
            ));
        }

        foreach ($items as $item) {
            if (($value = $this->normalize($item)) !== null && ! isset($values[$value['hash']])) {
                $values[$value['hash']] = $value;
            }
        }

        return array_values($values);
    }

    /**
     * How the field's values are stored: its type, whether it is a list, and
     * its case. A field whose signature changes is built again.
     */
    public function signature(): string
    {
        return $this->type.($this->many ? '[]' : '').($this->exactCase ? ':exact' : '').($this->words ? ':words' : '').($this->text === null ? '' : ':text='.$this->text);
    }

    /**
     * The words in a text, lowercased: runs of letters and numbers, and each
     * emoji as a word of its own. A leading # is dropped, so a tag and the
     * same word in text match.
     *
     * @return array<int, string>
     */
    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}\p{M}_]+|\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}]*(?:\x{200D}\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}]*)*|\p{Regional_Indicator}{2}/u', mb_strtolower($text), $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * A value to match, as given in a query, normalized as stored.
     */
    public function hashOf(mixed $value): ?int
    {
        return $this->normalize($value)['hash'] ?? null;
    }

    /**
     * A date as stored, without sort_at's floor, since an old date is a real
     * value here. Years outside 1000 to 9999 are left out, which every
     * database can hold.
     */
    protected static function date(mixed $value): ?string
    {
        $date = SortAt::parse($value, floor: false);

        if (! $date instanceof DateTimeInterface || (int) $date->format('Y') < 1000) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /**
     * @return array{hash: int, time: string|null, num: float|null, text: string}|null
     */
    protected function normalize(mixed $value): ?array
    {
        $text = match ($this->type) {
            'string' => is_scalar($value) && ! is_bool($value) ? trim((string) $value) : null,
            'uri' => is_string($value) ? trim($value) : null,
            'number' => is_numeric($value) ? (string) (0 + $value) : null,
            'bool' => is_bool($value) ? ($value ? 'true' : 'false') : null,
            'datetime' => self::date($value),
        };

        if ($text === null || $text === '' || strlen($text) > 2048) {
            return null;
        }

        if ($this->type === 'string' && ! $this->exactCase) {
            $text = mb_strtolower($text);
        }

        return [
            'hash' => ValueHash::of($text),
            'time' => $this->type === 'datetime' ? $text : null,
            'num' => $this->type === 'number' ? (float) $text : null,
            'text' => $text,
        ];
    }
}
