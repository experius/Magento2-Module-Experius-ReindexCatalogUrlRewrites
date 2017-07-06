<?php
namespace Experius\ReindexCatalogUrlRewrites\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\Store\Model\Store;

class RegenerateProductUrlCommand extends Command
{

    const STORE_OPTION = "store_ids";
    const PRODUCT_OPTION = "product_ids";
    
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
            ->setDescription('Regenerate url for given products')
            ->addOption(
                self::STORE_OPTION,'s',
                InputOption::VALUE_OPTIONAL,
                'Use the specific Store View'
            )->addOption(
                self::PRODUCT_OPTION,'p',
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
            $this->state->setAreaCode('adminhtml');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // intentionally left empty
        }

        $this->output = $output;

        if($input->hasOption(self::PRODUCT_OPTION) && $input->getOption(self::PRODUCT_OPTION)){
            $this->productIds = explode(',',$input->getOption(self::PRODUCT_OPTION));
        }

        if($input->hasOption(self::STORE_OPTION) && $input->getOption(self::STORE_OPTION)){
            $this->storeIds = explode(',',$input->getOption(self::STORE_OPTION));
        }

        foreach($this->getStoreIds() as $storeId){
            $list = $this->getProductCollection($storeId);
            $this->updateCatalogProductUrlRewriteCollection($list,$storeId);
        }
    }

    /**
     * @param $collection
     * @param $storeId
     */
    protected function updateCatalogProductUrlRewriteCollection($collection,$storeId){
        foreach($collection as $product)
        {
            $this->updateCatalogProductUrlRewrite($product,$storeId);
        }
    }

    /**
     * @param $product
     * @param int $storeId
     */
    protected function updateCatalogProductUrlRewrite($product,$storeId=Store::DEFAULT_STORE_ID){

        $product->setStoreId($storeId);

        //$product->setUrlKey($this->urlPathGenerator->getUrlKey($product));
        $this->output->writeln(sprintf('Update Url For Product %s StoreId %s',$product->getSku(),$storeId));

        $this->urlPersist->deleteByData([
            UrlRewrite::ENTITY_ID => $product->getId(),
            UrlRewrite::ENTITY_TYPE => $this->productUrlRewriteGenerator::ENTITY_TYPE,
            UrlRewrite::REDIRECT_TYPE => 0,
            UrlRewrite::STORE_ID => $storeId
        ]);

        try {
            if($product->isVisibleInSiteVisibility()) {
		$productUrls = $this->productUrlRewriteGenerator->generate($product);

	            //foreach($productUrls as $productUrl){
	            //    echo $productUrl->getRequestPath() . "\n";
	            //}
                $this->urlPersist->replace(
                    $productUrls
                );
            }
            
	    //$product->save();
        }
        catch(\Exception $e) {
            $this->output->writeln('<error>Duplicated url for '. $product->getId() .'</error>');
            $this->output->writeln($e->getMessage());
        }
    }

    /**
     * @param $storeId
     * @return $this
     */
    protected function getProductCollection($storeId){

        /* @var $collection \Magento\Catalog\Model\ResourceModel\Product\Collection */

        $collection = $this->collection->create();

        $collection->addStoreFilter($storeId);
        $collection->setStoreId($storeId);

        if(!empty($this->productIds)) {
            $collection->addIdFilter($this->productIds);
        }

        $collection->addAttributeToSelect(['url_path', 'url_key', 'name', 'visibility']);

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
