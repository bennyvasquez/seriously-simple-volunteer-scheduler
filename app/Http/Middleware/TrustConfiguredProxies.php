<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Trusts the proxies named in the TRUSTED_PROXIES setting (config 'app.trusted_proxies') to report the visitor's address, scheme and host in the X-Forwarded-For, -Host, -Port and -Proto headers. With no setting, no proxy is trusted and those headers are ignored. "*" trusts every sender and has to be written out.
 */
class TrustConfiguredProxies extends TrustProxies
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $proxies = array_values(array_filter(
            array_map('trim', explode(',', (string) config('app.trusted_proxies'))),
            fn (string $proxy) => $proxy !== '',
        ));

        // Laravel only reads "*" as "every sender" when it's the whole value, not one item of a list.
        if (in_array($proxies, [['*'], ['**']], true)) {
            return $proxies[0];
        }

        return $proxies ?: null;
    }
}
