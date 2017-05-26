<?php
namespace Experius\ReindexCatalogUrlRewrites\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Catalog\Model\Category;

use Magento\CatalogUrlRewrite\Block\UrlKeyRenderer;
use Magento\Store\Model\ScopeInterface;

class RegenerateCategoryUrlCommand extends Command
{

    protected $urlPersist;

    protected $collection;

    protected $state;

    protected $storeManager;

    protected $categoryIds = null;

    protected $storeIds = [];

    protected $output = null;

    protected $eventManager;

    protected $_appEmulation;

    protected $categoryUrlRewriteGenerator;

    protected $scopeConfig;

    protected $urlRewriteHandler;

    public function __construct(
        \Magento\Framework\App\State $state,
        \Magento\Catalog\Model\ResourceModel\Category\Collection $collection,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Event\Manager $eventManager,
        \Magento\Store\Model\App\Emulation $appEmulation,
        \Magento\UrlRewrite\Model\UrlPersistInterface $urlPersist,
        \Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\CatalogUrlRewrite\Observer\UrlRewriteHandler $urlRewriteHandler
    ) {

        $this->state = $state;
        $this->collection = $collection;
        $this->storeManager = $storeManager;
        $this->eventManager = $eventManager;
        $this->_appEmulation = $appEmulation;
        $this->urlPersist = $urlPersist;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->scopeConfig = $scopeConfig;
        $this->urlRewriteHandler = $urlRewriteHandler;

        parent::__construct();

    }

    protected function configure()
    {
        $this->setName('experius_reindexcatalogurlrewrites:categoryurls')
            ->setDescription('Regenerate url for given categories')
            ->addOption(
                'store_ids','s',
                InputOption::VALUE_OPTIONAL,
                'Use the specific Store View'
            )->addOption(
                'category_ids','c',
                InputOption::VALUE_OPTIONAL,
                'Use the specific Store View'
            );

        return parent::configure();
    }

    public function execute(InputInterface $inp, OutputInterface $out)
    {
        if(!$this->state->getAreaCode()) {
            $this->state->setAreaCode('frontend');
        }

        $this->output = $out;

        if($inp->hasOption('category_ids') && $inp->getOption('category_ids')){
            $this->categoryIds = explode(',',$inp->getOption('category_ids'));
        }

        if($inp->hasOption('store_ids') && $inp->getOption('store_ids')){
            $this->storeIds = explode(',',$inp->getOption('store_ids'));
        }

        foreach($this->getStoreIds() as $storeId){

            $this->_appEmulation->startEnvironmentEmulation($storeId);

            $list = $this->getCategoryCollection($storeId);
            $this->updateCatalogProductUrlRewriteCollection($list,$storeId);

            $this->_appEmulation->stopEnvironmentEmulation();
        }
    }

    protected function updateCatalogProductUrlRewriteCollection($collection,$storeId){
        foreach($collection as $category) {
            if ($category->getParentId() == Category::TREE_ROOT_ID) {
                continue;
            }

            $this->updateCatalogCategoryUrlRewrite($category,$storeId);

            if (!$this->categoryIds){
                return;
            }
        }
    }

    protected function updateCatalogCategoryUrlRewrite($category,$storeId=Store::DEFAULT_STORE_ID){

        $category->setStoreId($storeId);

        $this->output->writeln(sprintf('Update Url For Category %s StoreId %s',$category->getName(),$storeId));

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

        //$this->urlRewriteHandler->deleteCategoryRewritesForChildren($category);

        try {
            $this->urlPersist->replace($urlRewrites);
        }
        catch(\Exception $e) {
            $this->output->writeln('<error>Duplicated url for '. $product->getId() .'</error>');
            $this->output->writeln($e->getMessage());
        }
    }


    protected function getCategoryCollection($storeId){

        $this->collection->setStoreId($storeId);

        if(!empty($this->categoryIds)) {
            $this->collection->addIdFilter($this->categoryIds);
        }

        //$this->collection->addUrlRewriteToResult();
        $this->collection->addAttributeToSelect('name');

        return  $this->collection->load();
    }

    protected function getStoreIds(){

        if(!empty($this->storeIds)){
            return $this->storeIds;
        }

        $storeIds = [];
        foreach($this->storeManager->getStores() as $store){
            $storeIds[] = $store->getId();
        }

        return $storeIds;
    }
}
