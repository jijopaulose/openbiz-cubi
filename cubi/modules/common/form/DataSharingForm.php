<?php 
class DataSharingForm extends EasyForm
{
	public $m_hasOwnerField = false;
	public function fetchData()
	{
		$prtForm = $this->m_ParentFormName;
		$prtFormObj = BizSystem::GetObject($prtForm);
		$recId = $this->m_RecordId;
		$dataObj = $prtFormObj->getDataObj();
		$dataRec = $dataObj->fetchById($recId);		
		
		$user_id = BizSystem::GetUserProfile("Id");
		$group_id = BizSystem::GetUserProfile("default_group");
		
		
		$this->m_hasOwnerField = $this->hasOwnerField();
 				
		$result = array();		
		$result['Id'] = $dataRec['Id'];					
		$result['editable'] = 0;
		$result['has_ref_data'] = 0;
		
		if($dataObj->m_ObjReferences->count()){			
			$result['has_ref_data'] = 1;			
		}
		
		if($user_id == $dataRec['create_by'])
		{
			$result['shared_data'] = 0;
			$result['editable'] = 1;
			
		}
		elseif($this->m_hasOwnerField && $owner_id == $user_id)
		{
			$result['shared_data'] = 0;
			$result['editable'] = 1;
		}
		elseif($group_id == $dataRec['group_id'])
		{
			$result['shared_data'] = 1;			
		}
		else
		{
			$result['shared_data'] = 2;
		}
		
		if($dataRec['name']!='')
		{
			$result['data_record'] = $dataRec['name'];
		}	
		elseif($dataRec['subject']!='')
		{
			$result['data_record'] = $dataRec['subject'];
		}
		elseif($dataRec['title']!='')
		{
			$result['data_record'] = $dataRec['title'];
		}
		elseif($dataRec['display_name']!='')
		{
			$result['data_record'] = $dataRec['display_name'];
		}else
		{
			$result['data_record'] = $dataRec['Id'];
		}
		
		if($this->m_hasOwnerField){
			$owner_id = $dataRec['owner_id'];			
			$result['owner_id'] =  $dataRec['owner_id'];
			
			if($dataRec['owner_id'] != $dataRec['create_by'])
			{
				if($dataRec['owner_id'] == $user_id)
				{
					$result['shared_data'] = 3;
					$result['editable'] = 1;
				}
				elseif($dataRec['create_by'] == $user_id)
				{
					$result['shared_data'] = 4;
					$result['editable'] = 1;
				}
			}
			
		}else{
			$owner_id = $dataRec['create_by'];
		}	
		
		$result['data_record'] = str_replace("<br />","",$result['data_record']);
		$result['owner_perm'] = 3;
		
		$inputArr = $this->readInputRecord();
		
		$result['group_id'] =  $dataRec['group_id'];
		$result['group_perm'] = isset($inputArr['group_perm'])?$inputArr['group_perm']:$dataRec['group_perm'];
		$result['other_perm'] = isset($inputArr['other_perm'])?$inputArr['other_perm']:$dataRec['other_perm'];
		$result['group_name'] = $this->_getGroupName($dataRec['group_id']);
		$result['owner_name'] = $this->_getOwnerName($owner_id);
		$result['creator_name'] = 	$this->_getOwnerName($dataRec['create_by']);
		$result['hasOwnerField'] = (int)$this->m_hasOwnerField;
		return $result;
	}
	
	public function ShareRecord()
	{
		$prtForm = $this->m_ParentFormName;
		$prtFormObj = BizSystem::GetObject($prtForm);
		$recId = $this->m_RecordId;
		$dataObj = $prtFormObj->getDataObj();
		$dataRec = $dataObj->fetchById($recId);
		
		$recArr = $this->readInputRecord();
		$DataRec = $dataRec;
		if(isset($recArr['group_perm']))
		{
			$DataRec['group_perm'] = $recArr['group_perm'];
		}
		
		if(isset($recArr['other_perm']))
		{
			$DataRec['other_perm'] = $recArr['other_perm'];
		}
		
		if(isset($recArr['group_id']))
		{
			$DataRec['group_id']	= $recArr['group_id'];	
		}		
		
		if(isset($recArr['owner_id'])){
			$DataRec['owner_id']	= $recArr['owner_id'];
		}
		
		$DataRec->save();
		//$prtFormObj->getDataObj()->updateRecord($newDataRec,$dataRec);
		
		
		
		if($recArr['update_ref_data']){
			if($dataObj->m_ObjReferences->count()){
				$this->_casacadeUpdate($dataObj, $recArr);
			}			
		}
		
		if ($this->m_ParentFormName)
        {
            $this->close();
            $this->renderParent();
        }
        $this->processPostAction();
	}
	
	private function _casacadeUpdate($obj,$setting){
		$dataShareSvc = BizSystem::getService(DATAPERM_SERVICE);
		foreach($obj->m_ObjReferences as $doRef){			
				$do = BizSystem::getObject($doRef->m_Name);				
				$rs = $do->fetch();
				foreach($rs as $rec){
					if($dataShareSvc->checkDataOwner($rec)){
						$newRec = $rec;
						$newRec['group_perm'] = $setting['group_perm'];
						$newRec['other_perm'] = $setting['other_perm'];
						$newRec['group_id']	= $setting['group_id'];
						if($rec['owner_id']){
							$newRec['owner_id']	= $setting['owner_id'];
						}
						$ok = $do->updateRecord($newRec,$rec);
					}
				}				
				if($do->m_ObjReferences->count())
				{
					//$this->_casacadeUpdate($do, $setting);					
				}
			}
	}
	
	private function _getGroupName($id)
	{
		$rec = BizSystem::GetObject("system.do.GroupDO")->fetchById($id);
		$result = $rec['name'];
		return $result;
	}
	
	private function _getOwnerName($id)
	{
		
		$contactDO = BizSystem::GetObject("contact.do.ContactSystemDO");
		$rec = $contactDO->fetchOne("[user_id]='$id'");
		if(count($rec))
		{
			$result = $rec['display_name'];
		}
		else
		{
			$rec = BizSystem::GetObject("system.do.UserDO")->fetchById($id);
			$result = $rec['username']." (".$rec['email'].")";
		}
		return $result;
	}
	
	public function hasOwnerField(){
		$prtForm = $this->m_ParentFormName;
		$prtFormObj = BizSystem::GetObject($prtForm);		
		$field = $prtFormObj->getDataObj()->getField('owner_id');
		if($field){
			return true;
		}
		else{
			return false;
		}
		
	}	
}
?>