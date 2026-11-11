<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Telescope\TelescopeOverride;

class TelescopeOverrideTest extends TestCase
{
    private const INSTALLED = __DIR__.'/../vendor/laravel/telescope/src/Telescope.php';

    /**
     * When a Telescope update moves its main class to a variant no copy was taken from, this is
     * where it is noticed: run bin/refresh-telescope-override.php and add the checksum it prints.
     */
    public function test_the_installed_telescope_has_its_copy(): void
    {
        $this->assertNotNull(TelescopeOverride::file(self::INSTALLED),
            'no copy of this Telescope release under overrides/telescope-5/');
    }

    public function test_every_variant_listed_has_its_copy_in_the_package(): void
    {
        foreach (TelescopeOverride::checksums() as $checksum) {
            $this->assertNotNull(TelescopeOverride::copyFor($checksum), "the copy for $checksum is missing");
        }
    }

    public function test_a_variant_no_copy_was_taken_from_has_none(): void
    {
        $this->assertNull(TelescopeOverride::copyFor(str_repeat('0', 64)));
        $this->assertNull(TelescopeOverride::file(null));
    }

    public function test_the_copy_keeps_what_the_installed_release_declares(): void
    {
        $copy = file_get_contents(TelescopeOverride::file(self::INSTALLED));

        preg_match_all('/public static function (\w+)\(/', file_get_contents(self::INSTALLED), $declared);
        $provided = ['startRecording', 'stopRecording', 'withoutRecording', 'isRecording', 'recordUpdate',
            'flushEntries', 'store'];

        foreach (array_diff($declared[1], $provided) as $method) {
            $this->assertStringContainsString("public static function $method(", $copy, "the copy lost $method()");
        }
    }
}
