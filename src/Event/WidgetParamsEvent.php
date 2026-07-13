<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Event;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before the storefront widget configuration is exposed to the template.
 * Allows extensions to add or modify the parameters passed to the Emporiqa widget.
 */
class WidgetParamsEvent extends Event
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private array $params,
        private readonly SalesChannelContext $salesChannelContext,
    ) {
    }

    /** @return array<string, mixed> */
    public function getParams(): array
    {
        return $this->params;
    }

    /** @param array<string, mixed> $params */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }
}
