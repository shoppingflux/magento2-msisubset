<?php

namespace ShoppingFeed\MsiSubset\Model\Feed\Product\Section\Adapter;

use ShoppingFeed\MsiSubset\Model\Feed\Product\Section\Config\Stock as SubsetStockConfig;
use ShoppingFeed\MsiSubset\Model\Feed\Product\Stock\QtyResolver;
use Magento\Store\Model\StoreManagerInterface;
use ShoppingFeed\Feed\Product\AbstractProduct as AbstractExportedProduct;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Attribute\Value\RendererPoolInterface as AttributeRendererPoolInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Section\Adapter\Stock as BaseAdapter;
use ShoppingFeed\Manager\Model\Feed\RefreshableProduct;
use ShoppingFeed\Manager\Model\LabelledValueFactory;

class Stock extends BaseAdapter
{
    const KEY_PATTERN_SOURCE_QTY = '%s-quantity';
    const KEY_PATTERN_SOURCE_IS_IN_STOCK = '%s-is-in-stock';

    /**
     * @param StoreManagerInterface $storeManager
     * @param LabelledValueFactory $labelledValueFactory
     * @param AttributeRendererPoolInterface $attributeRendererPool
     * @param QtyResolver $qtyResolver
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        LabelledValueFactory $labelledValueFactory,
        AttributeRendererPoolInterface $attributeRendererPool,
        QtyResolver $qtyResolver
    ) {
        parent::__construct($storeManager, $labelledValueFactory, $attributeRendererPool, $qtyResolver);
    }

    public function getProductData(StoreInterface $store, RefreshableProduct $product)
    {
        $config = $this->getConfig();

        if (($config instanceof SubsetStockConfig) && ($this->qtyResolver instanceof QtyResolver)) {
            $this->qtyResolver->setStoreUsableMsiStockSourceCodes(
                $store,
                $config->getMainMsiSourceCodes($store)
            );

            $stockData = parent::getProductData($store, $product);
            $sourceCodes = $config->getAdditionalMsiSourceCodes($store);

            if (!empty($sourceCodes)) {
                $catalogProduct = $product->getCatalogProduct();

                $sourceStatuses = array_intersect_key(
                    $this->qtyResolver->getCatalogProductMsiSourceStatuses($catalogProduct, $store),
                    array_flip($sourceCodes)
                );

                foreach ($sourceStatuses as $sourceCode => $sourceStatus) {
                    $stockData[sprintf(self::KEY_PATTERN_SOURCE_QTY, $sourceCode)] = $sourceStatus[1];
                    $stockData[sprintf(self::KEY_PATTERN_SOURCE_IS_IN_STOCK, $sourceCode)] = (int) $sourceStatus[0];
                }
            }
        } else {
            $stockData = parent::getProductData($store, $product);
        }

        return $stockData;
    }

    public function exportBaseProductData(
        StoreInterface $store,
        array $productData,
        AbstractExportedProduct $exportedProduct
    ) {
        parent::exportBaseProductData($store, $productData, $exportedProduct);

        $sourceCodes = $this->getConfig()->getAdditionalMsiSourceCodes($store);

        foreach ($sourceCodes as $sourceCode) {
            $qtyKey = sprintf(self::KEY_PATTERN_SOURCE_QTY, $sourceCode);
            $isInStockKey = sprintf(self::KEY_PATTERN_SOURCE_IS_IN_STOCK, $sourceCode);

            if (isset($productData[$qtyKey])) {
                $exportedProduct->setAttribute($qtyKey, $productData[$qtyKey]);
            }

            if (isset($productData[$isInStockKey])) {
                $exportedProduct->setAttribute($isInStockKey, $productData[$isInStockKey]);
            }
        }
    }

    public function describeProductData(StoreInterface $store, array $productData)
    {
        $baseData = parent::describeProductData($store, $productData);
        $sourceLabels = [];

        $qtyKeyPattern = '/' . str_replace('%s', '(.+)', preg_quote(self::KEY_PATTERN_SOURCE_QTY)) . '/';
        $statusKeyPattern = '/' . str_replace('%s', '(.+)', preg_quote(self::KEY_PATTERN_SOURCE_IS_IN_STOCK)) . '/';

        foreach ($productData as $key => $value) {
            if (preg_match($qtyKeyPattern, $key, $matches)) {
                $sourceLabels[$key] = __('Source "%1" - Quantity', $matches[1]);
            } elseif (preg_match($statusKeyPattern, $key, $matches)) {
                $sourceLabels[$key] = __('Source "%1" - Status', $matches[1]);
            }
        }

        return array_merge($baseData, $this->describeRawProductData($sourceLabels, $productData));
    }
}
