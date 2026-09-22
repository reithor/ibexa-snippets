<?php

declare(strict_types=1);

namespace App\Command;

use App\Content\ArchivedVersionSelector;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Exception;
use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo;
use Ibexa\Core\Base\Exceptions\InvalidArgumentException;
use Ibexa\Core\Persistence\Legacy\Content\Gateway;
use Ibexa\Core\Persistence\Legacy\Content\Type\Gateway as ContentTypeGateway;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:content:cleanup-archived-versions',
    description: 'Removes archived content versions, but keeps the most recent archived version of every language.'
)]
final class CleanupArchivedVersionsPerLanguageCommand extends Command
{
    public const DEFAULT_REPOSITORY_USER = 'admin';
    public const DEFAULT_EXCLUDED_CONTENT_TYPES = 'user';

    public const BEFORE_RUNNING_HINTS = <<<EOT
<error>Before you continue:</error>
- Make sure to back up your database.
- Take the installation offline. The database should not be modified while the script is being executed.
- Run this command without memory limit.
- Run this command in production environment using <info>--env=prod</info>
EOT;

    public function __construct(
        private readonly Repository $repository,
        private readonly Connection $connection,
        private readonly ArchivedVersionSelector $versionSelector
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $beforeRunningHints = self::BEFORE_RUNNING_HINTS;

        $this
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'Ibexa username (with a Role containing at least content policies: remove, read, versionread)',
                self::DEFAULT_REPOSITORY_USER
            )
            ->addOption(
                'excluded-content-types',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of content type identifiers whose versions should not be removed, for instance `article`.',
                self::DEFAULT_EXCLUDED_CONTENT_TYPES
            )
            ->addOption(
                'content-id',
                'c',
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of Content IDs to limit the cleanup to. All content items are processed when omitted.'
            )
            ->addOption(
                'keep',
                'k',
                InputOption::VALUE_REQUIRED,
                'Minimum number of archived versions to keep per content item, even when the per-language rule would leave fewer.',
                '0'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Only report what would be removed, without deleting anything.'
            )
            ->setHelp(
                <<<EOT
The command <info>%command.name%</info> removes archived content versions, while keeping, for every
language, the most recent archived version of that language. Published versions and drafts are never
touched.

Every version carries all translations of the content item, not only the edited one, so "the version of
a language" means the newest archived version whose <info>initial language</info> is that language -
the last archived state in which that translation was edited.

<info>--keep</info> is a floor, not a shield: it only matters when keeping one version per language
would leave fewer archived versions than that. Drafts and the published version are not counted.

Note: This script can potentially run for a very long time, and in the Symfony dev environment it will
consume memory exponentially with the size of the dataset.

{$beforeRunningHints}
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Repository services and config are not loaded before execute() to avoid loading them before SiteAccess is set.
        $keep = (int) $input->getOption('keep');
        if ($keep < 0) {
            throw new InvalidArgumentException('keep', 'Keep value cannot be negative.');
        }

        $isDryRun = (bool) $input->getOption('dry-run');

        $contentService = $this->repository->getContentService();
        $userService = $this->repository->getUserService();
        $permissionResolver = $this->repository->getPermissionResolver();

        $permissionResolver->setCurrentUserReference(
            $userService->loadUserByLogin((string) $input->getOption('user'))
        );

        $contentIds = $this->getObjectsIds(
            $this->parseList((string) $input->getOption('excluded-content-types'), self::DEFAULT_EXCLUDED_CONTENT_TYPES),
            array_map('intval', $this->parseList((string) $input->getOption('content-id')))
        );
        $contentIdsCount = count($contentIds);

        if ($contentIdsCount === 0) {
            $output->writeln('<info>There is no content matching the given Criteria.</info>');

            return self::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Found %d candidate Content ID(s).</info>',
            $contentIdsCount
        ));

        if ($isDryRun) {
            $output->writeln('<comment>Dry run: no version will be deleted.</comment>');
        }

