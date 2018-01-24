<?php
/**
 * A Magento 2 module named Experius/ReindexCatalogUrlRewrites
 * Copyright (C) 2018 Experius
 *
 * This file is part of Experius/ReindexCatalogUrlRewrites.
 *
 * Experius/ReindexCatalogUrlRewrites is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Experius\ReindexCatalogUrlRewrites\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\Store\Model\Store;

/**
 * Class RegenerateProductUrlCommand
 * @package Experius\ReindexCatalogUrlRewrites\Console\Command
 */
class RegenerateProductUrlCommand extends Command
{
    const STORE_OPTION = 'store_ids';
    const PRODUCT_OPTION = 'product_ids';

    /**
     * @var \Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator
     */
    protected $productUrlRewriteGenerator;

    /**
     * @var \Magento\UrlRewrite\Model\UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    protected $collection;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator
     */
    protected $urlPathGenerator;

    /**
     * @var null
     */
    protected $productIds = null;

    /**
     * @var array
     */
    protected $storeIds = [];

    /**
     * @var null
     */
    protected $output = null;

    /**
     * RegenerateProductUrlCommand constructor.
     * @param \Magento\Framework\App\State $state
     * @param \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $collection
     * @param \Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator $productUrlRewriteGenerator
     * @param \Magento\UrlRewrite\Model\UrlPersistInterface $urlPersist
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator $urlPathGenerator
     */
    public function __construct(
        \Magento\Framework\App\State $state,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $collection,
        \Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator $productUrlRewriteGenerator,
        \Magento\UrlRewrite\Model\UrlPersistInterface $urlPersist,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator $urlPathGenerator
    ) {
        $this->state = $state;
        $this->collection = $collection;
        $this->productUrlRewriteGenerator = $productUrlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        $this->storeManager = $storeManager;
        $this->urlPathGenerator = $urlPathGenerator;
        parent::__construct();
    }

    /**
     *  Define the Console Command with Options
     */
    protected function configure()
    {
        $this->setName('experius_reindexcatalogurlrewrites:producturls')
            ->setDescription('Regenerate url_rewrites for given products / stores')
            ->addOption(
                self::STORE_OPTION, 's',
                InputOption::VALUE_OPTIONAL,
                'Supply specific Store ID\'s (comma separated)'
            )
            ->addOption(
                self::PRODUCT_OPTION, 'p',
                InputOption::VALUE_OPTIONAL,
                'Supply specific Product ID\'s (comma separated)'
            );

        return parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);

        if ($input->hasOption(self::PRODUCT_OPTION) && $input->getOption(self::PRODUCT_OPTION)) {
            $this->productIds = explode(',', $input->getOption(self::PRODUCT_OPTION));
        }

        if ($input->hasOption(self::STORE_OPTION) && $input->getOption(self::STORE_OPTION)) {
            $this->storeIds = explode(',', $input->getOption(self::STORE_OPTION));
        }

        foreach ($this->getStoreIds() as $storeId) {
            $list = $this->getProductCollection($storeId);
            $this->updateCatalogProductUrlRewriteCollection($list, $storeId);
        }
    }

    /**
     * @param $collection
     * @param $storeId
     */
    protected function updateCatalogProductUrlRewriteCollection($collection, $storeId)
    {
        foreach ($collection as $product) {
            $this->updateCatalogProductUrlRewrite($product, $storeId);
        }
    }

    /**
     * @param $product
     * @param int $storeId
     */
    protected function updateCatalogProductUrlRewrite($product, $storeId = Store::DEFAULT_STORE_ID)
    {
        try {
            $product->setStoreId($storeId);

            $this->output->writeln(sprintf(
                'Updating product entity_id = <info>%s</info> store_id = <info>%s</info> "%s" (%s)',
                $product->getId(),
                $storeId,
                $product->getName(),
                $product->getSku()
            ));

            $this->urlPersist->deleteByData([
                UrlRewrite::ENTITY_ID => $product->getId(),
                UrlRewrite::ENTITY_TYPE => $this->productUrlRewriteGenerator::ENTITY_TYPE,
                UrlRewrite::REDIRECT_TYPE => 0,
                UrlRewrite::STORE_ID => $storeId
            ]);

            if ($product->isVisibleInSiteVisibility()) {
                $productUrls = $this->productUrlRewriteGenerator->generate($product);
                $i = 0;
                foreach ($productUrls as $productUrl) {
                    $i++;
                    $this->output->writeln('  ' . $productUrl->getRequestPath() . ' -> ' . $productUrl->getTargetPath());
                }
                $this->output->writeln('');
                $this->urlPersist->replace(
                    $productUrls
                );
            }
        } catch (\Exception $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');
        }
    }

    /**
     * @param $storeId
     * @return $this
     */
    protected function getProductCollection($storeId)
    {
        /* @var $collection \Magento\Catalog\Model\ResourceModel\Product\Collection */
        $collection = $this->collection->create();

        $collection->addStoreFilter($storeId);
        $collection->setStoreId($storeId);

        if (!empty($this->productIds)) {
            $collection->addIdFilter($this->productIds);
        }

        $collection->addAttributeToSelect(['url_path', 'url_key', 'name', 'visibility']);

        return $collection->load();
    }

    /**
     * @return array
     */
    protected function getStoreIds()
    {
        if (!empty($this->storeIds)) {
            return $this->storeIds;
        }

        $storeIds = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeIds[] = $store->getId();
        }

        return $storeIds;
    }
}
