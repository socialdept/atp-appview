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

    public function test_a_tid_shaped_key_decoding_to_before_2022_is_not_trusted(): void
    {
        $this->assertSame('2026-10-17 12:00:00.000000', SortAt::for(null, '2222222222222', $this->indexedAt));
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
