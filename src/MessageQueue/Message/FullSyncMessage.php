<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\MessageQueue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class FullSyncMessage implements AsyncMessageInterface
{
    /**
     * @param 'products'|'pages'|'all' $entity
     */
    public function __construct(
        private readonly string $entity = 'all',
    ) {
    }

    public function getEntity(): string
    {
        return $this->entity;
    }
}
