<?php

namespace Tests\Browser\Concerns;

use Laravel\Dusk\Browser;
use PHPUnit\Framework\Assert;

/**
 * Runs axe-core, an open source accessibility checker, against the page the browser is on. The copy of axe is in tests/Browser/axe and DEVELOPMENT.md says how to update it.
 */
trait ChecksAccessibility
{
    /**
     * The WCAG (Web Content Accessibility Guidelines) 2.0 and 2.1 level A and AA rules.
     */
    private const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

    /**
     * Fails with each violation's rule id, help text and the elements it hit, so a failure in the CI log says what to fix. `$within` is a CSS selector that limits the check to one part of the page, such as a panel beside a screen that has its own known problems; leave it out to check the whole page.
     */
    protected function assertAccessible(Browser $browser, ?string $within = null): void
    {
        $browser->script(file_get_contents(__DIR__.'/../axe/axe.min.js'));

        // axe.run is asynchronous and Dusk's script() isn't, so the driver's async call waits for axe's callback.
        $browser->driver->manage()->timeouts()->setScriptTimeout(30);
        $tags = json_encode(self::AXE_TAGS);
        $context = $within === null ? 'document' : json_encode($within);
        $violations = $browser->driver->executeAsyncScript(
            "const done = arguments[arguments.length - 1];
            axe.run({$context}, {runOnly: {type: 'tag', values: {$tags}}})
                .then((results) => done(results.violations.map((v) => ({
                    id: v.id,
                    help: v.help,
                    impact: v.impact,
                    targets: v.nodes.map((n) => n.target.join(' ')),
                }))))
                .catch((error) => done({error: String(error)}));"
        );

        Assert::assertArrayNotHasKey('error', $violations, 'axe failed to run: '.($violations['error'] ?? ''));

        $report = collect($violations)->map(fn (array $v) => sprintf(
            "%s (%s): %s\n    %s",
            $v['id'],
            $v['impact'],
            $v['help'],
            implode("\n    ", $v['targets']),
        ))->implode("\n");

        Assert::assertEmpty($violations, "Accessibility violations on {$browser->driver->getCurrentURL()}:\n{$report}");
    }
}
