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
    /**
     * @var ProductUrlRewriteGenerator
     */
    protected $productUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var ProductRepositoryInterface
     */
    protected $collection;
    
    /**
     * @var State
     */
    protected $state;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    protected $urlPathGenerator;

    protected $productIds = null;

    protected $storeIds = [];

    protected $output = null;

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

    protected function configure()
    {
        $this->setName('experius_reindexcatalogurlrewrites:producturls')
            ->setDescription('Regenerate url for given products')
            ->addOption(
                'store_ids','s',
                InputOption::VALUE_OPTIONAL,
                'Use the specific Store View'
            )->addOption(
                'product_ids','p',
                InputOption::VALUE_OPTIONAL,
                'Use the specific Store View'
            );

        return parent::configure();
    }

    public function execute(InputInterface $inp, OutputInterface $out)
    {

        try {
            $this->state->setAreaCode('adminhtml');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // intentionally left empty
        }

        $this->output = $out;

        if($inp->hasOption('product_ids') && $inp->getOption('product_ids')){
            $this->productIds = explode(',',$inp->getOption('product_ids'));
        }

        if($inp->hasOption('store_ids') && $inp->getOption('store_ids')){
            $this->storeIds = explode(',',$inp->getOption('store_ids'));
        }

        foreach($this->getStoreIds() as $storeId){
            $list = $this->getProductCollection($storeId);
            $this->updateCatalogProductUrlRewriteCollection($list,$storeId);
        }
    }

    protected function updateCatalogProductUrlRewriteCollection($collection,$storeId){
        foreach($collection as $product)
        {
            $this->updateCatalogProductUrlRewrite($product,$storeId);
        }
    }

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
                    productUrls
                );
            }
            
	    //$product->save();
        }
        catch(\Exception $e) {
            $this->output->writeln('<error>Duplicated url for '. $product->getId() .'</error>');
            $this->output->writeln($e->getMessage());
        }
    }

    protected function getProductCollection($storeId){

        /* @var $collection \Magento\Catalog\Model\ResourceModel\Product\Collection */

        $collection = $this->collection->create();

        $collection->addStoreFilter($storeId);
        $collection->setStoreId($storeId);

        if(!empty($this->productIds)) {
            $collection->addIdFilter($this->productIds);
        }

        $collection->addAttributeToSelect(['url_path', 'url_key','name']);

        return  $collection->load();
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
