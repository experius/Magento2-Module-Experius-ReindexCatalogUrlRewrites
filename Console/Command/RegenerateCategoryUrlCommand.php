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

    const STORE_OPTION = "store_ids";
    const CATEGORY_OPTION = "category_ids";
    
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
     */
    public function __construct(
        \Magento\Framework\App\State $state,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $collection,
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
        $this->appEmulation = $appEmulation;
        $this->urlPersist = $urlPersist;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->scopeConfig = $scopeConfig;
        $this->urlRewriteHandler = $urlRewriteHandler;

        parent::__construct();

    }

    /**
     *  Define the Console Command with Options
     */
    protected function configure()
    {
        $this->setName('experius_reindexcatalogurlrewrites:categoryurls')
            ->setDescription('Regenerate url for given categories')
            ->addOption(
                self::STORE_OPTION,'s',
                InputOption::VALUE_OPTIONAL,
                'Use the specific Store View'
            )->addOption(
                self::CATEGORY_OPTION,'c',
                InputOption::VALUE_OPTIONAL,
                'Use the specific Store View'
            );

        return parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {

	    try {
            $this->state->setAreaCode('frontend');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // intentionally left empty
        }

        $this->output = $output;

        if($input->hasOption(self::CATEGORY_OPTION) && $input->getOption(self::CATEGORY_OPTION)){
            $this->categoryIds = explode(',',$input->getOption(self::CATEGORY_OPTION));
        }

        if($input->hasOption(self::STORE_OPTION) && $input->getOption(self::STORE_OPTION)){
            $this->storeIds = explode(',',$input->getOption(self::STORE_OPTION));
        }

        foreach($this->getStoreIds() as $storeId){

            $this->appEmulation->startEnvironmentEmulation($storeId);

            $list = $this->getCategoryCollection($storeId);
            $this->updateCatalogCategoryUrlRewriteCollection($list,$storeId);

            $this->appEmulation->stopEnvironmentEmulation();
        }
    }

    /**
     * @param $collection
     * @param $storeId
     */
    protected function updateCatalogCategoryUrlRewriteCollection($collection,$storeId){
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

    /**
     * @param $category
     * @param $storeId
     */
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
            $this->output->writeln('<error>Duplicated url for '. $category->getId() .'</error>');
            $this->output->writeln($e->getMessage());
        }
    }


    /**
     * @param $storeId
     * @return mixed
     */
    protected function getCategoryCollection($storeId){

        $collection = $this->collection->create();
    	$collection->setStoreId($storeId);

        if(!empty($this->categoryIds)) {
            $collection->addIdFilter($this->categoryIds);
        }

        //$collection->addUrlRewriteToResult();
        $collection->addAttributeToSelect('name');

        return  $collection->load();
    }

    /**
     * @return array
     */
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
