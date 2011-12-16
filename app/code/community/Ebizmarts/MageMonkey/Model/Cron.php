<?php

class Ebizmarts_MageMonkey_Model_Cron
{
	/**
	 * Limit var for SQL queries
	 */
	protected $_limit = 200;

	/**
	 * Import limit var
	 */
	protected $_importLimit = 500;

	public function processImportJobs()
	{
		$job = $this->_getJob('Import');
		if(is_null($job)){
			return $this;
		}

		$start = 0;
		$toImport = array();

		foreach($job->lists() as $listId){

			$store = $this->_helper()->getStoreByList($listId);
			$websiteId = Mage::app()->getStore($store)->getWebsiteId();

			$exportapi = Mage::getModel('monkey/api', array('store' => $store, '_export_' => TRUE));
			$api = Mage::getModel('monkey/api', array('store' => $store));

			$mergevars = $api->listMergeVars($listId);

			foreach($job->statuses() as $status){

				$members = $exportapi->listExport($listId, $status, NULL, $start, $this->_importLimit);

				if($members){
					if( !isset($toImport[$status]) ){
						$toImport [$status] = array();
					}
					$mdata = $this->_helper('export')->parseMembers($members, $mergevars, $store);

					$toImport [$status] += $mdata;
				}

			}

			if( count($toImport) > 0 ){

				foreach($toImport as $type => $emails){

					foreach($emails as $data){

						//Run: subscribed, unsubscribed, cleaned or updated method
						$this->{$type}($data, $websiteId, (bool)$job->getCreateCustomer());

					}

				}

			}

			//var_dump($toImport);die;

		}
	}

	protected function subscribed($member, $websiteId = null, $createCustomer = FALSE)
	{

		if( $createCustomer ){

			$customer = Mage::getModel('customer/customer')->setWebsiteId($websiteId)
															->loadByEmail($member['email']);

			//Create customer if not exists, and subscribe
			if( is_null($customer->getId()) ){
				$this->_helper()->createCustomerAccount($member, $websiteId);
			}else{
				//Just subscribe existing customer
				Mage::getModel('newsletter/subscriber')->setImportMode(TRUE)
														->subscribeCustomer($customer);
			}

		}else{

			//Just subscribe email
			Mage::getModel('newsletter/subscriber')->setImportMode(TRUE)
													->subscribe($member['email']);

		}

	}

	protected function unsubscribed($email, $websiteId = null, $createCustomer = FALSE)
	{

	}

	protected function cleaned($email, $websiteId = null, $createCustomer = FALSE)
	{
		return $this->unsubscribed($email, $websiteId, $createCustomer);
	}

	protected function updated($email, $websiteId = null, $createCustomer = FALSE)
	{

	}



	/**
	 * Process EXPORT tasks
	 */
	public function processExportJobs()
	{
		$job = $this->_getJob('Export');
		if(is_null($job)){
			return $this;
		}

		$collection = $this->_getEntityModel($job->getDataSourceEntity())
						->setPageSize($this->_limit);

		//Condition for chunk batch
		if($job->getLastProcessedId()){
			$collection->addFieldToFilter($this->_getId($job->getDataSourceEntity()), array('gt' => (int)$job->getLastProcessedId()));
		}

		$collection->load();

    	//var_dump((string)$collection->getSelect());

		$batch = array();

		foreach($job->lists() as $listId){
			$store = $this->_helper()->getStoreByList($listId);

			//if($store){

				$api = Mage::getSingleton('monkey/api', array('store' => $store));

				$processedCount = 0;
				foreach($collection as $item){
					$processedCount += 1;
					$batch []= $this->_helper()->getMergeVars($item, TRUE);
				}

				//var_dump($batch);

				if(count($batch) > 0){

					$job->setStatus('chunk_running')
						->setUpdatedAt($this->_dbDate())
						->save();

					$vals = $api->listBatchSubscribe($listId, $batch, FALSE, TRUE, FALSE);

					if ( is_null($api->errorCode) ){

						$lastId = $collection->getLastItem()->getId();
						$job->setLastProcessedId($lastId);
						$job->setProcessedCount( ( $processedCount+$job->getProcessedCount() ));

						/*if( $processedCount < $this->_limit ){
							$job->setStatus('finished');
						}*/

						$job
						->setUpdatedAt($this->_dbDate())
						->save();

					} else {

						//TODO: Do something to handle errors

					    /*echo "Batch Subscribe failed!\n";
						echo "code:".$api->errorCode."\n";
						echo "msg :".$api->errorMessage."\n";
						die;*/
						/*echo "added:   ".$vals['add_count']."\n";
						echo "updated: ".$vals['update_count']."\n";
						echo "errors:  ".$vals['error_count']."\n";
						foreach($vals['errors'] as $val){
							echo $val['email_address']. " failed\n";
							echo "code:".$val['code']."\n";
							echo "msg :".$val['message']."\n";
						}
						die;*/

					}

				}else{
					$job
					->setStatus('finished')
					->setUpdatedAt($this->_dbDate())
					->save();
				}

			//}
		}

		return $this;
	}

	protected function _getEntityModel($type)
	{
		$model = null;

		switch ($type) {
			case 'newsletter_subscriber':
				$model = Mage::getResourceSingleton('newsletter/subscriber_collection')
							//->showCustomerInfo(true)
            				->addSubscriberTypeField()
            				->showStoreInfo();
				break;
			case 'customer':

				//TODO: Add default Billing and Shipping address data

				$model = Mage::getResourceModel('customer/customer_collection')
							->addNameToSelect()
							->addAttributeToSelect('gender')
							->addAttributeToSelect('dob');
				break;
		}

		return $model;
	}

	protected function _getId($type)
	{
		$idFieldName = null;

		switch ($type) {
			case 'newsletter_subscriber':
				$idFieldName = 'subscriber_id';
				break;
			default:
				$idFieldName = 'id';
		}

		return $idFieldName;
	}

	protected function _helper($which = 'data')
	{
		return Mage::helper('monkey/'.$which);
	}

	protected function _dbDate()
	{
		return Mage::getModel('core/date')->gmtDate();
	}

	protected function _getJob($entity)
	{
		$job = Mage::getModel("monkey/bulksync{$entity}")
					->getCollection()
					->addFieldToFilter('status', array('IN' => array('idle', 'chunk_running') ))
					->addOrder('created_at', 'asc')
					->load();
		if(!$job->getFirstItem()->getId()){
			return null;
		}

		return $job->getFirstItem();
	}
}