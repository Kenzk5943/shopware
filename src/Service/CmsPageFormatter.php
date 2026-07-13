<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\LandingPage\LandingPageEntity;

class CmsPageFormatter implements CmsPageFormatterInterface
{
    use TranslationResolverTrait;
    /**
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array<string, mixed>
     */
    public function formatLandingPage(
        LandingPageEntity $landingPage,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): array {
        $channelContexts = $this->filterByAssignedChannels($landingPage, $channelContexts);
        $channelKeys = array_keys($channelContexts);

        $pageUrl = $landingPage->getUrl() ?? '';

        $titles = [];
        $contents = [];
        $links = [];

        foreach ($channelContexts as $channelKey => $ctxList) {
            $channelTitles = [];
            $channelContents = [];
            $channelLinks = [];

            foreach ($ctxList as $ctx) {
                $langCode = $ctx['languageCode'];
                $languageId = $ctx['languageId'] ?? '';
                $domainUrl = $ctx['domainUrl'];

                if (!isset($channelTitles[$langCode])) {
                    // SEO metaTitle takes precedence over the internal page name.
                    $metaTitle = $this->getTranslatedString($landingPage, 'metaTitle', $languageId);
                    $channelTitles[$langCode] = $metaTitle !== ''
                        ? $metaTitle
                        : $this->getTranslatedString($landingPage, 'name', $languageId);

                    $description = '';
                    $cmsPage = $landingPage->getCmsPage();
                    if ($cmsPage !== null) {
                        $slotOverride = $this->getTranslatedSlotConfig($landingPage, $languageId);
                        $description = $this->extractCmsPageContent($cmsPage, $languageId, $slotOverride);
                    }
                    // Pages built purely from image/banner blocks resolve to no
                    // extractable text, fall back to the meta description so
                    // they don't sync with empty content.
                    if ($description === '') {
                        $description = $this->getTranslatedString($landingPage, 'metaDescription', $languageId);
                    }
                    $channelContents[$langCode] = $description;

                    $channelLinks[$langCode] = rtrim($domainUrl, '/') . '/' . ltrim($pageUrl, '/');
                }
            }

            $titles[$channelKey] = $channelTitles;
            $contents[$channelKey] = $channelContents;
            $links[$channelKey] = $channelLinks;
        }

        $data = [
            'identification_number' => 'page-' . $landingPage->getId(),
            'channels' => $channelKeys,
            'titles' => $titles,
            'contents' => $contents,
            'links' => $links,
        ];

        if ($syncSessionId !== null) {
            $data['sync_session_id'] = $syncSessionId;
        }

        return $data;
    }

    /**
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array<string, mixed>
     */
    public function formatCmsPage(
        CmsPageEntity $cmsPage,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): array {
        $channelKeys = array_keys($channelContexts);

        $titles = [];
        $contents = [];
        $links = [];

        foreach ($channelContexts as $channelKey => $ctxList) {
            $channelTitles = [];
            $channelContents = [];
            $channelLinks = [];

            foreach ($ctxList as $ctx) {
                $langCode = $ctx['languageCode'];
                $languageId = $ctx['languageId'] ?? '';
                $domainUrl = $ctx['domainUrl'];

                if (!isset($channelTitles[$langCode])) {
                    $channelTitles[$langCode] = $this->getTranslatedString($cmsPage, 'name', $languageId);
                    $channelContents[$langCode] = $this->extractCmsPageContent($cmsPage, $languageId);
                    $channelLinks[$langCode] = $domainUrl;
                }
            }

            $titles[$channelKey] = $channelTitles;
            $contents[$channelKey] = $channelContents;
            $links[$channelKey] = $channelLinks;
        }

        $data = [
            'identification_number' => 'page-' . $cmsPage->getId(),
            'channels' => $channelKeys,
            'titles' => $titles,
            'contents' => $contents,
            'links' => $links,
        ];

        if ($syncSessionId !== null) {
            $data['sync_session_id'] = $syncSessionId;
        }

        return $data;
    }

