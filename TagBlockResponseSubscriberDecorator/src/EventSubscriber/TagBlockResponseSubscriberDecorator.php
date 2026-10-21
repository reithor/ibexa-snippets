<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use DateTimeImmutable;
use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\ContentTypeService;
use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\SiteAccess\ConfigResolverInterface;
use Ibexa\Contracts\FieldTypePage\FieldType\LandingPage\Model\BlockValue;
use Ibexa\Core\MVC\Symfony\View\CachableView;
use Ibexa\Core\MVC\Symfony\View\ContentValueView;
use Ibexa\Core\MVC\Symfony\View\LocationValueView;
use Ibexa\FieldTypePage\Event\BlockResponseEvent;
use Ibexa\FieldTypePage\Event\BlockResponseEvents;
use Ibexa\FieldTypePage\Event\Subscriber\TagBlockResponseSubscriber;
use Ibexa\FieldTypePage\FieldType\Page\Block\Relation\RelationCollector;
use Ibexa\FieldTypePage\FieldType\Page\FieldDefinitionLocator;
use Ibexa\HttpCache\Handler\TagHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Logs TTL changes made by TagBlockResponseSubscriber and works around its multilingual bug:
 * onResponse() reloads the content with versionInfo->languageCodes (ordered by language ID, not
 * by SiteAccess priority), so the page TTL and relation tags are computed from the wrong translation.
 * Here they are recomputed from the translation that was actually rendered.
 */
