<?php

namespace SocialDept\AtpAppview\Views\Contracts;

/**
 * A part read from atp_record_refs, which names the reference it needs kept.
 */
interface ReadsRefs
{
    /**
     * @return array{0: string, 1: string} The collection that points, and the field it points through
     */
    public function ref(): array;
}
