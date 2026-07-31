<?php

declare(strict_types=1);

use App\Controllers\Projects;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The version string comes from manifest.json *inside* an uploaded archive and
 * is used as a directory name under writable/uploads. These cases pin the
 * validation that keeps it from escaping that directory.
 *
 * @internal
 */
final class SafeVersionTest extends CIUnitTestCase
{
    #[DataProvider('provideTraversalAttempts')]
    public function testRejectsPathTraversalAndSeparators(string $version): void
    {
        $this->assertFalse(
            Projects::isSafeVersion($version),
            "Expected '{$version}' to be rejected"
        );
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideTraversalAttempts(): iterable
    {
        yield 'parent directory'      => ['../../public'];
        yield 'single dot-dot'        => ['..'];
        yield 'trailing traversal'    => ['1.0.0/../../..'];
        yield 'embedded traversal'    => ['1.0..0/../x'];
        yield 'forward slash'         => ['1.0.0/evil'];
        yield 'backslash'             => ['1.0.0\\evil'];
        yield 'absolute path'         => ['/etc/passwd'];
        yield 'windows absolute'      => ['C:/Windows'];
        yield 'null byte'             => ["1.0.0\0.php"];
        yield 'empty'                 => [''];
        yield 'whitespace'            => ['1.0 .0'];
        yield 'leading dot'           => ['.hidden'];
        yield 'leading hyphen'        => ['-rf'];
        yield 'too long'              => ['1.0.0-alpha.build.12345678901234567890'];
        yield 'semicolon'             => ['1.0.0;rm'];
        yield 'newline'               => ["1.0.0\n"];
    }

    #[DataProvider('provideValidVersions')]
    public function testAcceptsRealisticVersions(string $version): void
    {
        $this->assertTrue(
            Projects::isSafeVersion($version),
            "Expected '{$version}' to be accepted"
        );
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideValidVersions(): iterable
    {
        yield 'semver'          => ['1.0.0'];
        yield 'two part'        => ['2.1'];
        yield 'single number'   => ['7'];
        yield 'prerelease'      => ['1.0.0-beta.1'];
        yield 'build metadata'  => ['1.0.0_build2'];
        yield 'v prefix'        => ['v1.2.3'];
        yield 'date based'      => ['2026.07.29'];
        yield 'at the 20 limit' => ['1.0.0-rc.1234567890'];
    }
}
