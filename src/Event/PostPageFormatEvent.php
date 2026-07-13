<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Event;

use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a landing page is formatted and before the webhook payload is sent.
 * Allows extensions to modify the formatted page data.
 */
class PostPageFormatEvent extends Event
{
    /** @param array<string, mixed> $formattedData */
    public function __construct(
        private readonly LandingPageEntity $landingPage,
        private array $formattedData,
    ) {
    }

    public function getLandingPage(): LandingPageEntity
    {
        return $this->landingPage;
    }

    /** @return array<string, mixed> */
    public function getFormattedData(): array
    {
        return $this->formattedData;
    }

    /** @param array<string, mixed> $formattedData */
    public function setFormattedData(array $formattedData): void
    {
        $this->formattedData = $formattedData;
    }
}
