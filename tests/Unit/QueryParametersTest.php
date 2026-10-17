<?php

namespace SocialDept\AtpAppview\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpAppview\Xrpc\QueryParameters;
use SocialDept\AtpAppview\Xrpc\XrpcException;

class QueryParametersTest extends TestCase
{
    protected const SCHEMA = [
        'type' => 'params',
        'required' => ['actor'],
        'properties' => [
            'actor' => ['type' => 'string'],
            'limit' => ['type' => 'integer'],
            'reverse' => ['type' => 'boolean'],
            'uris' => ['type' => 'array', 'items' => ['type' => 'string']],
            'counts' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ],
    ];

    public function test_repeated_parameters_become_an_array_in_order(): void
    {
        $params = QueryParameters::parse('actor=a&uris=at%3A%2F%2Fone&uris=at%3A%2F%2Ftwo&uris=at%3A%2F%2Fthree', self::SCHEMA);

        $this->assertSame(['at://one', 'at://two', 'at://three'], $params['uris']);
    }

    public function test_a_single_value_for_an_array_parameter_is_still_an_array(): void
    {
        $this->assertSame(['at://one'], QueryParameters::parse('actor=a&uris=at%3A%2F%2Fone', self::SCHEMA)['uris']);
    }

    public function test_values_are_cast_to_their_declared_types(): void
    {
        $params = QueryParameters::parse('actor=a&limit=-3&reverse=false&counts=1&counts=2', self::SCHEMA);

        $this->assertSame(['actor' => 'a', 'limit' => -3, 'reverse' => false, 'counts' => [1, 2]], $params);
    }

    public function test_undeclared_parameters_pass_through_as_strings(): void
    {
        $params = QueryParameters::parse('actor=a&extra=1&tag=x&tag=y', self::SCHEMA);

        $this->assertSame('1', $params['extra']);
        $this->assertSame(['x', 'y'], $params['tag']);
    }

    public function test_a_missing_required_parameter_is_refused(): void
    {
        $this->expectException(XrpcException::class);

        QueryParameters::parse('limit=5', self::SCHEMA);
    }

    public function test_an_invalid_integer_or_boolean_is_refused(): void
    {
        foreach (['actor=a&limit=1.5', 'actor=a&reverse=1', 'actor=a&counts=1&counts=x'] as $query) {
            try {
                QueryParameters::parse($query, self::SCHEMA);
                $this->fail("{$query} should be refused.");
            } catch (XrpcException $e) {
                $this->assertSame('InvalidRequest', $e->error);
            }
        }
    }

    public function test_no_schema_means_everything_is_a_string(): void
    {
        $this->assertSame(['limit' => '5'], QueryParameters::parse('limit=5', null));
    }
}
