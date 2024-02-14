<?php

namespace ShoppingFeed\MsiSubset\Model\Feed\Product\Stock;

use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\CatalogInventory\Model\Stock\Item as StockItem;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\GetStockSourceLinksInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Exception\SkuIsNotAssignedToStockException;
use Magento\InventoryReservationsApi\Model\GetReservationsQuantityInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\Website as BaseWebsite;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Stock\QtyResolverInterface;

class QtyResolver implements QtyResolverInterface
{
    const MSI_REQUIRED_MODULE_NAMES = [
        'Magento_InventoryConfiguration',
        'Magento_InventoryConfigurationApi',
        'Magento_InventorySales',
        'Magento_InventorySalesApi',
        'Magento_InventoryReservationsApi',
    ];

    const PRODUCT_DATA_KEY_MSI_STOCK_SOURCE_DATA = '__sfm_msi_stock_source_data__';

    /**
     * @var ModuleManager
     */
    private $moduleManager;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var StockRegistryInterface $stockRegistry
     */
    private $stockRegistry;

    /**
     * @var bool|null
     */
    private $isMsiRequiredModulesEnabled = null;

    /**
     * @var StockResolverInterface|false|null
     */
    private $msiStockResolver = null;

    /**
     * @var GetStockItemConfigurationInterface|false|null
     */
    private $msiGetStockItemConfigurationCommand = null;

    /**
     * @var GetStockSourceLinksInterface|false|null
     */
    private $msiGetStockSourceLinksCommand = null;

    /**
     * @var GetSourceItemsBySkuInterface|false|null
     */
    private $msiGetSourceItemsBySkuCommand = null;

    /**
     * @var GetReservationsQuantityInterface|false|null
     */
    private $msiGetReservationsQuantityCommand = null;

    /**
     * @var (int|false)[]
     */
    private $msiWebsiteStockIds = [];

    /**
     * @var string[][]
     */
    private $msiStockSourceCodes = [];

    /**
     * @var (string[]|null)[]
     */
    private $msiStoreUsableStockSourceCodes = [];

    /**
     * @param ModuleManager $moduleManager
     * @param ObjectManagerInterface $objectManager
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(
        ModuleManager $moduleManager,
        ObjectManagerInterface $objectManager,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        StockRegistryInterface $stockRegistry
    ) {
        $this->moduleManager = $moduleManager;
        $this->objectManager = $objectManager;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->stockRegistry = $stockRegistry;
    }

    /**
     * @return bool
     */
    private function isMsiRequiredModulesEnabled()
    {
        if (null === $this->isMsiRequiredModulesEnabled) {
            $this->isMsiRequiredModulesEnabled = true;

            foreach (static::MSI_REQUIRED_MODULE_NAMES as $moduleName) {
                if (!$this->moduleManager->isEnabled($moduleName)) {
                    $this->isMsiRequiredModulesEnabled = false;
                    break;
                }
            }
        }

        return $this->isMsiRequiredModulesEnabled;
    }

    public function isUsingMsi()
    {
        return $this->isMsiRequiredModulesEnabled();
    }

    /**
     * @return StockResolverInterface|null
     */
    private function getMsiStockResolver()
    {
        if (null === $this->msiStockResolver) {
            if (
                $this->isMsiRequiredModulesEnabled()
                && interface_exists('Magento\InventorySalesApi\Api\StockResolverInterface')
            ) {
                try {
                    $this->msiStockResolver = $this->objectManager->create(StockResolverInterface::class);
                } catch (\Exception $e) {
                    $this->msiStockResolver = false;
                }
            } else {
                $this->msiStockResolver = false;
            }
        }

        return is_object($this->msiStockResolver) ? $this->msiStockResolver : null;
    }

    /**
     * @return GetStockItemConfigurationInterface|null
     */
    private function getMsiGetStockItemConfigurationCommand()
    {
        if (null === $this->msiGetStockItemConfigurationCommand) {
            if (
                $this->isMsiRequiredModulesEnabled()
                && interface_exists('Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface')
            ) {
                try {
                    $this->msiGetStockItemConfigurationCommand = $this->objectManager->create(
                        GetStockItemConfigurationInterface::class
                    );
                } catch (\Exception $e) {
                    $this->msiGetStockItemConfigurationCommand = false;
                }
            } else {
                $this->msiGetStockItemConfigurationCommand = false;
            }
        }

        return !is_object($this->msiGetStockItemConfigurationCommand)
            ? null
            : $this->msiGetStockItemConfigurationCommand;
    }