    public function formatShopPage(
        CategoryEntity $category,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): ?array {
        $seoUrls = $category->getSeoUrls();
        if ($seoUrls === null || $seoUrls->count() === 0) {
            return null;
        }

        // Build a lookup: salesChannelId → channelKey
        $salesChannelToKey = [];
        foreach ($channelContexts as $channelKey => $ctxList) {
            foreach ($ctxList as $ctx) {
                $salesChannelToKey[$ctx['salesChannelId']] = $channelKey;
            }
        }

        // Build a lookup: salesChannelId+languageCode → domainUrl
        $domainLookup = [];
        foreach ($channelContexts as $ctxList) {
            foreach ($ctxList as $ctx) {
                $domainLookup[$ctx['salesChannelId'] . '|' . $ctx['languageId']] = $ctx['domainUrl'];
            }
        }

        $titles = [];
        $contents = [];
        $links = [];
        $channelKeys = [];

        foreach ($seoUrls as $seoUrl) {
            if ($seoUrl->getIsDeleted() || !$seoUrl->getIsCanonical()) {
                continue;
            }

            $salesChannelId = $seoUrl->getSalesChannelId();
            if ($salesChannelId === null || !isset($salesChannelToKey[$salesChannelId])) {
                continue;
            }

            $channelKey = $salesChannelToKey[$salesChannelId];
            $languageId = $seoUrl->getLanguageId();
            $domainUrl = $domainLookup[$salesChannelId . '|' . $languageId] ?? null;

            if ($domainUrl === null) {
                continue;
            }

            // Find language code for this languageId
            $langCode = null;
            foreach ($channelContexts[$channelKey] ?? [] as $ctx) {
                if ($ctx['languageId'] === $languageId) {
                    $langCode = $ctx['languageCode'];
                    break;
                }
            }
            if ($langCode === null) {
                continue;
            }

            if (!isset($titles[$channelKey])) {
                $channelKeys[] = $channelKey;
                $titles[$channelKey] = [];
                $contents[$channelKey] = [];
                $links[$channelKey] = [];
            }

            if (!isset($titles[$channelKey][$langCode])) {
                // SEO metaTitle takes precedence over the internal category name.
                $metaTitle = $this->getTranslatedString($category, 'metaTitle', $languageId);
                $titles[$channelKey][$langCode] = $metaTitle !== ''
                    ? $metaTitle
                    : $this->getTranslatedString($category, 'name', $languageId);

                $description = '';
                $cmsPage = $category->getCmsPage();
                if ($cmsPage !== null) {
                    $slotOverride = $this->getTranslatedSlotConfig($category, $languageId);
                    $description = $this->extractCmsPageContent($cmsPage, $languageId, $slotOverride);
                }
                if ($description === '') {
                    $description = $this->getTranslatedString($category, 'description', $languageId);
                }
                $contents[$channelKey][$langCode] = $description;

                $seoPath = $seoUrl->getSeoPathInfo();
                $links[$channelKey][$langCode] = rtrim($domainUrl, '/') . '/' . ltrim($seoPath, '/');
            }
        }

        if (empty($channelKeys)) {
            return null;
        }

        $data = [
            'identification_number' => 'page-' . $category->getId(),
            'channels' => array_unique($channelKeys),
            'titles' => $titles,
            'contents' => $contents,
            'links' => $links,
        ];

        if ($syncSessionId !== null) {
            $data['sync_session_id'] = $syncSessionId;
        }

        return $data;
    }

    public function formatPageDelete(string $pageId): array
    {
        return [
            ['identification_number' => 'page-' . $pageId],
        ];
    }

