<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Subscriber;

use Emporiqa\ShopwarePlugin\Event\WidgetParamsEvent;
use Emporiqa\ShopwarePlugin\Service\ChannelResolverInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Subscriber\StorefrontSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class StorefrontSubscriberTest extends TestCase
{
    private ConfigServiceInterface&MockObject $config;
    private ChannelResolverInterface&MockObject $channelResolver;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private StorefrontSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->channelResolver = $this->createMock(ChannelResolverInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->subscriber = new StorefrontSubscriber($this->config, $this->channelResolver, $this->eventDispatcher);
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = StorefrontSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(StorefrontRenderEvent::class, $events);
        $this->assertSame('onStorefrontRender', $events[StorefrontRenderEvent::class]);
    }

    public function testOnStorefrontRenderSkipsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);

        $event = $this->createMock(StorefrontRenderEvent::class);
        $event->expects($this->never())->method('getSalesChannelContext');

        $this->subscriber->onStorefrontRender($event);
    }

    public function testOnStorefrontRenderSkipsWhenStoreIdEmpty(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getStoreId')->willReturn('');

        $salesChannelContext = $this->createSalesChannelContext('channel-1');

        $event = $this->createMock(StorefrontRenderEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->expects($this->never())->method('setParameter');

        $this->subscriber->onStorefrontRender($event);
    }

    public function testOnStorefrontRenderSetsTemplateParameters(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getStoreId')->willReturn('store-abc');
        $this->config->method('getWebhookSecret')->willReturn('secret-xyz');
        $this->config->method('getWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/');
        $this->channelResolver->method('resolveChannelKey')->willReturn('');

        $salesChannelContext = $this->createSalesChannelContext('channel-1');

        $request = new Request();
        $request->setLocale('en_GB');

        $event = $this->createMock(StorefrontRenderEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getRequest')->willReturn($request);

        $event
            ->expects($this->once())
            ->method('setParameter')
            ->with('emporiqaConfig', $this->callback(function (array $config) {
                return $config['storeId'] === 'store-abc'
                    && $config['language'] === 'en_GB'
                    && $config['channel'] === ''
                    && $config['currency'] === 'EUR'
                    && !array_key_exists('userToken', $config)
                    && $config['widgetBaseUrl'] === 'https://emporiqa.com'
                    && $config['cartApiUrl'] === '/emporiqa/api/cart'
                    && $config['userTokenUrl'] === '/emporiqa/api/user-token';
            }));

        $this->subscriber->onStorefrontRender($event);
    }

    public function testOnStorefrontRenderSetsFullLocaleCode(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getStoreId')->willReturn('store-de');
        $this->config->method('getWebhookSecret')->willReturn('secret');
        $this->config->method('getWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/');
        $this->channelResolver->method('resolveChannelKey')->willReturn('');

        $salesChannelContext = $this->createSalesChannelContext('channel-de');

        $request = new Request();
        $request->setLocale('de_DE');

        $event = $this->createMock(StorefrontRenderEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getRequest')->willReturn($request);

        $event
            ->expects($this->once())
            ->method('setParameter')
            ->with('emporiqaConfig', $this->callback(function (array $config) {
                return $config['language'] === 'de_DE';
            }));

        $this->subscriber->onStorefrontRender($event);
    }

    public function testOnStorefrontRenderDoesNotIncludeUserToken(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getStoreId')->willReturn('store-auth');
        $this->config->method('getWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/');
        $this->channelResolver->method('resolveChannelKey')->willReturn('');

        $customer = $this->createMock(CustomerEntity::class);
        $customer->method('getId')->willReturn('customer-id-123');

        $salesChannelContext = $this->createSalesChannelContext('channel-auth', $customer);

        $request = new Request();
        $request->setLocale('en_US');

        $event = $this->createMock(StorefrontRenderEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getRequest')->willReturn($request);

        $event
            ->expects($this->once())
            ->method('setParameter')
            ->with('emporiqaConfig', $this->callback(function (array $config) {
                return !array_key_exists('userToken', $config)
                    && $config['userTokenUrl'] === '/emporiqa/api/user-token';
            }));

        $this->subscriber->onStorefrontRender($event);
    }

    public function testOnStorefrontRenderResolvesChannelFromMapping(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getStoreId')->willReturn('store-ch');
        $this->config->method('getWebhookSecret')->willReturn('secret');
        $this->config->method('getWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/');
        $this->channelResolver->method('resolveChannelKey')->with('channel-ch')->willReturn('retail');

        $salesChannelContext = $this->createSalesChannelContext('channel-ch');

        $request = new Request();
        $request->setLocale('en_GB');

        $event = $this->createMock(StorefrontRenderEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getRequest')->willReturn($request);

        $event
            ->expects($this->once())
            ->method('setParameter')
            ->with('emporiqaConfig', $this->callback(function (array $config) {
                return $config['channel'] === 'retail';
            }));

        $this->subscriber->onStorefrontRender($event);
    }

    public function testOnStorefrontRenderDispatchesWidgetParamsEvent(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getStoreId')->willReturn('store-abc');
        $this->config->method('getWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/');
        $this->channelResolver->method('resolveChannelKey')->willReturn('');

        $salesChannelContext = $this->createSalesChannelContext('channel-1');

        $request = new Request();
        $request->setLocale('en_GB');

        $event = $this->createMock(StorefrontRenderEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getRequest')->willReturn($request);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($dispatchedEvent) use ($salesChannelContext) {
                return $dispatchedEvent instanceof WidgetParamsEvent
                    && $dispatchedEvent->getSalesChannelContext() === $salesChannelContext
                    && $dispatchedEvent->getParams()['storeId'] === 'store-abc';
            }))
            ->willReturnArgument(0);

        $this->subscriber->onStorefrontRender($event);
    }

    public function testOnStorefrontRenderUsesMutatedWidgetParams(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getStoreId')->willReturn('store-abc');
        $this->config->method('getWebhookUrl')->willReturn('https://emporiqa.com/webhooks/sync/');
        $this->channelResolver->method('resolveChannelKey')->willReturn('');

        $salesChannelContext = $this->createSalesChannelContext('channel-1');

        $request = new Request();
        $request->setLocale('en_GB');

        $event = $this->createMock(StorefrontRenderEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getRequest')->willReturn($request);

        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($dispatchedEvent) {
                if ($dispatchedEvent instanceof WidgetParamsEvent) {
                    $params = $dispatchedEvent->getParams();
                    $params['customParam'] = 'custom-value';
                    $dispatchedEvent->setParams($params);
                }

                return $dispatchedEvent;
            });

        $event
            ->expects($this->once())
            ->method('setParameter')
            ->with('emporiqaConfig', $this->callback(function (array $config) {
                return $config['customParam'] === 'custom-value';
            }));

        $this->subscriber->onStorefrontRender($event);
    }

    /**
     * @return SalesChannelContext&MockObject
     */
    private function createSalesChannelContext(string $salesChannelId, ?CustomerEntity $customer = null): SalesChannelContext&MockObject
    {
        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')->willReturn('EUR');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getCurrency')->willReturn($currency);

        return $context;
    }
}