    /**
     * @return GetStockSourceLinksInterface|null
     */
    private function getMsiGetStockSourceLinksCommand()
    {
        if (null === $this->msiGetStockSourceLinksCommand) {
            if (
                $this->isMsiRequiredModulesEnabled()
                && interface_exists('Magento\InventoryApi\Api\GetStockSourceLinksInterface')
            ) {
                try {
                    $this->msiGetStockSourceLinksCommand = $this->objectManager->create(
                        GetStockSourceLinksInterface::class
                    );
                } catch (\Exception $e) {
                    $this->msiGetStockSourceLinksCommand = false;
                }
            } else {
                $this->msiGetStockSourceLinksCommand = false;
            }
        }

        return is_object($this->msiGetStockSourceLinksCommand) ? $this->msiGetStockSourceLinksCommand : null;
    }

    /**
     * @return GetSourceItemsBySkuInterface|null
     */
    private function getMsiGetSourceItemsBySkuCommand()
    {
        if (null === $this->msiGetSourceItemsBySkuCommand) {
            if (
                $this->isMsiRequiredModulesEnabled()
                && interface_exists('Magento\InventoryApi\Api\GetSourceItemsBySkuInterface')
            ) {
                try {
                    $this->msiGetSourceItemsBySkuCommand = $this->objectManager->create(
                        GetSourceItemsBySkuInterface::class
                    );
                } catch (\Exception $e) {
                    $this->msiGetSourceItemsBySkuCommand = false;
                }
            } else {
                $this->msiGetSourceItemsBySkuCommand = false;
            }
        }

        return is_object($this->msiGetSourceItemsBySkuCommand) ? $this->msiGetSourceItemsBySkuCommand : null;
    }

    /**
     * @return GetReservationsQuantityInterface|null
     */
    private function getMsiGetReservationsQuantityCommand()
    {
        if (null === $this->msiGetReservationsQuantityCommand) {
            if (
                $this->isMsiRequiredModulesEnabled()
                && interface_exists('Magento\InventoryReservationsApi\Model\GetReservationsQuantityInterface')
            ) {
                try {
                    $this->msiGetReservationsQuantityCommand = $this->objectManager->create(
                        GetReservationsQuantityInterface::class
                    );
                } catch (\Exception $e) {
                    $this->msiGetReservationsQuantityCommand = false;
                }
            } else {
                $this->msiGetReservationsQuantityCommand = false;
            }
        }

        return is_object($this->msiGetReservationsQuantityCommand) ? $this->msiGetReservationsQuantityCommand : null;
    }

    /**
     * @param StockResolverInterface $stockResolver
     * @param BaseWebsite $website
     * @return int
     * @throws NoSuchEntityException
     */
    private function getMsiWebsiteStockId(StockResolverInterface $stockResolver, BaseWebsite $website)
    {
        $websiteId = (int) $website->getId();

        if (!isset($this->msiWebsiteStockIds[$websiteId])) {
            try {
                $stock = $stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $website->getCode());
                $this->msiWebsiteStockIds[$websiteId] = (int) $stock->getId();
            } catch (NoSuchEntityException $e) {
                $this->msiWebsiteStockIds[$websiteId] = false;
            }
        }

        if (false === $this->msiWebsiteStockIds[$websiteId]) {
            throw new NoSuchEntityException(__('No linked stock found'));
        }

