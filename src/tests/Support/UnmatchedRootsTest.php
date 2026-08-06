<?php

declare(strict_types=1);

use App\Controllers\Projects;
use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * What drives the "Skippable" suggestion in the admin panel: directories a
 * release covers that the newest one does not.
 *
 * It only ever suggests. Whether skipping a release actually matters is a
 * judgement about the application, not something this server can settle — so
 * marking it required stays an explicit click.
 *
 * @internal
 */
final class UnmatchedRootsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';

    /**
     * unguardedSteps() is private and reads the releases table, so it is
     * reached through the invoker rather than by widening its visibility.
     *
     * @return list<array{version: string, roots: list<string>}>
     */
    private function steps(int $projectId): array
    {
        $controller = new Projects();
        $this->setPrivateProperty($controller, 'releases', new ReleaseModel());

        return $this->getPrivateMethodInvoker($controller, 'unguardedSteps')($projectId);
    }

    private function project(): int
    {
        return (int) (new ProjectModel())->insert([
            'name'       => 'My App',
            'slug'       => 'my-app',
            'created_at' => '2026-01-01 00:00:00',
        ], true);
    }

    private function release(int $projectId, string $version, ?string $roots, bool $isLatest = false, bool $required = false): void
    {
        (new ReleaseModel())->insert([
            'project_id'  => $projectId,
            'version'     => $version,
            'roots'       => $roots,
            'required'    => $required ? 1 : 0,
            'is_latest'   => $isLatest ? 1 : 0,
            'released_at' => '2026-06-01',
            'created_at'  => '2026-06-01 12:00:00',
        ]);
    }

    // -- What the panel suggests ----------------------------------------------

    public function testSuggestsAReleaseCoveringSomethingTheLatestDoesNot(): void
    {
        $id = $this->project();
        $this->release($id, '1.1.0', 'app, public');
        $this->release($id, '1.2.0', 'app, public, vendor');
        $this->release($id, '1.3.0', 'app, public', true);

        self::assertSame([['version' => '1.2.0', 'roots' => ['vendor']]], $this->steps($id));
    }

    /**
     * Already marked: nothing left to suggest. Keeping it in the list would
     * make the badge nag about a decision that has been taken.
     */
    public function testSaysNothingAboutAReleaseAlreadyMarkedRequired(): void
    {
        $id = $this->project();
        $this->release($id, '1.2.0', 'app, public, vendor', false, true);
        $this->release($id, '1.3.0', 'app, public', true);

        self::assertSame([], $this->steps($id));
    }

    /**
     * Nothing skips the newest release, so covering more than the others is
     * exactly what it is supposed to do.
     */
    public function testTheLatestReleaseIsNeverSuggested(): void
    {
        $id = $this->project();
        $this->release($id, '1.2.0', 'app, public');
        $this->release($id, '1.3.0', 'app, public, vendor', true);

        self::assertSame([], $this->steps($id));
    }

    /**
     * "Latest" is a flag, not the highest version — any release can be
     * promoted. A release newer than the current latest is not on the path
     * anyone is taking, so nothing skips it and nothing should be suggested.
     */
    public function testAReleaseNewerThanTheLatestIsNotSuggested(): void
    {
        $id = $this->project();
        $this->release($id, '1.2.0', 'app, public');
        $this->release($id, '1.3.0', 'app, public', true);
        $this->release($id, '1.4.0', 'app, public, vendor');

        self::assertSame([], $this->steps($id));
    }

    public function testARemovedDirectoryIsNotASuggestion(): void
    {
        $id = $this->project();
        $this->release($id, '1.2.0', 'app');
        $this->release($id, '1.3.0', 'app, public', true);

        // 1.2.0 covers less than the latest, so skipping it loses nothing.
        self::assertSame([], $this->steps($id));
    }

    public function testSuggestionsComeBackInVersionOrder(): void
    {
        $id = $this->project();
        $this->release($id, '1.3.0', 'app, themes');
        $this->release($id, '1.2.0', 'app, vendor');
        $this->release($id, '1.4.0', 'app', true);

        self::assertSame(
            [
                ['version' => '1.2.0', 'roots' => ['vendor']],
                ['version' => '1.3.0', 'roots' => ['themes']],
            ],
            $this->steps($id)
        );
    }

    public function testNothingIsSuggestedWithoutALatestRelease(): void
    {
        $id = $this->project();
        $this->release($id, '1.2.0', 'app, public, vendor');

        self::assertSame([], $this->steps($id));
    }

    // -- The comparison itself -------------------------------------------------

    public function testReportsADirectoryTheLatestReleaseDoesNotCover(): void
    {
        self::assertSame(
            ['vendor'],
            Projects::unmatchedRoots('app, public, vendor', 'app, public')
        );
    }

    public function testSaysNothingWhenTheLatestCoversEverything(): void
    {
        self::assertSame([], Projects::unmatchedRoots('app, public', 'app, public'));
        self::assertSame([], Projects::unmatchedRoots('app', 'app, public, vendor'));
    }

    /**
     * Archives built before ci4-updater 2.6 declare no scope. Comparing
     * against nothing would either suggest everything or nothing at random,
     * so it suggests nothing.
     */
    public function testStaysSilentWhenEitherSideDeclaresNoScope(): void
    {
        self::assertSame([], Projects::unmatchedRoots(null, 'app, public'));
        self::assertSame([], Projects::unmatchedRoots('app, public, vendor', null));
        self::assertSame([], Projects::unmatchedRoots('', ''));
    }

    public function testReportsSeveralDirectoriesAtOnce(): void
    {
        self::assertSame(
            ['vendor', 'themes'],
            Projects::unmatchedRoots('app, vendor, themes', 'app, public')
        );
    }

    public function testParsingToleratesSpacingAndDuplicates(): void
    {
        self::assertSame(['app', 'public'], Projects::parseRoots(' app ,public,  app '));
        self::assertSame([], Projects::parseRoots('   '));
        self::assertSame([], Projects::parseRoots(null));
    }

    /**
     * The list is rendered in a badge tooltip, so it must be a real list —
     * array_diff preserves keys, and a gap would serialise as an object.
     */
    public function testTheResultIsAListWithoutHoles(): void
    {
        $value = Projects::unmatchedRoots('app, vendor, public, themes', 'app, public');

        self::assertSame([0, 1], array_keys($value));
    }
}
