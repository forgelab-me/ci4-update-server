<?php

declare(strict_types=1);

use App\Controllers\Projects;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ci4-updater 2.6 made a release declare the directories it covers, and this
 * server records that to show it beside the release.
 *
 * The value comes out of an uploaded archive and is rendered in the admin
 * panel, so it is constrained here rather than trusted. This server never
 * applies an update, so it takes no view on whether a scope is *acceptable* —
 * that belongs to each installation's Config\Updater::$allowedRoots.
 *
 * @internal
 */
final class ReleaseRootsTest extends CIUnitTestCase
{
    public function testReadsTheDeclaredScope(): void
    {
        self::assertSame('app, public', Projects::readRoots([
            'version' => '1.0.0',
            'roots'   => ['app', 'public'],
        ]));
    }

    public function testReadsAScopeThatShipsDependencies(): void
    {
        self::assertSame('app, public, vendor', Projects::readRoots([
            'roots' => ['app', 'public', 'vendor'],
        ]));
    }

    /**
     * Manifests built before 2.6 declare nothing, and that has to stay
     * uploadable — this server has to keep serving releases it already holds.
     */
    public function testAManifestWithoutRootsYieldsNull(): void
    {
        self::assertNull(Projects::readRoots(['version' => '1.0.0']));
    }

    #[DataProvider('provideMalformedScopes')]
    public function testMalformedScopesYieldNull(mixed $roots): void
    {
        self::assertNull(Projects::readRoots(['roots' => $roots]));
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function provideMalformedScopes(): iterable
    {
        yield 'not an array'   => ['app,public'];
        yield 'empty array'    => [[]];
        yield 'only rejects'   => [['../etc', '.git']];
        yield 'only non-string' => [[42, ['app']]];
    }

    #[DataProvider('provideHostileEntries')]
    public function testDropsEntriesThatArentDirectoryNames(string $root): void
    {
        self::assertSame('app', Projects::readRoots(['roots' => ['app', $root]]));
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function provideHostileEntries(): iterable
    {
        yield 'traversal'   => ['..'];
        yield 'nested path' => ['app/Config'];
        yield 'backslash'   => ['app\\Config'];
        yield 'absolute'    => ['/etc'];
        yield 'dot dir'     => ['.git'];
        yield 'empty'       => [''];
        yield 'nul byte'    => ["app\0"];
        yield 'too long'    => [str_repeat('a', 65)];

        // It lands in an HTML table; the escaping in the view is the barrier,
        // but nothing shaped like this belongs in the column either.
        yield 'markup' => ['<script>alert(1)</script>'];
    }

    public function testDropsDuplicates(): void
    {
        self::assertSame('app, public', Projects::readRoots([
            'roots' => ['app', 'public', 'app'],
        ]));
    }

    public function testTruncatesToTheColumnWidth(): void
    {
        $roots = array_map(static fn (int $i): string => 'directory' . $i, range(1, 60));

        $value = Projects::readRoots(['roots' => $roots]);

        self::assertNotNull($value);
        self::assertLessThanOrEqual(255, mb_strlen($value));
    }
}