        return $this->msiWebsiteStockIds[$websiteId];
    }

    /**
     * @param int $stockId
     * @return string[]
     */
    private function getMsiStockAllSourceCodes($stockId)
    {
        if (!isset($this->msiStockSourceCodes[$stockId])) {
            $getStockSourceLinksCommand = $this->getMsiGetStockSourceLinksCommand();

            if (null !== $getStockSourceLinksCommand) {
                $this->searchCriteriaBuilder->addFilter(StockSourceLinkInterface::STOCK_ID, $stockId);
                $sourceLinksSearchCriteria = $this->searchCriteriaBuilder->create();
                $sourceLinksResult = $getStockSourceLinksCommand->execute($sourceLinksSearchCriteria);

                $this->msiStockSourceCodes[$stockId] = array_map(
                    function (StockSourceLinkInterface $sourceLink) {
                        return $sourceLink->getSourceCode();
                    },
                    $sourceLinksResult->getItems()
                );
            } else {
                $this->msiStockSourceCodes[$stockId] = [];
            }
        }

        return $this->msiStockSourceCodes[$stockId];
    }

    /**
     * @param string[]|null $codes
     * @return void
     */
    public function setStoreUsableMsiStockSourceCodes(StoreInterface $store, $codes = null)
    {
        $this->msiStoreUsableStockSourceCodes[$store->getId()] = is_array($codes) ? $codes : false;
    }

    /**
     * @param GetSourceItemsBySkuInterface $getSourceItemsBySkuCommand
     * @param int $stockId
     * @param CatalogProduct $product
     * @return array
     */
    private function getCatalogProductMsiStockSourceData(
        GetSourceItemsBySkuInterface $getSourceItemsBySkuCommand,
        $stockId,
        CatalogProduct $product
    ) {
        $stockSourceData = $product->getData(static::PRODUCT_DATA_KEY_MSI_STOCK_SOURCE_DATA);

        if (is_array($stockSourceData)) {
            if (isset($stockSourceData[$stockId])) {
                return $stockSourceData[$stockId];
            }
        } else {
            $stockSourceData = [];
        }

        $stockSourceCodes = $this->getMsiStockAllSourceCodes($stockId);
        $skuSourceItems = $getSourceItemsBySkuCommand->execute($product->getSku());

        foreach ($skuSourceItems as $sourceItem) {
            $sourceCode = $sourceItem->getSourceCode();

            if (in_array($sourceCode, $stockSourceCodes, true)) {
                $isInStock = SourceItemInterface::STATUS_IN_STOCK === (int) $sourceItem->getStatus();

                $stockSourceData[$stockId][$sourceCode] = [
                    $isInStock,
                    $isInStock ? $sourceItem->getQuantity() : 0,
                ];
            }
        }

        return $stockSourceData[$stockId];
    }

    /**
     * @param CatalogProduct $product
     * @param StoreInterface $store
     * @param string $msiQuantityType
     * @return array|false|null
     */
    private function getCatalogProductMsiStockData(
        CatalogProduct $product,
        StoreInterface $store,
        $msiQuantityType
    ) {
        $storeId = (int) $store->getId();
        $stockData = false;
        $stockResolver = $this->getMsiStockResolver();
        $getStockItemConfigurationCommand = $this->getMsiGetStockItemConfigurationCommand();
        $getSourceItemsBySkuCommand = $this->getMsiGetSourceItemsBySkuCommand();
        $getReservationsQuantityCommand = $this->getMsiGetReservationsQuantityCommand();

        if (
            (null !== $stockResolver)
            && (null !== $getStockItemConfigurationCommand)
            && (null !== $getSourceItemsBySkuCommand)
            && (null !== $getReservationsQuantityCommand)
        ) {
            try {
                $sku = $product->getSku();
                $stockId = $this->getMsiWebsiteStockId($stockResolver, $store->getBaseWebsite());
                $stockItemConfiguration = $getStockItemConfigurationCommand->execute($sku, $stockId);

                if ($stockItemConfiguration->isManageStock()) {
                    $stockSourceData = $this->getCatalogProductMsiStockSourceData(
                        $getSourceItemsBySkuCommand,
                        $stockId,
                        $product
                    );

                    $stockQuantity = 0;

                    foreach ($stockSourceData as $sourceCode => $sourceData) {
                        if (
                            !is_array($this->msiStoreUsableStockSourceCodes[$storeId])
                            || in_array($sourceCode, $this->msiStoreUsableStockSourceCodes[$storeId], true)
                        ) {
                            $stockQuantity += $sourceData[1];
                        }
                    }

                    if (static::MSI_QUANTITY_TYPE_STOCK !== $msiQuantityType) {
                        $salableQuantity = $stockQuantity
                            - $stockItemConfiguration->getMinQty()
                            - $getReservationsQuantityCommand->execute($sku, $stockId);
                    } else {
                        $salableQuantity = $stockQuantity;
                    }

                    switch ($msiQuantityType) {
                        case static::MSI_QUANTITY_TYPE_SALABLE:
                            $quantity = $salableQuantity;
                            break;
                        case static::MSI_QUANTITY_TYPE_MAXIMUM:
                            $quantity = max($salableQuantity, $stockQuantity);
                            break;
                        case static::MSI_QUANTITY_TYPE_MINIMUM:
                            $quantity = min($salableQuantity, $stockQuantity);
                            break;
                        default:
                            $quantity = $stockQuantity;
                            break;
                    }

                    $stockData = [ $quantity > 0, $quantity ];
                } else {
                    $stockData = null;
                }
            } catch (SkuIsNotAssignedToStockException $e) {
                $stockData = [ false, 0.0 ];
            } catch (\Exception $e) {
                $stockData = false;
            }
        }

        return $stockData;
    }

    public function getCatalogProductQuantity(CatalogProduct $product, StoreInterface $store, $msiQuantityType)
    {
        $stockData = $this->getCatalogProductMsiStockData($product, $store, $msiQuantityType);

        if (false === $stockData) {
            $stockItem = $this->stockRegistry->getStockItem($product->getId(), $store->getBaseWebsiteId());

            if ($stockItem instanceof StockItem) {
                // Ensure that the right system configuration values will be used.
                $stockItem->setStoreId($store->getBaseStoreId());
            }

            if ($stockItem->getManageStock()) {
                $quantity = $stockItem->getQty();
            } else {
                $quantity = null;
            }
        } elseif (null === $stockData) {
            $quantity = null;
        } else {
            $quantity = $stockData[1];
        }

        return $quantity;
    }

    public function isCatalogProductInStock(CatalogProduct $product, StoreInterface $store, $msiQuantityType)
    {
        $stockData = $this->getCatalogProductMsiStockData($product, $store, $msiQuantityType);

        if (false === $stockData) {
            $stockItem = $this->stockRegistry->getStockItem($product->getId(), $store->getBaseWebsiteId());

            if ($stockItem instanceof StockItem) {
                $stockItem->setStoreId($store->getBaseStoreId());
            }

            if ($stockItem->getManageStock()) {
                $isInStock = $stockItem->getIsInStock();
            } else {
                $isInStock = true;
            }
        } elseif (null === $stockData) {
            $isInStock = true;
        } else {
            $isInStock = $stockData[0];
        }

        return $isInStock;
    }

    public function isCatalogProductBackorderable(CatalogProduct $product, StoreInterface $store)
    {
        $stockItem = $this->stockRegistry->getStockItem($product->getId(), $store->getBaseWebsiteId());

        return in_array(
            (int) $stockItem->getBackorders(),
            [
                Stock::BACKORDERS_YES_NONOTIFY,
                Stock::BACKORDERS_YES_NOTIFY,
            ],
            true
        );
    }

    /**
     * @param CatalogProduct $product
     * @param StoreInterface $store
     * @return array
     */
    public function getCatalogProductMsiSourceStatuses(CatalogProduct $product, StoreInterface $store)
    {
        $sourceStatuses = [];
        $stockResolver = $this->getMsiStockResolver();
        $getSourceItemsBySkuCommand = $this->getMsiGetSourceItemsBySkuCommand();

        if ((null !== $stockResolver) && (null !== $getSourceItemsBySkuCommand)) {
            try {
                $stockId = $this->getMsiWebsiteStockId($stockResolver, $store->getBaseWebsite());

                $sourceStatuses = $this->getCatalogProductMsiStockSourceData(
                    $getSourceItemsBySkuCommand,
                    $stockId,
                    $product
                );
            } catch (\Exception $e) {
                $sourceStatuses = [];
            }
        }

        return $sourceStatuses;
    }
}
