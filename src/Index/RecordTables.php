<?php

namespace SocialDept\AtpAppview\Index;

/**
 * Where a query's records and their field values are kept: the public
 * index, or the copy of followed spaces. A record filter reads the tables
 * it is given, so the same filter narrows either.
 */
final class RecordTables
{
    public function __construct(
        public readonly string $records,
        public readonly string $values,
    ) {
    }

    /**
     * atp_records and atp_record_values.
     */
    public static function index(): self
    {
        return new self('atp_records', RecordValues::VALUES);
    }

    /**
     * atp_space_records and atp_space_record_values.
     */
    public static function spaces(): self
    {
        return new self('atp_space_records', 'atp_space_record_values');
    }

    public function isIndex(): bool
    {
        return $this->records === 'atp_records';
    }
}
