<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Emporiqa\ShopwarePlugin\Event\WidgetParamsEvent;
use Emporiqa\ShopwarePlugin\Service\ChannelResolverInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class StorefrontSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly ChannelResolverInterface $channelResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender',
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $storeId = $this->config->getStoreId($salesChannelId);

        if ($storeId === '') {
            return;
        }

        $request = $event->getRequest();
        $locale = $request->getLocale();
        $languageCode = $locale;

        // Derive widget URL from webhook URL
        $webhookUrl = $this->config->getWebhookUrl($salesChannelId);
        $parsedUrl = parse_url($webhookUrl);
        if (!\is_array($parsedUrl) || !isset($parsedUrl['host'])) {
            $parsedUrl = ['scheme' => 'https', 'host' => 'emporiqa.com'];
        }
        $widgetBaseUrl = ($parsedUrl['scheme'] ?? 'https') . '://' . $parsedUrl['host'];

        $widgetChannel = $this->channelResolver->resolveChannelKey($salesChannelId);

        // Get current currency
        $currency = $event->getSalesChannelContext()->getCurrency();
        $currencyIso = $currency->getIsoCode();

        $emporiqaConfig = [
            'storeId' => $storeId,
            'language' => $languageCode,
            'channel' => $widgetChannel,
            'currency' => $currencyIso,
            'widgetBaseUrl' => $widgetBaseUrl,
            'cartApiUrl' => '/emporiqa/api/cart',
            'userTokenUrl' => '/emporiqa/api/user-token',
        ];

        $widgetParamsEvent = new WidgetParamsEvent($emporiqaConfig, $event->getSalesChannelContext());
        $this->eventDispatcher->dispatch($widgetParamsEvent);
        $emporiqaConfig = $widgetParamsEvent->getParams();

        $event->setParameter('emporiqaConfig', $emporiqaConfig);
    }
}
