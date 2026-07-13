<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\LandingPage\LandingPageEntity;

interface CmsPageFormatterInterface
{
    /**
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array<string, mixed>
     */
    public function formatCmsPage(
        CmsPageEntity $cmsPage,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): array;

    /**
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array<string, mixed>
     */
    public function formatLandingPage(
        LandingPageEntity $landingPage,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): array;

    /**
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array<string, mixed>|null
     */
    public function formatShopPage(
        CategoryEntity $category,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formatPageDelete(string $pageId): array;
}
