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
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Indexer\Category\Flat\State as CategoryIndexer;
use Magento\CatalogUrlRewrite\Block\UrlKeyRenderer;
use Magento\Store\Model\ScopeInterface;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;

/**
 * Class RegenerateCategoryUrlCommand
 * @package Experius\ReindexCatalogUrlRewrites\Console\Command
 */
class RegenerateCategoryUrlCommand extends Command
{
    const STORE_OPTION = 'store_ids';
    const CATEGORY_OPTION = 'category_ids';

    /**
     * @var \Magento\UrlRewrite\Model\UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
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
     * @var null
     */
    protected $categoryIds = null;

    /**
     * @var array
     */
    protected $storeIds = [];

    /**
     * @var null
     */
    protected $output = null;

    /**
     * @var \Magento\Framework\Event\Manager
     */
    protected $eventManager;

    /**
     * @var \Magento\Store\Model\App\Emulation
     */
    protected $appEmulation;

    /**
     * @var \Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator
     */
    protected $categoryUrlRewriteGenerator;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\CatalogUrlRewrite\Observer\UrlRewriteHandler
     */
    protected $urlRewriteHandler;

    /** @var CategoryUrlPathGenerator */
    protected $categoryUrlPathGenerator;

    /**
     * @var CategoryIndexer
     */
    protected $flatState;

    /**
     * @var \Magento\Framework\Indexer\IndexerRegistry
     */
    protected $indexerRegistry;

    /**
     * RegenerateCategoryUrlCommand constructor.
     * @param \Magento\Framework\App\State $state
     * @param \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $collection
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Event\Manager $eventManager
     * @param \Magento\Store\Model\App\Emulation $appEmulation
     * @param \Magento\UrlRewrite\Model\UrlPersistInterface $urlPersist
     * @param \Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\CatalogUrlRewrite\Observer\UrlRewriteHandler $urlRewriteHandler
     * @param CategoryUrlPathGenerator $categoryUrlPathGenerator
     * @param CategoryIndexer $flatState
     * @param \Magento\Framework\Indexer\IndexerRegistry $indexerRegistry
     */
    public function __construct(
        \Magento\Framework\App\State $state,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $collection,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Event\Manager $eventManager,
        \Magento\Store\Model\App\Emulation\Proxy $appEmulation,
        \Magento\UrlRewrite\Model\UrlPersistInterface $urlPersist,
        \Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\CatalogUrlRewrite\Observer\UrlRewriteHandler\Proxy $urlRewriteHandler,
        CategoryUrlPathGenerator $categoryUrlPathGenerator,
        CategoryIndexer $flatState,
        \Magento\Framework\Indexer\IndexerRegistry $indexerRegistry
    ) {

        $this->state = $state;
        $this->collection = $collection;
        $this->storeManager = $storeManager;
        $this->eventManager = $eventManager;
        $this->appEmulation = $appEmulation;
        $this->urlPersist = $urlPersist;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->scopeConfig = $scopeConfig;
        $this->urlRewriteHandler = $urlRewriteHandler;
        $this->categoryUrlPathGenerator = $categoryUrlPathGenerator;
        $this->flatState = $flatState;
        $this->indexerRegistry = $indexerRegistry;
        parent::__construct();

    }

    /**
     *  Define the Console Command with Options
     */
    protected function configure()
    {
        $this->setName('experius_reindexcatalogurlrewrites:categoryurls')
            ->setDescription('Regenerate url_rewrites for given categories / stores')
            ->addOption(
                self::STORE_OPTION, 's',
                InputOption::VALUE_OPTIONAL,
                'Supply specific Store ID\'s (comma separated)'
            )->addOption(
                self::CATEGORY_OPTION, 'c',
                InputOption::VALUE_OPTIONAL,
                'Supply specific Category ID\'s (comma separated)'
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

        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            /** Try catch added to prevent "Area is already set" errors */
        }

        if ($input->hasOption(self::CATEGORY_OPTION) && $input->getOption(self::CATEGORY_OPTION)) {
            $this->categoryIds = explode(',', $input->getOption(self::CATEGORY_OPTION));
        }

        if ($input->hasOption(self::STORE_OPTION) && $input->getOption(self::STORE_OPTION)) {
            $this->storeIds = explode(',', $input->getOption(self::STORE_OPTION));
        }

        foreach ($this->getStoreIds() as $storeId) {
            $this->output->writeln('Regenerating category url_rewrites for store_id <info>' . $storeId . '</info>');
            $this->appEmulation->startEnvironmentEmulation($storeId);

            $list = $this->getCategoryCollection($storeId);
            $this->updateCatalogCategoryUrlPathCollection($list, $storeId);
            $this->updateCatalogCategoryUrlRewriteCollection($list, $storeId);
            $this->updateFlatCategoryIndex($list);

            $this->appEmulation->stopEnvironmentEmulation();
        }
    }

