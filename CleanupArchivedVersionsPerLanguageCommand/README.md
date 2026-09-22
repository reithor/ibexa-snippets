# CleanupArchivedVersionsPerLanguageCommand

`src/Command/CleanupArchivedVersionsPerLanguageCommand.php` — command name
`app:content:cleanup-archived-versions`.

Removes archived content versions while keeping the most recent archived version of every language.
Modelled on `ibexa:content:cleanup-versions`
(`vendor/ibexa/core/src/bundle/Core/Command/CleanupVersionsCommand.php`), which instead keeps the
last N versions regardless of language.

## What "one version per language" can mean in Ibexa

A version carries **all** translations of the content item, not only the one that was edited:
`Handler::createDraftFromVersion()` loads the full content and clones every field of every language
into the new version, and `DoctrineDatabase::updateVersion()` merges the language mask with a bitwise
OR. Versions are therefore cumulative supersets of their predecessors — confirmed on this database,
where no version holds a language that is absent from all newer versions (content 399: v3 = `DE`,
v4…v7 = `DE` + `ger-DE`).

So "the archived version of language X" cannot mean "a version containing X" — that is almost every
version. It means the version whose **initial language** is X, i.e. the last archived state in which
that translation was edited and published.

## How it decides what to keep

Only `VersionInfo::STATUS_ARCHIVED` versions are loaded, so published versions and drafts are never
touched. Per content item the command sorts the archived versions most-recent-first and keeps the
first one it sees for each **initial language**; everything else is deleted.

Matching by the translations a version *contains* is not offered, because it is not meaningful here:
versions are cumulative supersets, so the newest archived version alone would cover every language and
the cleanup would degenerate into "keep one version per content item" — which is what core's
`--keep=1` already does.

### Ordering: modification date, not version number

Versions are sorted by `modificationDate` descending, with the version number only as a tie-breaker.
The version number reflects the order drafts were *created*
(`getLastVersionNumber() + 1`), not the order they were published — drafts can be created in one
order and published in another, so a lower version number can hold the more recent archived state.

`ibexa_content_version.modified` is rewritten with `time()` on every status change
(`Gateway\DoctrineDatabase\QueryBuilder::getSetVersionStatusQuery()` sets both `status` and
`modified`), so for an archived version it is the moment it stopped being the published one — exactly
the recency signal wanted here. The tie-breaker matters because `ContentService::deleteTranslation()`
stamps the same `modified` on *every* version of a content item
(`deleteTranslationFromContentVersions()` with `$versionNo = null`).

Note that `loadVersions()` itself returns versions ordered by `v.id` (see `listVersions()`), which is
creation order, hence the explicit re-sort.

### Candidate selection is only a pre-filter

`getObjectsIds()` is a cheap SQL query over `ibexa_content` × `ibexa_content_version` (`status = 3`)
that narrows the work down; the real keep/delete decision is taken per content item on the domain
objects in `getVersionsToRemove()`. A content item can therefore be listed as a candidate and still
lose nothing.

The `HAVING` clause is `count(v.id) > count(distinct v.initial_language_id)`: exactly one archived
version is kept per initial language, so there is something to remove only when two archived versions
share their initial language. Deletions per item are exactly
`count − distinct initial languages`, so this pre-filter is precise rather than merely conservative.

The final summary counts the content items actually touched, not the candidates.

## Options

| Option | Default | Meaning |
| --- | --- | --- |
| `--user`, `-u` | `admin` | Ibexa username (needs content policies: remove, read, versionread) |
| `--excluded-content-types` | `user` | Comma-separated content type identifiers to skip (same default as core) |
| `--content-id`, `-c` | — | Comma-separated Content IDs to scope the run |
| `--keep`, `-k` | `0` | Additionally keep the N most recent archived versions |
| `--dry-run` | off | Report only, delete nothing |
| `-v` | off | Per-version output instead of a progress bar |

Before a real run: back up the database, take the installation offline, run without a memory limit and
with `--env=prod`.

