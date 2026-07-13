<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Catalog-wide changes (categories, manufacturers, currencies, languages,
 * taxes) invalidate many already-synced product payloads. Rather than
 * auto-resyncing the whole catalog, log an actionable warning telling the
 * merchant to run a full product sync (PrestaShop reference parity).
 */
class CatalogChangeSubscriber implements EventSubscriberInterface, ResetInterface
{
    private const SYNC_HINT = 'Run a full product sync (Admin > Emporiqa > Sync or bin/console emporiqa:sync:products).';

    private const MESSAGES = [
        'category' => '[Emporiqa] Category changed, product category paths may be outdated. ' . self::SYNC_HINT,
        'product_manufacturer' => '[Emporiqa] Manufacturer changed, product brand data may be outdated. ' . self::SYNC_HINT,
        'currency' => '[Emporiqa] Currency changed, product prices may be outdated. ' . self::SYNC_HINT,
        'language' => '[Emporiqa] Language changed, translated product content may be outdated. ' . self::SYNC_HINT,
        'tax' => '[Emporiqa] Tax changed, product prices may be outdated. ' . self::SYNC_HINT,
        'promotion' => '[Emporiqa] Promotion changed, effective product prices may be outdated. ' . self::SYNC_HINT,
        'rule' => '[Emporiqa] Pricing rule changed, effective product prices may be outdated. ' . self::SYNC_HINT,
    ];

    /** @var array<string, true> Entity types already warned about in this request */
    private array $warnedEntities = [];

    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'category.written' => 'onCategoryChanged',
            'category.deleted' => 'onCategoryChanged',
            'product_manufacturer.written' => 'onCatalogEntityChanged',
            'product_manufacturer.deleted' => 'onCatalogEntityChanged',
            'currency.written' => 'onCatalogEntityChanged',
            'currency.deleted' => 'onCatalogEntityChanged',
            'language.written' => 'onCatalogEntityChanged',
            'language.deleted' => 'onCatalogEntityChanged',
            'tax.written' => 'onCatalogEntityChanged',
            'tax.deleted' => 'onCatalogEntityChanged',
            'promotion.written' => 'onCatalogEntityChanged',
            'promotion.deleted' => 'onCatalogEntityChanged',
            'rule.written' => 'onCatalogEntityChanged',
            'rule.deleted' => 'onCatalogEntityChanged',
        ];
    }

    public function onCategoryChanged(EntityWrittenEvent $event): void
    {
        // Page-type categories are synced directly by CategorySubscriber.
        // Renames and deletes don't include `type` in the payload, so the
        // only case we can positively attribute to CategorySubscriber is a
        // payload that explicitly confirms type === 'page', everything
        // else (including an absent type) must warn.
        foreach ($event->getWriteResults() as $result) {
            $type = $result->getPayload()['type'] ?? null;
            if ($type !== 'page') {
                $this->warnOnce('category');

                return;
            }
        }
    }

    public function onCatalogEntityChanged(EntityWrittenEvent $event): void
    {
        $this->warnOnce($event->getEntityName());
    }

    public function reset(): void
    {
        $this->warnedEntities = [];
    }

    private function warnOnce(string $entityName): void
    {
        if (!isset(self::MESSAGES[$entityName]) || isset($this->warnedEntities[$entityName])) {
            return;
        }

        if (!$this->config->isConfigured()) {
            return;
        }

        $this->warnedEntities[$entityName] = true;
        $this->logger->warning(self::MESSAGES[$entityName]);
    }
}
