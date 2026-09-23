

# CleanupArchivedVersionsPerLanguageCommand
`src/Command/CleanupArchivedVersionsPerLanguageCommand.php` — command name
`app:content:cleanup-archived-versions`.

Removes archived content versions while keeping the most recent archived version of every language.
Modelled on `ibexa:content:cleanup-versions`
(`vendor/ibexa/core/src/bundle/Core/Command/CleanupVersionsCommand.php`), which instead keeps the
last N versions regardless of language.

## Preliminary remarks

- Code should be considered as 'proof of concept' and comes with no guarantee.
- Before using it, make sure that behavior is exactly what you want.
- When using the command, you should disable regular version removal by setting
```
ibexa:
  repositories:
    default:
      options:
         remove_archived_versions_on_publish: false
```
- Additionaly you should not run the standard cleanup command  `ibexa:content:cleanup-version` as this also might remove archived versions that you want to keep.


## How it decides what to keep

Only `VersionInfo::STATUS_ARCHIVED` versions are loaded, so published versions and drafts are never
touched. Per content item the command sorts the archived versions most-recent-first and keeps the
first one it sees for each **initial language**; everything else is deleted.

`--keep` is a floor on how many archived versions survive per content item:
it only comes into play when the per-language rule alone would leave fewer than N,
and then the most recent of the otherwise removable versions are kept back until the floor is reached.
Drafts and the published version are not counted towards it.

Examples:
```

php bin/console app:content:cleanup-archived-versions
-> will check all content objects that have more than one archived version for at least one language
-> will remove all but the most recent version for each language that has an archived version

php bin/console app:content:cleanup-archived-versions --keep=30
-> will keep at least 30 archived versions
-> starting from version #31, versions are removed when language is already covered in #1 to #30

```

## Options

| Option | Default | Meaning |
| --- | --- | --- |
| `--user`, `-u` | `admin` | Ibexa username (needs content policies: remove, read, versionread) |
| `--excluded-content-types` | `user` | Comma-separated content type identifiers to skip (same default as core) |
| `--content-id`, `-c` | — | Comma-separated Content IDs to scope the run |
| `--keep`, `-k` | `0` | Minimum number of archived versions to keep per content item |
| `--dry-run` | off | Report only, delete nothing |
| `-v` | off | Per-version output instead of a progress bar |

Before a real run: back up the database, take the installation offline, run without a memory limit and
with `--env=prod`.



## Technical details


### Ordering: modification date, not version number

Versions are sorted by `modificationDate` descending, with the version number only as a tie-breaker.
The version number reflects the order drafts were *created*
(`getLastVersionNumber() + 1`), not the order they were published — drafts can be created in one
order and published in another, so a lower version number can hold the more recent archived state.

`ezcontentobject_version.modified` is rewritten with `time()` on every status change
(`Gateway\DoctrineDatabase\QueryBuilder::getSetVersionStatusQuery()` sets both `status` and
`modified`), so for an archived version it is the moment it stopped being the published one — exactly
the recency signal wanted here. The tie-breaker matters because `ContentService::deleteTranslation()`
stamps the same `modified` on *every* version of a content item
(`deleteTranslationFromContentVersions()` with `$versionNo = null`).

Note that `loadVersions()` itself returns versions ordered by `v.id` (see `listVersions()`), which is
creation order, hence the explicit re-sort.

### Candidate selection is only a pre-filter

`getObjectsIds()` is a cheap SQL query over `ezcontentobject` × `ezcontentobject_version` (`status = 3`)
that narrows the work down; the real keep/delete decision is taken per content item on the domain
objects in `ArchivedVersionSelector::selectRemovable()`. A content item can therefore be listed as a
candidate and still lose nothing.

The `HAVING` clause is `count(v.id) > count(distinct v.initial_language_id)`: exactly one archived
version is kept per initial language, so there is something to remove only when two archived versions
share their initial language. Without `--keep`, deletions per item are exactly
`count − distinct initial languages`, so this pre-filter is precise rather than merely conservative; a
`--keep` floor can only reduce that number, never raise it.

The final summary counts the content items actually touched, not the candidates.


## Compatibility

This branch targets **Ibexa DXP 4.6** (PHP 8.1+, doctrine/dbal 2.13, Symfony 5.4, PHPUnit 9). It
differs from the 5.0 version in:

* `ezcontentobject.contentclass_id` instead of `ibexa_content.content_type_id` (the table constants
  `Gateway::CONTENT_ITEM_TABLE` etc. exist in both versions and resolve to the right names by
  themselves, the column does not).
* `Connection::PARAM_STR_ARRAY` / `PARAM_INT_ARRAY` instead of `ArrayParameterType`, which only
  exists from doctrine/dbal 3.6.
* `QueryBuilder::execute()` instead of `executeQuery()`, which doctrine/dbal 2.13 does not have. It
  returns a `ForwardCompatibility\Result`, so `fetchFirstColumn()` still applies.
* `ContentService::loadVersions()` returns a plain array, and `iterator_to_array()` only accepts
  arrays as of PHP 8.2, so the result is passed through unchanged when it already is one.
* `@dataProvider` annotations instead of `#[DataProvider]` attributes, which need PHPUnit 10.

If the installation still runs PHP 8.0 or 7.4 (`ibexa/core` 4.6 allows both, although the supported
matrix is 8.1+), also expand the promoted `readonly` constructor properties and replace the
`#[AsCommand]` attribute with `setName()` / `setDescription()` in `configure()` - attributes are
ignored on PHP 7.4, which would leave the command without a name.
