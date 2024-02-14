<?php

namespace ShoppingFeed\MsiSubset\Plugin;

use ShoppingFeed\MsiSubset\Model\Feed\Product\Stock\QtyResolver;
use ShoppingFeed\Feed\Product\AbstractProduct as AbstractExportedProduct;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Section\Adapter\StockInterface as StockSectionAdapterInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Section\Config\StockInterface as StockSectionConfigInterface;
use ShoppingFeed\Manager\Model\Feed\RefreshableProduct;

class AddMsiSourceFieldsToStockFeedData
{
    const FIELD_NAME_PATTERN_SOURCE_QTY = '%s-quantity';
    const FIELD_NAME_PATTERN_SOURCE_IS_IN_STOCK = '%s-is-in-stock';

    /**
     * @var QtyResolver
     */
    private $qtyResolver;

    /**
     * @var StockSectionConfigInterface
     */
    private $stockSectionConfig;

    /**
     * @param QtyResolver $qtyResolver
     * @param StockSectionConfigInterface $stockSectionConfig
     */
    public function __construct(QtyResolver $qtyResolver, StockSectionConfigInterface $stockSectionConfig)
    {
        $this->qtyResolver = $qtyResolver;
        $this->stockSectionConfig = $stockSectionConfig;
    }

    /**
     * @param StockSectionAdapterInterface $subject
     * @param array $result
     * @param StoreInterface $store
     * @param RefreshableProduct $product
     * @return array
     */
    public function afterGetProductData(
        StockSectionAdapterInterface $subject,
        $result,
        StoreInterface $store,
        RefreshableProduct $product
    ) {
        if (is_array($result)) {
            $sourceCodes = $this->stockSectionConfig->getField(
                $store,
                QtyResolver::STOCK_CONFIG_KEY_ADDITIONAL_MSI_SOURCE_CODES
            );

            if (empty($sourceCodes)) {
                return $result;
            }

            $catalogProduct = $product->getCatalogProduct();

            foreach ($sourceCodes as $sourceCode) {
                $sourceData = $this->qtyResolver->getCatalogProductMsiSourceData($catalogProduct, $store, $sourceCode);

                if (is_array($sourceData)) {
                    $result[sprintf(self::FIELD_NAME_PATTERN_SOURCE_QTY, $sourceCode)] = $sourceData[0];
                    $result[sprintf(self::FIELD_NAME_PATTERN_SOURCE_IS_IN_STOCK, $sourceCode)] = $sourceData[1];
                }
            }
        }

        return $result;
    }
    
}
