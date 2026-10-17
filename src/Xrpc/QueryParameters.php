<?php

namespace SocialDept\AtpAppview\Xrpc;

/**
 * Reads XRPC query parameters with the types their lexicon declares.
 *
 * Everything in a URL is a string, and XRPC repeats a parameter for each item
 * of an array (`uris=a&uris=b`), which PHP's own parsing collapses to the last
 * value. So the raw query string is parsed here, and each value converted to
 * the declared integer, boolean or array type before validation.
 */
final class QueryParameters
{
    /**
     * @param  array<string, mixed>|null  $schema  The lexicon's `parameters` object
     * @return array<string, mixed>
     */
    public static function parse(?string $query, ?array $schema): array
    {
        $raw = self::raw((string) $query);
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $params = [];

        foreach ($raw as $name => $values) {
            $definition = $properties[$name] ?? null;

            // Parameters the lexicon does not declare pass through as strings.
            if (! is_array($definition)) {
                $params[$name] = count($values) > 1 ? $values : $values[0];

                continue;
            }

            $params[$name] = ($definition['type'] ?? null) === 'array'
                ? array_map(fn ($value) => self::cast($value, $definition['items'] ?? [], $name), $values)
                : self::cast(end($values), $definition, $name);
        }

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $params)) {
                throw XrpcException::invalidRequest("Missing required parameter: {$required}.");
            }
        }

        return $params;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected static function raw(string $query): array
    {
        $values = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $values[urldecode($name)][] = urldecode($value);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected static function cast(string $value, array $definition, string $name): mixed
    {
        return match ($definition['type'] ?? 'string') {
            'integer' => preg_match('/^-?\d+$/', $value)
                ? (int) $value
                : throw XrpcException::invalidRequest("Parameter {$name} must be an integer."),
            'boolean' => match ($value) {
                'true' => true,
                'false' => false,
                default => throw XrpcException::invalidRequest("Parameter {$name} must be true or false."),
            },
            default => $value,
        };
    }
}
