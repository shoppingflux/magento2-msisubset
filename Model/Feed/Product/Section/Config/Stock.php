<?php

namespace ShoppingFeed\MsiSubset\Model\Feed\Product\Section\Config;

use ShoppingFeed\MsiSubset\Model\Feed\Product\Section\Adapter\Stock as StockAdapter;
use ShoppingFeed\MsiSubset\Model\Feed\Product\Section\Config\Stock as StockConfig;
use ShoppingFeed\MsiSubset\Model\Feed\Product\Stock\QtyResolver;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\StockSourceLinkInterface;
use Magento\InventoryApi\Api\GetStockSourceLinksInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Ui\Component\Form\Element\DataType\Text as UiText;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Model\Config\Field\MultiSelect;
use ShoppingFeed\Manager\Model\Config\FieldFactoryInterface;
use ShoppingFeed\Manager\Model\Config\Value\Handler\Option as OptionHandler;
use ShoppingFeed\Manager\Model\Config\Value\HandlerFactoryInterface as ValueHandlerFactoryInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Section\Config\Stock as BaseConfig;

class Stock extends BaseConfig
{
    const KEY_MAIN_MSI_SOURCE_CODES = 'hs_sfm_main_sources';
    const KEY_ADDITIONAL_MSI_SOURCE_CODES = 'hs_sfm_additional_sources';

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var StockByWebsiteIdResolverInterface
     */
    private $stockByWebsiteIdResolver;

    /**
     * @var GetStockSourceLinksInterface
     */
    private $getStockSourceLinks;

    /**
     * @var SourceRepositoryInterface
     */
    private $sourceRepository;

    /**
     * @param FieldFactoryInterface $fieldFactory
     * @param ValueHandlerFactoryInterface $valueHandlerFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver
     * @param GetStockSourceLinksInterface $getStockSourceLinks
     * @param SourceRepositoryInterface $sourceRepository
     * @param QtyResolver $qtyResolver
     */
    public function __construct(
        FieldFactoryInterface $fieldFactory,
        ValueHandlerFactoryInterface $valueHandlerFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        GetStockSourceLinksInterface $getStockSourceLinks,
        SourceRepositoryInterface $sourceRepository,
        QtyResolver $qtyResolver
    ) {
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->stockByWebsiteIdResolver = $stockByWebsiteIdResolver;
        $this->getStockSourceLinks = $getStockSourceLinks;
        $this->sourceRepository = $sourceRepository;
        parent::__construct($fieldFactory, $valueHandlerFactory, $qtyResolver);
    }


    protected function getStoreFields(StoreInterface $store)
    {
        $fields = parent::getStoreFields($store);

        $msiStock = $this->stockByWebsiteIdResolver->execute($store->getBaseWebsiteId());

        $msiSourceLinks = $this->getStockSourceLinks->execute(
            $this->searchCriteriaBuilder
                ->addFilter(StockSourceLinkInterface::STOCK_ID, (int) $msiStock->getStockId())
                ->create()
        );

        $msiSourceCodes = array_map(
            function (StockSourceLinkInterface $msiSourceLink) {
                return $msiSourceLink->getSourceCode();
            },
            $msiSourceLinks->getItems()
        );

        $msiSources = $this->sourceRepository->getList(
            $this->searchCriteriaBuilder
                ->addFilter(SourceInterface::SOURCE_CODE, $msiSourceCodes, 'in')
                ->create()
        );

        $msiSourceHandler = $this->valueHandlerFactory->create(
            OptionHandler::TYPE_CODE,
            [
                'dataType' => UiText::NAME,
                'optionArray' => array_map(
                    function (SourceInterface $msiSource) {
                        return [
                            'value' => $msiSource->getSourceCode(),
                            'label' => $msiSource->getName() . ' (' . $msiSource->getSourceCode() . ')',
                        ];
                    },
                    $msiSources->getItems()
                ),
            ]
        );

        $fields[] = $this->fieldFactory->create(
            MultiSelect::TYPE_CODE,
            [
                'name' => StockConfig::KEY_MAIN_MSI_SOURCE_CODES,
                'valueHandler' => $msiSourceHandler,
                'allowAll' => true,
                'isRequired' => true,
                'label' => __('Main Stock Sources'),
                'sortOrder' => 1000,
                'notice' => __(
                    'The selected sources will be used for calculating the values of the main stock fields ("quantity" and "is-in-stock").'
                ),
            ]
        );

        $fields[] = $this->fieldFactory->create(
            MultiSelect::TYPE_CODE,
            [
                'name' => StockConfig::KEY_ADDITIONAL_MSI_SOURCE_CODES,
                'valueHandler' => $msiSourceHandler,
                'allowAll' => true,
                'isRequired' => false,
                'label' => __('Export Stock Sources Separately'),
                'sortOrder' => 1010,
                'notice' => implode(
                    "\n",
                    [
                        __(
                            'The quantity and stock status of the products for each of the selected sources will be exported as:'
                        ),
                        '- "' . sprintf(StockAdapter::KEY_PATTERN_SOURCE_QTY, '[source_code]') . '"',
                        '- "' . sprintf(
                            StockAdapter::KEY_PATTERN_SOURCE_IS_IN_STOCK,
                            '[source_code]'
                        ) . '"',
                    ]
                ),
            ]
        );

        return $fields;
    }

    /**
     * @param StoreInterface $store
     * @return string[]|null
     */
    public function getMainMsiSourceCodes(StoreInterface $store)
    {
        return $this->getFieldValue($store, self::KEY_MAIN_MSI_SOURCE_CODES);
    }

    /**
     * @param StoreInterface $store
     * @return string[]|null
     */
    public function getAdditionalMsiSourceCodes(StoreInterface $store)
    {
        return $this->getFieldValue($store, self::KEY_ADDITIONAL_MSI_SOURCE_CODES);
    }
}