    /**
     * @param $collection
     * @param $storeId
     */
    protected function updateCatalogCategoryUrlPathCollection($collection, $storeId)
    {
        foreach ($collection as $category) {
            $category->setStoreId($storeId);
            $this->updateUrlPathForCategory($category);
        }
    }

    /**
     * @param Category $category
     * @return void
     */
    protected function updateUrlPathForCategory(Category $category)
    {
        $category->unsUrlPath();
        $category->setUrlPath($this->categoryUrlPathGenerator->getUrlPath($category));
        $category->getResource()->saveAttribute($category, 'url_path');
        $this->output->writeln('url_key updated for category entity_id = ' . $category->getId() . ' "' . $category->getName() . '"');
        $this->output->writeln('  ' . $category->getUrlPath());
    }


    /**
     * @param $collection
     * @param $storeId
     */
    protected function updateCatalogCategoryUrlRewriteCollection($collection, $storeId)
    {
        foreach ($collection as $category) {
            if ($category->getParentId() == Category::TREE_ROOT_ID) {
                continue;
            }

            $this->updateCatalogCategoryUrlRewrite($category, $storeId);


            if (!$this->categoryIds) {
                return;
            }
        }
    }

    protected function updateFlatCategoryIndex($collection)
    {
        foreach ($collection as $category) {
            if ($category->getLevel() != 2 || !$category->hasChildren()) {
                continue;
            }
            $this->reindex($category);
        }
    }

    protected function reindex($category)
    {
        if ($this->flatState->isFlatEnabled()) {
            $flatIndexer = $this->indexerRegistry->get(CategoryIndexer::INDEXER_ID);
            if (!$flatIndexer->isScheduled()) {
                $flatIndexer->reindexRow($category->getId());
                $this->output->writeln('Flat index row update for category entity_id = ' . $category->getId() . ' "' . $category->getName() . '"');
                $flatIndexer->reindexList(explode(',', $category->getAllChildren()));
                $this->output->writeln('Flat index list update for children category ids = ' . $category->getAllChildren());
            }
        }
    }

    /**
     * @param $category
     * @param $storeId
     */
    protected function updateCatalogCategoryUrlRewrite($category, $storeId = Store::DEFAULT_STORE_ID)
    {
        try {
            $category->setStoreId($storeId);

            $saveRewritesHistory = $this->scopeConfig->isSetFlag(
                UrlKeyRenderer::XML_PATH_SEO_SAVE_HISTORY,
                ScopeInterface::SCOPE_STORE,
                $category->getStoreId()
            );

            $category->setData('save_rewrites_history', $saveRewritesHistory);

            $urlRewrites = array_merge(
                $this->categoryUrlRewriteGenerator->generate($category, true),
                $this->urlRewriteHandler->generateProductUrlRewrites($category)
            );

            $this->urlPersist->replace($urlRewrites);
        } catch (\Exception $e) {
            $this->output->writeln('<error>An error occurred while updating url_rewrite for category ID: ' . $category->getId() . '</error>');
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');
        }
    }


    /**
     * @param $storeId
     * @return mixed
     */
    protected function getCategoryCollection($storeId)
    {
        $collection = $this->collection->create();
        $collection->setStoreId($storeId);

        if (!empty($this->categoryIds)) {
            $collection->addIdFilter($this->categoryIds);
        }

        $collection->addAttributeToSelect('name');
        $collection->addAttributeToSelect('url_key');

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
