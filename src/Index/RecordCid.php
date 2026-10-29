<?php

namespace SocialDept\AtpAppview\Index;

use SocialDept\AtpCbor\Core\CID;
use stdClass;
use Throwable;

/**
 * The CID of a record body, computed from its JSON the way a repo stores
 * it: as DAG-CBOR, with each {"$link": ...} a CID link.
 *
 * Needs socialdept/atp-cbor. Without it, and for a body JSON cannot carry
 * faithfully into DAG-CBOR (bytes, a float, a key PHP would read as a
 * number), the answer is null: the CID cannot be checked this way.
 */
final class RecordCid
{
    /**
     * @param  mixed  $value  The body decoded with objects for maps, as json_decode() does without $associative
     */
    public static function of(mixed $value): ?string
    {
        if (! $value instanceof stdClass || ! class_exists(CID::class)) {
            return null;
        }

        try {
            $ipld = self::ipld($value);

            return $ipld === null ? null : CID::forDagCbor($ipld)->toString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The value with links as CIDs and maps kept as objects, so an empty
     * map stays a map. Null when it holds something DAG-CBOR would encode
     * differently than the repo did.
     */
    protected static function ipld(mixed $value): mixed
    {
        if (is_float($value)) {
            return null;
        }

        if (is_array($value)) {
            $list = [];

            foreach ($value as $item) {
                $converted = self::ipld($item);

                if ($converted === null && $item !== null) {
                    return null;
                }

                $list[] = $converted;
            }

            return $list;
        }

        if (! $value instanceof stdClass) {
            return $value;
        }

        $fields = get_object_vars($value);

        if (array_keys($fields) === ['$link'] && is_string($fields['$link'])) {
            return CID::fromString($fields['$link']);
        }

        if (array_key_exists('$bytes', $fields)) {
            return null;
        }

        $map = new stdClass();

        foreach ($fields as $key => $item) {
            if (is_int($key) || ctype_digit((string) $key)) {
                return null;
            }

            $converted = self::ipld($item);

            if ($converted === null && $item !== null) {
                return null;
            }

            $map->{$key} = $converted;
        }

        return $map;
    }
}
