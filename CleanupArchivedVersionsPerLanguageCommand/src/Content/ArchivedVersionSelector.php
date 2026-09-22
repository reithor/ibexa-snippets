<?php

declare(strict_types=1);

namespace App\Content;

use Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo;

/**
 * Decides which archived versions of a single content item are redundant.
 *
 * The rule: keep the most recent archived version of every language, remove the rest. A version is
 * matched to a language by its initial language - the translation it was edited and published in.
 * Matching by the translations a version contains would be pointless: a draft copies every
 * translation of its source version, so versions are cumulative supersets of their predecessors and
 * the newest one alone would cover every language.
 */
final class ArchivedVersionSelector
{
    /**
     * @param \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[] $versions archived versions of one content item, in any order
     * @param int $keep number of the most recent versions to keep regardless of their language
     *
     * @return \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[] the versions which can be removed
     */
    public function selectRemovable(array $versions, int $keep = 0): array
    {
        $versions = $this->sortByRecency($versions);

        $coveredLanguages = [];
        $removable = [];

        foreach ($versions as $index => $version) {
            $languageCode = $version->getInitialLanguage()->getLanguageCode();

            if (!in_array($languageCode, $coveredLanguages, true)) {
                // This version holds the most recent archived state of that translation.
                $coveredLanguages[] = $languageCode;

                continue;
            }

            if ($index < $keep) {
                continue;
            }

            $removable[] = $version;
        }

        return $removable;
    }

    /**
     * Sorts versions from the most to the least recently published.
     *
     * Ordering is by modification date, not by version number: the version number only reflects the
     * creation order of the draft, while `ezcontentobject_version.modified` is rewritten with time()
     * every time a version's status changes (see
     * {@see \Ibexa\Core\Persistence\Legacy\Content\Gateway\DoctrineDatabase\QueryBuilder::getSetVersionStatusQuery()}),
     * so for an archived version it is the moment it stopped being the published one. Drafts can be
     * published out of order, in which case a lower version number can hold the more recent state.
     *
     * The version number is only a tie-breaker, because
     * {@see \Ibexa\Contracts\Core\Repository\ContentService::deleteTranslation()} stamps the same
     * timestamp on every version of a content item.
     *
     * @param \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[] $versions
     *
     * @return \Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo[]
     */
    public function sortByRecency(array $versions): array
    {
        usort(
            $versions,
            static fn (VersionInfo $a, VersionInfo $b): int
                => [$b->modificationDate->getTimestamp(), $b->getVersionNo()]
                <=> [$a->modificationDate->getTimestamp(), $a->getVersionNo()]
        );

        return $versions;
    }
}
