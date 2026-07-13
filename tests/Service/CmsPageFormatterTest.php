<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Service;

use Emporiqa\ShopwarePlugin\Service\CmsPageFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsBlock\CmsBlockCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsBlock\CmsBlockEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSection\CmsSectionCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsSection\CmsSectionEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\LandingPage\Aggregate\LandingPageTranslation\LandingPageTranslationCollection;
use Shopware\Core\Content\LandingPage\Aggregate\LandingPageTranslation\LandingPageTranslationEntity;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlCollection;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;

class CmsPageFormatterTest extends TestCase
{
    private CmsPageFormatter $formatter;

    /** @var array<string, array<int, array<string, string>>> */
    private array $defaultChannelContexts;

    protected function setUp(): void
    {
        $this->formatter = new CmsPageFormatter();

        $this->defaultChannelContexts = [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
            ],
        ];
    }

    public function testFormatLandingPageBasicFormatting(): void
    {
        $landingPage = $this->createLandingPageMock('lp-001', 'Summer Sale', 'summer-sale');

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('page-lp-001', $result['identification_number']);
        $this->assertSame([''], $result['channels']);
        $this->assertSame(['' => ['en' => 'Summer Sale']], $result['titles']);
        $this->assertSame(['' => ['en' => 'https://shop.example.com/summer-sale']], $result['links']);
        $this->assertSame(['' => ['en' => '']], $result['contents']);
        $this->assertArrayNotHasKey('sync_session_id', $result);
    }

    public function testFormatLandingPageWithSyncSessionId(): void
    {
        $landingPage = $this->createLandingPageMock('lp-002', 'Winter Sale', 'winter-sale');

        $result = $this->formatter->formatLandingPage(
            $landingPage,
            $this->defaultChannelContexts,
            'sync-session-xyz',
        );

        $this->assertSame('page-lp-002', $result['identification_number']);
        $this->assertSame('sync-session-xyz', $result['sync_session_id']);
    }

    public function testFormatLandingPageUrlHandlesLeadingSlash(): void
    {
        $landingPage = $this->createLandingPageMock('lp-003', 'Page', '/offers/spring');

        $result = $this->formatter->formatLandingPage($landingPage, [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.example.com/', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
            ],
        ]);

        $this->assertSame('https://shop.example.com/offers/spring', $result['links']['']['en']);
    }

    public function testFormatLandingPageWithEmptyUrl(): void
    {
        $landingPage = $this->createLandingPageMock('lp-004', 'Empty URL Page', '');

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('https://shop.example.com/', $result['links']['']['en']);
    }

    public function testFormatLandingPageWithNullUrl(): void
    {
        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('getId')->willReturn('lp-005');
        $landingPage->method('getTranslation')->willReturnCallback(fn(string $f) => match ($f) {
            'name' => 'Null URL',
            default => null,
        });
        $landingPage->method('getName')->willReturn('Null URL');
        $landingPage->method('getUrl')->willReturn(null);
        $landingPage->method('getCmsPage')->willReturn(null);
        $landingPage->method('getTranslations')->willReturn(null);

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('https://shop.example.com/', $result['links']['']['en']);
    }

    public function testFormatLandingPageFallbackName(): void
    {
        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('getId')->willReturn('lp-006');
        $landingPage->method('getTranslation')->willReturn(null);
        $landingPage->method('getName')->willReturn('Fallback Name');
        $landingPage->method('getUrl')->willReturn('page');
        $landingPage->method('getCmsPage')->willReturn(null);
        $landingPage->method('getTranslations')->willReturn(null);

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('Fallback Name', $result['titles']['']['en']);
    }

    public function testFormatLandingPageUsesPerLanguageSlotConfigOverride(): void
    {
        // A landing page's editable text lives in its own translatable slotConfig
        // override (keyed by slot id), not in the shared CMS layout. The formatter
        // must resolve it per language so en/de content differs.
        $slot = $this->createMock(CmsSlotEntity::class);
        $slot->method('getId')->willReturn('slot-1');
        $slot->method('getConfig')->willReturn(null);
        $slot->method('getData')->willReturn(null);
        $slot->method('getTranslations')->willReturn(null);

        $block = $this->createMock(CmsBlockEntity::class);
        $block->method('getSlots')->willReturn(new CmsSlotCollection([$slot]));

        $section = $this->createMock(CmsSectionEntity::class);
        $section->method('getBlocks')->willReturn(new CmsBlockCollection([$block]));

        $cmsPage = $this->createMock(CmsPageEntity::class);
        $cmsPage->method('getSections')->willReturn(new CmsSectionCollection([$section]));

        $transEn = $this->createMock(LandingPageTranslationEntity::class);
        $transEn->method('getUniqueIdentifier')->willReturn('t-en');
        $transEn->method('getLanguageId')->willReturn('lang-en');
        $transEn->method('getSlotConfig')->willReturn(['slot-1' => ['content' => ['value' => 'English body', 'source' => 'static']]]);

        $transDe = $this->createMock(LandingPageTranslationEntity::class);
        $transDe->method('getUniqueIdentifier')->willReturn('t-de');
        $transDe->method('getLanguageId')->willReturn('lang-de');
        $transDe->method('getSlotConfig')->willReturn(['slot-1' => ['content' => ['value' => 'German body', 'source' => 'static']]]);

        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('getId')->willReturn('lp-slot');
        $landingPage->method('getUrl')->willReturn('slot-page');
        $landingPage->method('getName')->willReturn('Slot Page');
        $landingPage->method('getTranslation')->willReturnCallback(fn(string $f) => $f === 'name' ? 'Slot Page' : null);
        $landingPage->method('getCmsPage')->willReturn($cmsPage);
        $landingPage->method('getTranslations')->willReturn(new LandingPageTranslationCollection([$transEn, $transDe]));

        $channelContexts = [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
                ['languageCode' => 'de', 'domainUrl' => 'https://shop.de', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-de'],
            ],
        ];

        $result = $this->formatter->formatLandingPage($landingPage, $channelContexts);

        $this->assertSame('English body', $result['contents']['']['en']);
        $this->assertSame('German body', $result['contents']['']['de']);
    }

    public function testFormatPageDeleteReturnsSingleEntry(): void
    {
        $result = $this->formatter->formatPageDelete('page-del-001');

        $this->assertCount(1, $result);
        $this->assertSame('page-page-del-001', $result[0]['identification_number']);
        $this->assertArrayNotHasKey('language', $result[0]);
    }

    public function testFormatLandingPageMultiLanguage(): void
    {
        $landingPage = $this->createLandingPageMock('lp-multi', 'Multi Page', 'multi-page');

        $channelContexts = [
            '' => [
                ['languageCode' => 'en', 'domainUrl' => 'https://shop.com', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-en'],
                ['languageCode' => 'de', 'domainUrl' => 'https://shop.de', 'currencyIso' => 'EUR', 'currencyId' => 'curr-eur', 'salesChannelId' => 'sc-1', 'languageId' => 'lang-de'],
            ],
        ];

        $result = $this->formatter->formatLandingPage($landingPage, $channelContexts);

        $this->assertArrayHasKey('en', $result['titles']['']);
        $this->assertArrayHasKey('de', $result['titles']['']);
        $this->assertSame('https://shop.com/multi-page', $result['links']['']['en']);
        $this->assertSame('https://shop.de/multi-page', $result['links']['']['de']);
    }

    public function testFormatLandingPagePrefersMetaTitleOverName(): void
    {
        $landingPage = $this->createLandingPageMock('lp-meta', 'Internal Name', 'meta-page', metaTitle: 'SEO Meta Title');

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('SEO Meta Title', $result['titles']['']['en']);
    }

    public function testFormatLandingPageFallsBackToNameWhenMetaTitleEmpty(): void
    {
        $landingPage = $this->createLandingPageMock('lp-no-meta', 'Internal Name', 'no-meta-page');

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('Internal Name', $result['titles']['']['en']);
    }

    public function testFormatLandingPageFallsBackToMetaDescriptionWhenContentEmpty(): void
    {
        $landingPage = $this->createLandingPageMock(
            'lp-meta-desc',
            'Banner Page',
            'banner-page',
            metaDescription: 'A page built purely from banners.',
        );

        $result = $this->formatter->formatLandingPage($landingPage, $this->defaultChannelContexts);

        $this->assertSame('A page built purely from banners.', $result['contents']['']['en']);
    }

    public function testFormatShopPagePrefersMetaTitleOverName(): void
    {
        $seoUrl = $this->createMock(SeoUrlEntity::class);
        $seoUrl->method('getUniqueIdentifier')->willReturn('seo-1');
        $seoUrl->method('getIsDeleted')->willReturn(false);
        $seoUrl->method('getIsCanonical')->willReturn(true);
        $seoUrl->method('getSalesChannelId')->willReturn('sc-1');
        $seoUrl->method('getLanguageId')->willReturn('lang-en');
        $seoUrl->method('getSeoPathInfo')->willReturn('shop-category');

        $category = $this->createMock(CategoryEntity::class);
        $category->method('getId')->willReturn('cat-meta');
        $category->method('getSeoUrls')->willReturn(new SeoUrlCollection([$seoUrl]));
        $category->method('getCmsPage')->willReturn(null);
        $category->method('getTranslations')->willReturn(null);
        $category->method('getTranslation')->willReturnCallback(fn(string $field) => match ($field) {
            'name' => 'Internal Category Name',
            'metaTitle' => 'SEO Category Title',
            default => null,
        });
        $category->method('getName')->willReturn('Internal Category Name');

        $result = $this->formatter->formatShopPage($category, $this->defaultChannelContexts);

        $this->assertNotNull($result);
        $this->assertSame('SEO Category Title', $result['titles']['']['en']);
    }

    /**
     * @return LandingPageEntity&MockObject
     */
    private function createLandingPageMock(
        string $id,
        string $name,
        string $url,
        string $metaTitle = '',
        string $metaDescription = '',
    ): LandingPageEntity&MockObject {
        $landingPage = $this->createMock(LandingPageEntity::class);
        $landingPage->method('getId')->willReturn($id);
        $landingPage->method('getTranslation')->willReturnCallback(fn(string $field) => match ($field) {
            'name' => $name,
            'metaTitle' => $metaTitle !== '' ? $metaTitle : null,
            'metaDescription' => $metaDescription !== '' ? $metaDescription : null,
            default => null,
        });
        $landingPage->method('getName')->willReturn($name);
        $landingPage->method('getUrl')->willReturn($url);
        $landingPage->method('getCmsPage')->willReturn(null);
        $landingPage->method('getTranslations')->willReturn(null);

        return $landingPage;
    }
}
