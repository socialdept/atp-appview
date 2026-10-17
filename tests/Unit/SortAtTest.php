<?php

namespace SocialDept\AtpAppview\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SocialDept\AtpAppview\Index\SortAt;

class SortAtTest extends TestCase
{
    protected DateTimeImmutable $indexedAt;

    protected function setUp(): void
    {
        $this->indexedAt = new DateTimeImmutable('2026-10-17 12:00:00', new DateTimeZone('UTC'));
    }

    public function test_created_at_is_used_when_it_is_before_the_index_time(): void
    {
        $this->assertSame('2024-03-01 09:30:00.250000', SortAt::for(['createdAt' => '2024-03-01T09:30:00.250Z'], 'self', $this->indexedAt));
    }

    public function test_created_at_in_another_timezone_is_normalized_to_utc(): void
    {
        $this->assertSame('2024-03-01 07:30:00.000000', SortAt::for(['createdAt' => '2024-03-01T09:30:00+02:00'], 'self', $this->indexedAt));
    }

    public function test_a_future_created_at_is_capped_at_the_index_time(): void
    {
        $this->assertSame('2026-10-17 12:00:00.000000', SortAt::for(['createdAt' => '2099-01-01T00:00:00Z'], 'self', $this->indexedAt));
    }

    public function test_a_tid_record_key_supplies_the_time_without_created_at(): void
    {
        $made = new DateTimeImmutable('2025-06-15 08:00:00.123456', new DateTimeZone('UTC'));

        $this->assertSame('2025-06-15 08:00:00.123456', SortAt::for(['text' => 'no date'], $this->tid($made), $this->indexedAt));
    }

    public function test_an_unusable_created_at_falls_back_to_the_tid(): void
    {
        $made = new DateTimeImmutable('2025-06-15 08:00:00', new DateTimeZone('UTC'));

        $this->assertSame('2025-06-15 08:00:00.000000', SortAt::for(['createdAt' => 'yesterday'], $this->tid($made), $this->indexedAt));
    }

    public function test_a_key_that_is_not_a_tid_falls_back_to_the_index_time(): void
    {
        $this->assertSame('2026-10-17 12:00:00.000000', SortAt::for(null, 'self', $this->indexedAt));
        $this->assertSame('2026-10-17 12:00:00.000000', SortAt::for(null, 'not-a-tid-key', $this->indexedAt));
    }

    public function test_a_tid_shaped_key_decoding_to_before_1970_is_not_trusted(): void
    {
        // Decodes to the epoch itself.
        $this->assertSame('2026-10-17 12:00:00.000000', SortAt::for(null, '2222222222222', $this->indexedAt));
    }

    public function test_a_backdated_tid_from_an_import_is_trusted(): void
    {
        // Importers mint keys from a post's original date.
        $made = new DateTimeImmutable('2010-03-04 21:19:38', new DateTimeZone('UTC'));

        $this->assertSame('2010-03-04 21:19:38.000000', SortAt::for(['title' => 'old post'], $this->tid($made), $this->indexedAt));
    }

    public function test_fields_are_tried_in_order(): void
    {
        $fields = ['publishedAt', 'createdAt'];

        $this->assertSame('2016-05-01 00:00:00.000000', SortAt::for(['publishedAt' => '2016-05-01T00:00:00Z', 'createdAt' => '2024-01-01T00:00:00Z'], 'self', $this->indexedAt, $fields));
        $this->assertSame('2024-01-01 00:00:00.000000', SortAt::for(['publishedAt' => 'soon', 'createdAt' => '2024-01-01T00:00:00Z'], 'self', $this->indexedAt, $fields));
        $this->assertSame('2026-10-17 12:00:00.000000', SortAt::for(['publishedAt' => '2016-05-01T00:00:00Z'], 'self', $this->indexedAt), 'Only the listed fields count.');
    }

    public function test_dates_are_read_as_records_actually_write_them(): void
    {
        $this->assertSame('2026-05-05 03:06:50.000000', SortAt::for(['createdAt' => '2026-05-05 03:06:50'], 'self', $this->indexedAt));
        $this->assertSame('2025-09-28 02:00:00.000000', SortAt::for(['createdAt' => '2025-09-27 22:00:00-04:00'], 'self', $this->indexedAt));
        $this->assertSame('2026-08-17 00:00:00.000000', SortAt::for(['createdAt' => '2026-08-17.000Z'], 'self', $this->indexedAt), 'A broken time part keeps the date.');
        $this->assertSame('2022-01-01 00:00:00.000000', SortAt::for(['createdAt' => '2022-01-01'], 'self', $this->indexedAt));
    }

    public function test_impossible_and_pre_1970_dates_are_missing(): void
    {
        $this->assertSame('2026-10-17 12:00:00.000000', SortAt::for(['createdAt' => '2024-02-30T10:00:00Z'], 'self', $this->indexedAt));
        $this->assertSame('2026-10-17 12:00:00.000000', SortAt::for(['createdAt' => '0001-01-01T00:00:00Z'], 'self', $this->indexedAt));
        $this->assertSame('2026-10-17 12:00:00.000000', SortAt::for(['createdAt' => '1969-07-20T20:17:00Z'], 'self', $this->indexedAt));
    }

    protected function tid(DateTimeImmutable $time): string
    {
        $micros = ((int) $time->format('U')) * 1_000_000 + (int) $time->format('u');
        $value = $micros << 10;
        $alphabet = '234567abcdefghijklmnopqrstuvwxyz';
        $tid = '';

        for ($i = 0; $i < 13; $i++) {
            $tid = $alphabet[$value & 31].$tid;
            $value >>= 5;
        }

        return $tid;
    }
}