    /**
     * Filter channel contexts to only include channels the landing page is assigned to.
     * If no sales channels are assigned, the page is available on all channels.
     *
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array<string, array<int, array<string, string>>>
     */
    private function filterByAssignedChannels(LandingPageEntity $landingPage, array $channelContexts): array
    {
        $assignedChannels = $landingPage->getSalesChannels();
        if ($assignedChannels === null || $assignedChannels->count() === 0) {
            return $channelContexts;
        }

        $assignedIds = [];
        foreach ($assignedChannels as $sc) {
            $assignedIds[$sc->getId()] = true;
        }

        $filtered = [];
        foreach ($channelContexts as $channelKey => $ctxList) {
            $matchingCtx = [];
            foreach ($ctxList as $ctx) {
                if (isset($assignedIds[$ctx['salesChannelId']])) {
                    $matchingCtx[] = $ctx;
                }
            }
            if (!empty($matchingCtx)) {
                $filtered[$channelKey] = $matchingCtx;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $slotConfigOverride slotId => slot config; the page's per-language slotConfig override
     */
    private function extractCmsPageContent(CmsPageEntity $cmsPage, string $languageId = '', array $slotConfigOverride = []): string
    {
        $sections = $cmsPage->getSections();
        if ($sections === null || $sections->count() === 0) {
            return '';
        }

        $texts = [];

        foreach ($sections as $section) {
            $blocks = $section->getBlocks();
            if ($blocks === null) {
                continue;
            }

            foreach ($blocks as $block) {
                $slots = $block->getSlots();
                if ($slots === null) {
                    continue;
                }

                foreach ($slots as $slot) {
                    $content = '';

                    // The page's own per-language slotConfig override takes precedence
                    // over the shared CMS layout, this is where landing page and
                    // category content typed per language actually lives.
                    if ($slotConfigOverride !== []) {
                        $override = $slotConfigOverride[$slot->getId()] ?? null;
                        if (\is_array($override) && isset($override['content']['value']) && \is_string($override['content']['value'])) {
                            $content = $override['content']['value'];
                        }
                    }

                    // Try language-specific config from the shared layout's slot translation
                    if ($content === '' && $languageId !== '') {
                        $content = $this->getSlotConfigContent($slot, $languageId);
                    }

                    // Fall back to default config
                    if ($content === '') {
                        $config = $slot->getConfig();
                        if (\is_array($config) && isset($config['content']['value']) && \is_string($config['content']['value'])) {
                            $content = $config['content']['value'];
                        }
                    }

                    // Fall back to resolved slot data
                    if ($content === '') {
                        $slotData = $slot->getData();
                        if ($slotData !== null) {
                            $content = $this->extractSlotContent($slotData);
                        }
                    }

                    if ($content !== '') {
                        $texts[] = $content;
                    }
                }
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * Resolve a page's per-language slotConfig override (landing page / category),
     * keyed by slot id. Returns an empty array when no translation matches.
     *
     * @return array<string, mixed>
     */
    private function getTranslatedSlotConfig(object $entity, string $languageId): array
    {
        $translation = $this->findTranslationForLanguage($entity, $languageId);
        if ($translation === null || !method_exists($translation, 'getSlotConfig')) {
            return [];
        }

        $config = $translation->getSlotConfig();

        return \is_array($config) ? $config : [];
    }

    private function getSlotConfigContent(object $slot, string $languageId): string
    {
        $translation = $this->findTranslationForLanguage($slot, $languageId);
        if ($translation === null || !method_exists($translation, 'getConfig')) {
            return '';
        }

        $config = $translation->getConfig();
        if (\is_array($config) && isset($config['content']['value']) && \is_string($config['content']['value'])) {
            return $config['content']['value'];
        }

        return '';
    }

    /**
     * Find the translation entity matching a language id on a translatable entity
     * (landing page, category, or CMS slot). Returns null when none matches.
     */
    private function findTranslationForLanguage(object $entity, string $languageId): ?object
    {
        if ($languageId === '' || !method_exists($entity, 'getTranslations')) {
            return null;
        }

        $translations = $entity->getTranslations();
        if ($translations === null) {
            return null;
        }

        foreach ($translations as $translation) {
            if (method_exists($translation, 'getLanguageId') && $translation->getLanguageId() === $languageId) {
                return $translation;
            }
        }

        return null;
    }

    private function extractSlotContent(object $data): string
    {
        if (method_exists($data, 'getContent')) {
            $content = $data->getContent();
            if (\is_string($content) && $content !== '') {
                return $content;
            }
        }

        if (method_exists($data, 'getText')) {
            $text = $data->getText();
            if (\is_string($text) && $text !== '') {
                return $text;
            }
        }

        return '';
    }
}
