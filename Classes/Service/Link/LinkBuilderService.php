<?php
declare(strict_types=1);

namespace Pixelant\PxaProductManager\Service\Link;

use Pixelant\PxaProductManager\Domain\Model\Category;
use Pixelant\PxaProductManager\Domain\Model\Product;
use Pixelant\PxaProductManager\Traits\SignalSlot\DispatcherTrait;
use Pixelant\PxaProductManager\Utility\ProductUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Class LinkBuilderService
 * @package Pixelant\PxaProductManager\Service\Link
 */
class LinkBuilderService
{
    use DispatcherTrait;

    /**
     * Link constants
     */
    const CATEGORY_ARGUMENT_START_WITH = 'category_';
    const NAMESPACES = 'tx_pxaproductmanager_pi1';

    /**
     * Cache category arguments
     *
     * @var array
     */
    protected static $cacheCategories = [];

    /**
     * @var TypoScriptFrontendController
     */
    protected $typoScriptFrontendController = null;

    /**
     * Language uid
     *
     * @var int
     */
    protected $languageUid = 0;

    /**
     * Initialize
     *
     * @param int|null $languageUid Provide language to generate urls
     * @param TypoScriptFrontendController|null $typoScriptFrontendController
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     */
    public function __construct(
        int $languageUid = null,
        TypoScriptFrontendController $typoScriptFrontendController = null
    ) {
        if ($languageUid !== null) {
            $this->languageUid = $languageUid;
        } elseif ($this->isFrontendRequestType()) {
            /** @var LanguageAspect $languageAspect */
            $languageAspect = GeneralUtility::makeInstance(Context::class)->getAspect('language');
            $this->languageUid = $languageAspect->getId();
        }

        $this->typoScriptFrontendController = $typoScriptFrontendController;
    }

    /**
     * Get product single view link
     *
     * @param int $pageUid Page Uid
     * @param int|Product $product Product object or UID
     * @param int|Category $category Category object or UID to override first product category
     * @param bool $excludeCategories Exclude categories from product single view url
     * @param bool $absolute Absolute link
     * @return string
     */
    public function buildForProduct(
        int $pageUid,
        $product,
        $category = null,
        bool $excludeCategories = false,
        bool $absolute = false
    ): string {
        $arguments = [];
        $productUid = is_object($product) ? $product->getUid() : (int)$product;
        if (!$excludeCategories) {
            $categoryUid = $this->getProductCategoryUid($product, $category);
            $arguments = $this->getCategoriesArguments($categoryUid);
        }
        $arguments['product'] = $productUid;

        return $this->buildUri($pageUid, 'show', $arguments, $absolute);
    }

    /**
     * Get link for category list view
     *
     * @param int $pageUid Page Uid
     * @param int|Category $category Category object or UID to generate url for list view
     * @param bool $absolute Absolute link
     * @return string
     */
    public function buildForCategory(
        int $pageUid,
        $category,
        bool $absolute = false
    ): string {
        $categoryUid = is_object($category) ? $category->getUid() : (int)$category;
        $arguments = $this->getCategoriesArguments($categoryUid);

        return $this->buildUri($pageUid, 'list', $arguments, $absolute);
    }

    /**
     * Build link for given arguments (For example from breadcrumbs)
     *
     * @param int $pageUid
     * @param array $arguments
     * @return string
     */
    public function buildForArguments(int $pageUid, array $arguments): string
    {
        $action = isset($arguments['product']) ? 'show' : 'list';

        return $this->buildUri($pageUid, $action, $arguments);
    }

    /**
     * @param int $languageUid
     */
    public function setLanguageUid(int $languageUid): void
    {
        $this->languageUid = $languageUid;
    }

    /**
     * @param int|Product $product
     * @param int|Category $category
     * @return int
     */
    protected function getProductCategoryUid($product, $category): int
    {
        if (is_object($category)) {
            return $category->getUid();
        }
        if ($category !== null) {
            return (int)$category;
        }
        if (is_object($product)) {
            $productCat = $product->getFirstCategory();
            return $productCat !== null ? $productCat->getUid() : 0;
        }

        $categories = ProductUtility::getProductCategoriesUids(intval($product));
        if (count($categories) > 0) {
            return $categories[0];
        }

        return 0;
    }

    /**
     * Generate link
     *
     * @param int $pageUid
     * @param string $action
     * @param array $arguments
     * @param bool $absolute
     * @return string
     */
    protected function buildUri(int $pageUid, string $action, array $arguments, bool $absolute = false): string
    {
        $arguments['action'] = $action;
        $arguments['controller'] = 'Product';

        $parameters = GeneralUtility::implodeArrayForUrl(
            static::NAMESPACES,
            $arguments
        );

        $confLink = [
            'parameter' => $pageUid,
            'language' => $this->languageUid,
            'useCacheHash' => true,
            'additionalParams' => $parameters,
            'forceAbsoluteUrl' => $absolute
        ];

        $signalArguments = [
            'conf' => &$confLink
        ];
        $this->emitSignal(__CLASS__, 'beforeBuildUri', $signalArguments);

        /** @var ContentObjectRenderer $contentObjectRenderer */
        $contentObjectRenderer = GeneralUtility::makeInstance(
            ContentObjectRenderer::class,
            $this->typoScriptFrontendController
        );
        return $contentObjectRenderer->typolink_URL($confLink);
    }

    /**
     * Get category tree arguments
     *
     * @param int $categoryUid
     * @return array
     */
    protected function getCategoriesArguments(int $categoryUid): array
    {
        if ($categoryUid <= 0) {
            return [];
        }

        if (isset(static::$cacheCategories[$categoryUid])) {
            return static::$cacheCategories[$categoryUid];
        }

        $i = 0;
        $categories = [$categoryUid];

        // Get parent
        $parentUid = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_category')
            ->select(
                ['parent'],
                'sys_category',
                ['uid' => $categoryUid]
            )
            ->fetchColumn(0);

        // Recursive get all parents UIDs
        // But exclude root category and make sure that parents doesn't repeat
        while ($parentUid) {
            $i++;
            if ($i > 50) {
                throw new \RuntimeException('Rich maximum recursive level', 1555924319262);
            }

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('sys_category');
            $expr = $queryBuilder->expr();

            $parentRow = $queryBuilder
                ->select('uid', 'parent', 'pxapm_nav_hide')
                ->from('sys_category')
                ->where(
                    $expr->eq('uid', $queryBuilder->createNamedParameter($parentUid, \PDO::PARAM_INT)),
                    $expr->neq('parent', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $expr->notIn('uid', $queryBuilder->createNamedParameter($categories, Connection::PARAM_INT_ARRAY))
                )
                ->execute()
                ->fetch();
            // Save result
            $parentUid = is_array($parentRow) ? $parentRow['parent'] : 0;
            if ($parentUid !== 0 && !$parentRow['pxapm_nav_hide']) {
                $categories[] = $parentRow['uid'];
            }
        }

        $arguments = [];
        $i = 0;
        foreach (array_reverse($categories) as $category) {
            $arguments[static::CATEGORY_ARGUMENT_START_WITH . $i++] = $category;
        }

        static::$cacheCategories[$categoryUid] = $arguments;

        return $arguments;
    }

    /**
     * Check if FE request
     *
     * @return bool
     */
    protected function isFrontendRequestType(): bool
    {
        if (!defined('TYPO3_REQUESTTYPE')) {
            return false;
        }

        return (bool)(TYPO3_REQUESTTYPE & TYPO3_REQUESTTYPE_FE);
    }
}
