<?php

namespace SocialDept\AtpAppview\Pds;

use InvalidArgumentException;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Pds\Contracts\PdsDriver;

/**
 * The PDSes the operator runs, from atp-appview.pds.hosts, and which of
 * them hosts an account.
 */
class Hosts
{
    /**
     * The driver for the PDS hosting this account, if the operator runs it.
     */
    public function for(string $did): ?PdsDriver
    {
        $pds = Identity::query()->whereKey($did)->value('pds');

        if (! is_string($pds) || $pds === '') {
            return null;
        }

        foreach ($this->hosts() as $host) {
            if (self::same($host['url'] ?? '', $pds)) {
                return $this->driver($host);
            }
        }

        return null;
    }

    /**
     * Whether the operator runs the PDS hosting this account.
     */
    public function hostsAccount(string $did): bool
    {
        return $this->urlFor($did) !== null;
    }

    /**
     * The PDS URL hosting this account, if the operator runs it.
     */
    public function urlFor(string $did): ?string
    {
        $pds = Identity::query()->whereKey($did)->value('pds');

        foreach ($this->hosts() as $host) {
            if (is_string($pds) && self::same($host['url'] ?? '', $pds)) {
                return rtrim((string) $host['url'], '/');
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function hosts(): array
    {
        return array_values(array_filter((array) config('atp-appview.pds.hosts', []), fn ($host) => is_array($host) && filled($host['url'] ?? null)));
    }

    /**
     * @param  array<string, mixed>  $host
     */
    protected function driver(array $host): PdsDriver
    {
        $name = (string) ($host['driver'] ?? 'admin');
        $class = config("atp-appview.pds.drivers.{$name}", $name);

        if (! is_string($class) || ! is_subclass_of($class, PdsDriver::class)) {
            throw new InvalidArgumentException("The PDS driver {$name} is not a PdsDriver.");
        }

        return app($class, ['url' => (string) $host['url'], 'password' => (string) ($host['password'] ?? '')] + $host);
    }

    protected static function same(string $a, string $b): bool
    {
        return strtolower(rtrim($a, '/')) === strtolower(rtrim($b, '/'));
    }
}
