<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Ibexa\Contracts\Core\SiteAccess\ConfigResolverInterface;
use Ibexa\FieldTypePage\Event\BlockResponseEvent;
use Ibexa\FieldTypePage\Event\BlockResponseEvents;
use Ibexa\FieldTypePage\Event\Subscriber\TagBlockResponseSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsDecorator(decorates: TagBlockResponseSubscriber::class)]
final class TagBlockResponseSubscriberDecorator implements EventSubscriberInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly TagBlockResponseSubscriber $inner,
        #[Autowire(service: 'monolog.logger.block_ttl')]
        private readonly LoggerInterface $logger,
        private readonly ConfigResolverInterface $configResolver,
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
        $after = $this->snapshot($event->getResponse());

        if ($this->isTtlChanged($before, $after)) {
            $view = $event->getRequest()->attributes->get('view');
            $this->logger->debug('Page response cache headers changed', [
                'uri' => $event->getRequest()->getRequestUri(),
                'content_id' => is_object($view) && method_exists($view, 'getContent') ? $view->getContent()->id : null,
                'location_id' => is_object($view) && method_exists($view, 'getLocation') ? $view->getLocation()?->id : null,
                'before' => $before,
                'after' => $after,
            ]);
        }
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