        $displayProgressBar = !($output->isVerbose() || $output->isVeryVerbose() || $output->isDebug());

        if ($displayProgressBar) {
            $progressBar = new ProgressBar($output, $contentIdsCount);
            $progressBar->setFormat(
                '%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%' . PHP_EOL
            );
            $progressBar->start();
        }

        $removedVersionsCounter = 0;
        $affectedContentCounter = 0;

        foreach ($contentIds as $contentId) {
            try {
                $contentInfo = $contentService->loadContentInfo((int) $contentId);
                $versions = iterator_to_array(
                    $contentService->loadVersions($contentInfo, VersionInfo::STATUS_ARCHIVED)
                );

                $output->writeln(sprintf(
                    '<info>Content %d has %d archived version(s).</info>',
                    $contentInfo->getId(),
                    count($versions)
                ), OutputInterface::VERBOSITY_VERBOSE);

                $versionsToRemove = $this->versionSelector->selectRemovable($versions, $keep);
                if ($versionsToRemove !== []) {
                    ++$affectedContentCounter;
                }

                foreach ($versionsToRemove as $version) {
                    if (!$isDryRun) {
                        $contentService->deleteVersion($version);
                    }
                    ++$removedVersionsCounter;

                    $output->writeln(sprintf(
                        $isDryRun
                            ? 'Content (%d) version (%d, edited in %s) would be deleted.'
                            : 'Content (%d) version (%d, edited in %s) has been deleted.',
                        $contentInfo->getId(),
                        $version->getVersionNo(),
                        $version->getInitialLanguage()->getLanguageCode()
                    ), OutputInterface::VERBOSITY_VERBOSE);
                }

                if ($displayProgressBar) {
                    $progressBar->advance(1);
                }
            } catch (Exception $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            }
        }

        $output->writeln(sprintf(
            $isDryRun
                ? '<info>Would remove %d archived version(s) from %d Content item(s).</info>'
                : '<info>Removed %d archived version(s) from %d Content item(s).</info>',
            $removedVersionsCounter,
            $affectedContentCounter
        ));

        return self::SUCCESS;
    }

    /**
     * Narrows the work down to the content items that can have archived versions removed.
     *
     * This is a cheap SQL pre-filter; the final decision is taken per content item by
     * {@see \App\Content\ArchivedVersionSelector}, which works on the domain objects.
     *
     * @param string[] $excludedContentTypes
     * @param int[] $contentIds
     *
     * @return string[]
     */
    private function getObjectsIds(array $excludedContentTypes, array $contentIds): array
    {
        $query = $this->connection->createQueryBuilder();
        $expr = $query->expr();

        $query
            ->select('c.id')
            ->from(Gateway::CONTENT_ITEM_TABLE, 'c')
            ->join('c', Gateway::CONTENT_VERSION_TABLE, 'v', 'v.contentobject_id = c.id')
            ->join('c', ContentTypeGateway::CONTENT_TYPE_TABLE, 'ct', 'ct.id = c.content_type_id')
            ->where($expr->eq('v.status', ':status'))
            ->groupBy('c.id')
            // Exactly one archived version is kept per initial language, so there is something to
            // remove only when two archived versions share their initial language.
            ->having('count(v.id) > count(distinct v.initial_language_id)')
            ->setParameter('status', VersionInfo::STATUS_ARCHIVED);

        if ($excludedContentTypes !== []) {
            $query
                ->andWhere($expr->notIn('ct.identifier', ':contentTypes'))
                ->setParameter('contentTypes', $excludedContentTypes, ArrayParameterType::STRING);
        }

        if ($contentIds !== []) {
            $query
                ->andWhere($expr->in('c.id', ':contentIds'))
                ->setParameter('contentIds', $contentIds, ArrayParameterType::INTEGER);
        }

        return $query->executeQuery()->fetchFirstColumn();
    }

    /**
     * @return string[]
     */
    private function parseList(string $value, string $default = ''): array
    {
        if (trim($value) === '') {
            $value = $default;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }
}
