<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ShortNameUsageTest extends TestCase
{
    /**
     * Anyone named to someone else is "Jordan P." (User::shortName()). firstName() is only for greeting a person themselves: the "Hi Sam," line of an email, Home's heading, and the "Thanks, Sam!" heading on the taken page.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        "greeting('Hi '.\$notifiable->firstName()",
        "'Hi '.\$user->firstName()",
        // The taken page thanks the person who just took the shift, so it greets them.
        "'It\\'s yours. Thanks, '.\$user->firstName().'!'",
        // The method itself.
        'public function firstName(): string',
    ];

    public function test_first_name_is_used_only_for_greetings(): void
    {
        $offenders = [];

        foreach (['app', 'resources/views'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file->isFile() || ! preg_match('/\.php$/', $file->getFilename())) {
                    continue;
                }

                foreach (file($file->getPathname()) as $number => $line) {
                    if (! str_contains($line, 'firstName()')) {
                        continue;
                    }

                    $allowed = false;

                    foreach (self::ALLOWED as $shape) {
                        if (str_contains($line, $shape)) {
                            $allowed = true;
                        }
                    }

                    if (! $allowed) {
                        $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $file->getPathname()).':'.($number + 1).': '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "firstName() is for greetings only. Use shortName() where one person is named to another:\n".implode("\n", $offenders));
    }

    /**
     * A person's `->name` is their full name, which a notification must not show to someone else. These are the only `->name` shapes notifications use: an area's or the organization's name.
     */
    public function test_notifications_never_read_a_persons_name_directly(): void
    {
        $offenders = [];

        foreach (glob(dirname(__DIR__, 2).'/app/Notifications/*.php') as $path) {
            foreach (file($path) as $number => $line) {
                $rest = str_replace(['area->name', '$organization->name', 'Organization::current()->name'], '', $line);

                if (preg_match('/->name\b/', $rest)) {
                    $offenders[] = basename($path).':'.($number + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, "A notification reads ->name. Use shortName() to name a person to someone else, or firstName() for the greeting:\n".implode("\n", $offenders));
    }
}