#[AsDecorator(decorates: TagBlockResponseSubscriber::class)]
final class TagBlockResponseSubscriberDecorator implements EventSubscriberInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly TagBlockResponseSubscriber $inner,
        #[Autowire(service: 'monolog.logger.block_ttl')]
        private readonly LoggerInterface $logger,
        private readonly ConfigResolverInterface $configResolver,
        #[Autowire(service: 'ibexa.api.repository')]
        private readonly Repository $repository,
        private readonly ContentService $contentService,
        private readonly ContentTypeService $contentTypeService,
        private readonly FieldDefinitionLocator $pageFieldDefinitionLocator,
        private readonly RelationCollector $relationCollector,
        private readonly TagHandler $tagHandler,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Same events and priorities as the decorated subscriber
        return [
            BlockResponseEvents::BLOCK_RESPONSE => ['onBlockResponse', 0],
            KernelEvents::RESPONSE => ['onResponse', 5],
        ];
    }

    public function onBlockResponse(BlockResponseEvent $event): void
    {
        $before = $this->snapshot($event->getResponse());
        $this->inner->onBlockResponse($event);
        $after = $this->snapshot($event->getResponse());

        if ($this->isTtlChanged($before, $after)) {
            $block = $event->getBlockValue();
            $this->logger->debug('Block response cache headers changed', [
                'block_id' => $block->getId(),
                'block_type' => $block->getType(),
                'since' => $block->getSince()?->format(DATE_ATOM),
                'till' => $block->getTill()?->format(DATE_ATOM),
                'before' => $before,
                'after' => $after,
            ]);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $before = $this->snapshot($event->getResponse());
        $this->inner->onResponse($event);
        $languageCode = $this->applyTranslationTtl($event);
        $after = $this->snapshot($event->getResponse());

        if ($this->isTtlChanged($before, $after)) {
            $view = $event->getRequest()->attributes->get('view');
            $this->logger->debug('Page response cache headers changed', [
                'uri' => $event->getRequest()->getRequestUri(),
                'content_id' => is_object($view) && method_exists($view, 'getContent') ? $view->getContent()->id : null,
                'location_id' => is_object($view) && method_exists($view, 'getLocation') ? $view->getLocation()?->id : null,
                'language_code' => $languageCode,
                'before' => $before,
                'after' => $after,
            ]);
        }
    }

    /**
     * Recomputes page TTL and relation tags from the rendered translation; only ever lowers the TTL.
     *
     * @return string|null the language code used, null if the response is not a cached Page view
     */
    private function applyTranslationTtl(ResponseEvent $event): ?string
    {
        $view = $event->getRequest()->attributes->get('view');
        if (!$view instanceof CachableView || !$view->isCacheEnabled()) {
            return null;
        }

        if (!$view instanceof ContentValueView || !$view instanceof LocationValueView) {
            return null;
        }

        $response = $event->getResponse();
        $referenceDate = $response->getDate();
        if (null === $referenceDate) {
            return null;
        }

        $viewContent = $view->getContent();
        $languageCode = $viewContent->getDefaultLanguageCode();

        // Reload as the decorated subscriber does (ParameterProvider strips invisible blocks from
        // the view's Page), but with the rendered language first
        $content = $this->repository->sudo(
            fn (): Content => $this->contentService->loadContent(
                $viewContent->id,
                array_values(array_unique([$languageCode, ...$viewContent->versionInfo->languageCodes])),
                $viewContent->versionInfo->versionNo
            )
        );

        $contentType = $this->contentTypeService->loadContentType($content->contentInfo->contentTypeId);
        $fieldDefinition = $this->pageFieldDefinitionLocator->locate($content, $contentType);
        if (null === $fieldDefinition) {
            return null;
        }

        /** @var \Ibexa\FieldTypePage\FieldType\LandingPage\Value|null $fieldValue */
        $fieldValue = $content->getFieldValue($fieldDefinition->identifier, $languageCode);
        if (null === $fieldValue) {
            return $languageCode;
        }

        $blockTtlValues = [];
        foreach ($fieldValue->getPage()->getZones() as $zone) {
            foreach ($zone->getBlocks() as $blockValue) {
                $blockTtlValues[] = $this->getBlockVisibilityTtl($blockValue, $referenceDate);
                $this->tagHandler->addRelationTags($this->relationCollector->collect($fieldValue, $blockValue));
            }
        }

        if (empty($blockTtlValues)) {
            return $languageCode;
        }

        $ttl = min($blockTtlValues);
        $currentMaxAge = $response->getMaxAge();
        if (null !== $currentMaxAge && $currentMaxAge <= $ttl) {
            return $languageCode;
        }

        $this->logger->info('Corrected page TTL for rendered translation', [
            'uri' => $event->getRequest()->getRequestUri(),
            'content_id' => $content->id,
            'language_code' => $languageCode,
            'previous_max_age' => $currentMaxAge,
            'ttl' => $ttl,
        ]);

        $response->setPublic();
        $response->setSharedMaxAge($ttl);
        $response->setMaxAge($ttl);

        return $languageCode;
    }

    /**
     * Same as TagBlockResponseSubscriber::getBlockVisibilityTtl() (private there).
     */
    private function getBlockVisibilityTtl(BlockValue $blockValue, DateTimeImmutable $referenceDate): int
    {
        $timestamps = [];

        $sinceDate = $blockValue->getSince();
        if (null !== $sinceDate && $sinceDate > $referenceDate) {
            $timestamps[] = $sinceDate->getTimestamp();
        }

        $tillDate = $blockValue->getTill();
        if (null !== $tillDate && $tillDate > $referenceDate) {
            $timestamps[] = $tillDate->getTimestamp();
        }

        if (empty($timestamps)) {
            return (int) $this->configResolver->getParameter('content.default_ttl');
        }

        return min($timestamps) - $referenceDate->getTimestamp();
    }

    /**
     * Only report TTLs that differ from the previous value and from content.default_ttl,
     * i.e. the ones shortened by block since/till scheduling.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function isTtlChanged(array $before, array $after): bool
    {
        $defaultTtl = (int) $this->configResolver->getParameter('content.default_ttl');

        return $after['max_age'] !== $before['max_age']
            && $after['max_age'] !== $defaultTtl;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Response $response): array
    {
        return [
            // max-age/s-maxage as set, unlike getTtl() it does not subtract the response age
            'max_age' => $response->getMaxAge(),
            'cache_control' => $response->headers->get('Cache-Control'),
            'vary' => $response->getVary(),
        ];
    }
}
