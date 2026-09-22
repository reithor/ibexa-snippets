<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\ArchivedVersionSelector;
use DateTimeImmutable;
use Ibexa\Contracts\Core\Repository\Values\Content\Language;
use Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo as APIVersionInfo;
use Ibexa\Core\Repository\Values\Content\VersionInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArchivedVersionSelectorTest extends TestCase
{
    private ArchivedVersionSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new ArchivedVersionSelector();
    }

    /**
     * @param \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[] $versions
     * @param int[] $expectedRemoved version numbers expected to be removed
     * @param int[] $expectedKept version numbers expected to survive
     */
    #[DataProvider('provideVersionHistories')]
    public function testSelectRemovable(array $versions, array $expectedRemoved, array $expectedKept): void
    {
        $removable = $this->selector->selectRemovable($versions);

        self::assertSame($expectedRemoved, self::versionNumbers($removable));
        self::assertEqualsCanonicalizing($expectedKept, self::keptVersionNumbers($versions, $removable));
    }

    /**
     * @return iterable<string, array{
     *     \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[],
     *     int[],
     *     int[],
     * }>
     */
    public static function provideVersionHistories(): iterable
    {
        yield 'no archived versions' => [[], [], []];

        yield 'single archived version' => [
            [self::version(1, 'eng-GB', 100)],
            [],
            [1],
        ];

        yield 'every version in the same language' => [
            [
                self::version(1, 'eng-GB', 100),
                self::version(2, 'eng-GB', 200),
                self::version(3, 'eng-GB', 300),
            ],
            [2, 1],
            [3],
        ];

        yield 'one version per language' => [
            [
                self::version(1, 'eng-GB', 100),
                self::version(2, 'ger-DE', 200),
                self::version(3, 'pol-PL', 300),
            ],
            [],
            [1, 2, 3],
        ];

        yield 'several versions per language' => [
            [
                self::version(1, 'eng-GB', 100),
                self::version(2, 'ger-DE', 200),
                self::version(3, 'eng-GB', 300),
                self::version(4, 'ger-DE', 400),
                self::version(5, 'pol-PL', 500),
            ],
            [2, 1],
            [3, 4, 5],
        ];

        // Drafts are numbered when they are created but can be published in any order, so the
        // highest version number is not necessarily the most recently published one.
        yield 'out-of-order publishing: lower version number is the more recent one' => [
            [
                self::version(4, 'eng-GB', 100),
                self::version(6, 'eng-GB', 200),
                self::version(5, 'eng-GB', 300),
            ],
            [6, 4],
            [5],
        ];

        yield 'out-of-order publishing across languages keeps both' => [
            [
                self::version(6, 'eng-GB', 100),
                self::version(5, 'ger-DE', 200),
            ],
            [],
            [5, 6],
        ];

        // ContentService::deleteTranslation() stamps the same timestamp on every version of a
        // content item, so the version number has to break the tie.
        yield 'tied timestamps fall back to the version number' => [
            [
                self::version(4, 'eng-GB', 500),
                self::version(5, 'eng-GB', 500),
                self::version(6, 'eng-GB', 500),
            ],
            [5, 4],
            [6],
        ];

        yield 'tied timestamps still keep one version per language' => [
            [
                self::version(4, 'eng-GB', 500),
                self::version(5, 'ger-DE', 500),
                self::version(6, 'eng-GB', 500),
            ],
            [4],
            [5, 6],
        ];
    }

    /**
     * Versions reach the selector in the order ContentService::loadVersions() returns them, which is
     * by version ID - creation order, unrelated to publication order.
     */
    public function testResultDoesNotDependOnInputOrder(): void
    {
        $versions = [
            self::version(4, 'eng-GB', 100),
            self::version(6, 'ger-DE', 200),
            self::version(5, 'eng-GB', 300),
            self::version(7, 'ger-DE', 150),
        ];

        $expected = self::versionNumbers($this->selector->selectRemovable($versions));

        self::assertSame([7, 4], $expected);
        self::assertSame($expected, self::versionNumbers($this->selector->selectRemovable(array_reverse($versions))));
        self::assertSame($expected, self::versionNumbers($this->selector->selectRemovable([
            $versions[2], $versions[0], $versions[3], $versions[1],
        ])));
    }

    /**
     * @param \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[] $versions
     * @param int[] $expectedRemoved
     */
    #[DataProvider('provideKeepValues')]
    public function testKeepIsAFloorOnTheNumberOfSurvivingVersions(
        array $versions,
        int $keep,
        array $expectedRemoved
    ): void {
        self::assertSame(
            $expectedRemoved,
            self::versionNumbers($this->selector->selectRemovable($versions, $keep))
        );
    }

    /**
     * @return iterable<string, array{
     *     \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[],
     *     int,
     *     int[],
     * }>
     */
    public static function provideKeepValues(): iterable
    {
        // The language rule alone keeps v4 (ger-DE) and v3 (eng-GB): two survivors.
        $redundantAtTheTail = [
            self::version(1, 'eng-GB', 100),
            self::version(2, 'eng-GB', 200),
            self::version(3, 'eng-GB', 300),
            self::version(4, 'ger-DE', 400),
        ];

        yield 'no floor' => [$redundantAtTheTail, 0, [2, 1]];
        yield 'floor below the number of survivors' => [$redundantAtTheTail, 1, [2, 1]];
        yield 'floor equal to the number of survivors' => [$redundantAtTheTail, 2, [2, 1]];
        yield 'floor above the number of survivors keeps the most recent one back' => [$redundantAtTheTail, 3, [1]];
        yield 'floor equal to the version count' => [$redundantAtTheTail, 4, []];
        yield 'floor above the version count' => [$redundantAtTheTail, 10, []];

        $redundantAmongTheNewest = [
            self::version(29, 'ger-DE', 100),
            self::version(30, 'eng-GB', 200),
            self::version(33, 'eng-GB', 300),
        ];

        yield 'redundant version among the newest, no floor' => [$redundantAmongTheNewest, 0, [30]];
        yield 'redundant version among the newest, floor already met' => [$redundantAmongTheNewest, 2, [30]];
        yield 'redundant version among the newest, floor forces it back' => [$redundantAmongTheNewest, 3, []];
    }

    /**
     * The same predicate the SQL pre-filter in the command relies on:
     * removals = versions - distinct initial languages.
     */
    public function testRemovedCountEqualsVersionCountMinusDistinctLanguages(): void
    {
        $versions = [
            self::version(1, 'eng-GB', 100),
            self::version(2, 'ger-DE', 200),
            self::version(3, 'eng-GB', 300),
            self::version(4, 'pol-PL', 400),
            self::version(5, 'ger-DE', 500),
            self::version(6, 'eng-GB', 600),
        ];

        $removable = $this->selector->selectRemovable($versions);

        self::assertCount(count($versions) - 3, $removable);
        self::assertSame(
            ['eng-GB', 'ger-DE', 'pol-PL'],
            self::sortedLanguageCodes(self::keptVersions($versions, $removable))
        );
    }

    private static function version(int $versionNo, string $languageCode, int $modified): VersionInfo
    {
        return new VersionInfo([
            'versionNo' => $versionNo,
            'status' => APIVersionInfo::STATUS_ARCHIVED,
            'modificationDate' => new DateTimeImmutable('@' . $modified),
            'initialLanguage' => new Language([
                'id' => crc32($languageCode),
                'languageCode' => $languageCode,
                'name' => $languageCode,
                'enabled' => true,
            ]),
        ]);
    }

    /**
     * @param \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[] $versions
     *
     * @return int[]
     */
    private static function versionNumbers(array $versions): array
    {
        return array_map(
            static fn (APIVersionInfo $version): int => $version->getVersionNo(),
            $versions
        );
    }

    /**
     * @param \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[] $versions
     * @param \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[] $removable
     *
     * @return int[]
     */
    private static function keptVersionNumbers(array $versions, array $removable): array
    {
        return self::versionNumbers(self::keptVersions($versions, $removable));
    }

    /**
     * @param \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[] $versions
     * @param \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[] $removable
     *
     * @return \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[]
     */
    private static function keptVersions(array $versions, array $removable): array
    {
        return array_values(array_filter(
            $versions,
            // Identity comparison: value objects are not comparable as strings.
            static fn (APIVersionInfo $version): bool => !in_array($version, $removable, true)
        ));
    }

    /**
     * @param \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[] $versions
     *
     * @return string[]
     */
    private static function sortedLanguageCodes(array $versions): array
    {
        $languageCodes = array_map(
            static fn (APIVersionInfo $version): string => $version->getInitialLanguage()->getLanguageCode(),
            $versions
        );
        sort($languageCodes);

        return $languageCodes;
    }
}
