<?php 
class MetaGeneratorService
{
	
	protected $m_DBName;
	protected $m_DBTable;
	protected $m_DBFields;
	protected $m_DBFieldsInfo;
	protected $m_DBSearchField;
	protected $m_ConfigModule;
	protected $m_BuildOptions;
	
	protected $m_MetaTempPath;
	protected $m_ACLArr;
	protected $m_TypeDOFullName;
	
	/**
	 * 
	 * This files will records generated files
	 * array - DataObjFiles
	 * array - FormObjFiles
	 * array - ViewObjFiles
	 * array - MessageFiles
	 * array - TemplateFiles
	 * string - ModXMLFile
	 * @var array Generated Files list
	 */
	protected $m_GeneratedFiles;
	
	public function setDBName($dbName)
	{
		$this->m_DBName	=	$dbName;
		return $this;
	}
	
	public function setDBTable($dbTable)
	{
		$this->m_DBTable	=	$dbTable;
		return $this;
	}
	
	public function setDBFields($dbFields)
	{
		$this->m_DBFields	=	$dbFields;
		return $this;
	}
	
	public function setConfigModule($configModule)
	{
		$this->m_ConfigModule	=	$configModule;
		return $this;
	}

	public function setBuildOptions($buildOptions)
	{
		$this->m_BuildOptions	=	$buildOptions;
		return $this;
	}	
	
	/**
	 * 	$svc->setDBName($dbName);
	 *  $svc->setDBTable($dbTable);
	 *  $svc->setDBFields($dbFields);
	 *  $svc->setConfigModule($configModule);
	 *  $svc->setBuildOptions($buildOptions);		
	 *  $svc->generate();
	 * Generate MetaObject Files
	 */
	public function generate()
	{
		$this->_genMessageFiles();
		$this->_genDataObj();
		$this->_genFormObj();
		$this->_genViewObj();
		$this->_genTemplateFiles();
		$this->_genResourceFiles();
		$this->_genDashboardFiles();
		$this->_genModuleFile();
		$this->_loadModule();
		var_dump($this->m_GeneratedFiles);
		exit;
		return $this->m_GeneratedFiles;
	}
	
	protected function _loadModule()
	{
		$modName = $this->__getModuleName(false);		
		include_once MODULE_PATH.DIRECTORY_SEPARATOR.'system'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'ModuleLoader.php';
		$loader = new ModuleLoader($modName);
		return $loader->loadModule();		
	}
	
	protected function _genDataObj()
	{
		if($this->m_BuildOptions["gen_data_object"]!='1')
		{
			return false;
		}
		$templateFile = $this->__getMetaTempPath().'/do/DataObject.xml.tpl';
		$doName 	= $this->m_ConfigModule['object_name'];
		$doDesc 	= $this->m_ConfigModule['object_desc'];			
		$modName 	= $this->__getModuleName(); 				
		$uniqueness = $this->_getUniqueness();
		$sortField  = $this->_getSortField();
		$aclArr     = $this->_getACLArr();		
		$features	= $this->_getExtendFeatures();		
		$doFullName = $modName.'.do.'.$this->m_ConfigModule['object_name'];	
		
		if($this->m_ConfigModule['data_perm']=='0')
		{
			$doPermControl = "N";
		}
		else
		{
			$doPermControl = "Y";
		}
		
 		if(CLI){echo "Start generate dataobject $doName." . PHP_EOL;}
        $targetPath = $moduleDir = MODULE_PATH . "/" . str_replace(".", "/", $modName) . "/do";
        if (!file_exists($targetPath))
        {
            if(CLI){echo "Create directory $targetPath" . PHP_EOL;}
            mkdir($targetPath, 0777, true);
        }

	    if($features['extend']==1)
        {        	
        	$this->_genExtendTypeDO();
        }
        
        $smarty = BizSystem::getSmartyTemplate();
        
        $smarty->assign("do_full_name", $doFullName);
        $smarty->assign("do_name", $doName);        
        $smarty->assign("do_desc", $doDesc);
        $smarty->assign("db_name", $this->m_DBName);
        $smarty->assign("do_perm_control", $doPermControl);        
        $smarty->assign("table_name", $this->m_DBTable);
        $smarty->assign("fields", $this->m_DBFieldsInfo);        
        $smarty->assign("uniqueness", $uniqueness);        
        $smarty->assign("sort_field", $sortField);
        $smarty->assign("features", $features);
        $smarty->assign("acl", $aclArr);

        if($features['self_reference']==1)
        {
        	$smarty->assign("do_full_name_ref", 		str_replace("DO","RefDO",$doFullName));
        	$smarty->assign("do_full_name_related", 	str_replace("DO","RelatedDO",$doFullName));
        	$smarty->assign("table_name_related",		$this->m_DBTable."_releated");        	
        	$smarty->assign("table_ref_id", 			strtolower($this->m_DBTable)."_id");
        	$this->_genSelfReferenceDO();        
        }
        
        $content = $smarty->fetch($templateFile);
                
        $targetFile = $targetPath . "/" . $doName . ".xml";
        file_put_contents($targetFile, $content);        

        $this->m_GeneratedFiles['DataObjFiles']['MainDO']=str_replace(MODULE_PATH,"",$targetFile);        
        if(CLI){echo "\t".str_replace(MODULE_PATH,"",$targetFile)." is generated." . PHP_EOL;}

        return $targetFile;		
	}
	
	/**
	 * 
	 * Generate Data Objects for Extend Data Fields Feature
	 * if data_type table doesn't exists then create it.
	 */
	protected function _genExtendTypeDO()
	{
		if($this->m_BuildOptions["gen_data_object"]!='1')
		{
			return false;
		}
		$extendTypeDO = $this->m_ConfigModule['extend_type_do'];
        $extendTypeDesc = $this->m_ConfigModule['extend_type_desc'];
        
        $db 	= BizSystem::dbConnection($this->m_DBName);
        
        //check type_id field existing
        $fieldName = "type_id";
        if(!$this->__isFieldExists($fieldName))
        {
        	$this->__addDOField($fieldName);
        }
        if(!in_array($fieldName, $this->m_DBFields))
        {
        	$this->m_DBFields[] = $fieldName;
        }
        
        //drop record type table if it exists
        $tableTypeName = $this->m_DBTable."_type";
        $sql="DROP TABLE IF EXISTS `$tableTypeName`;";
        $db->query($sql);
        
        if($this->m_ConfigModule['data_perm']=='1')
		{
	        $perm_fields = "
	        	  `group_id` int(11) DEFAULT '1',
				  `group_perm` int(11) DEFAULT '1',
				  `other_perm` int(11) DEFAULT '1', ";
		}
		
		//create record type table
        $sql="
			CREATE TABLE IF NOT EXISTS `$tableTypeName` (
			  `id` int(11) NOT NULL AUTO_INCREMENT,
			  `name` varchar(255) NOT NULL,
			  `description` text NOT NULL,
			  `color` varchar(255) NOT NULL,
			  `sortorder` int(11) NOT NULL,
			  $perm_fields
			  `create_by` int(11) NOT NULL,
			  `create_time` datetime NOT NULL,
			  `update_by` int(11) NOT NULL,
			  `update_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`)
			) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;        
        ";
        $db->query($sql);
        
        $doName 	= $extendTypeDO;
		$doDesc 	= $extendTypeDesc;			
		$modName 	= $this->__getModuleName(); 				
		$doFullName = $modName.'.do.'.$this->m_ConfigModule['object_name'];	
		$this->m_TypeDOFullName = $modName.'.do.'.$extendTypeDO;
		
		if($this->m_ConfigModule['data_perm']=='0')
		{
			$doPermControl = "N";
		}
		else
		{
			$doPermControl = "Y";
		}
		
        $targetPath = $moduleDir = MODULE_PATH . "/" . str_replace(".", "/", $modName) . "/do";
        $templateFile = $this->__getMetaTempPath().'/do/DataObjectExtendTypeDO.xml.tpl';
        $smarty = BizSystem::getSmartyTemplate();
        
        $smarty->assign("record_do_full_name", $doFullName);
        $smarty->assign("do_name", $doName);        
        $smarty->assign("do_desc", $doDesc);
        $smarty->assign("db_name", $this->m_DBName);
        $smarty->assign("do_perm_control", $doPermControl);
        $smarty->assign("table_name", $this->m_DBTable);        
        $smarty->assign("table_type_name", $tableTypeName);
        
        $content = $smarty->fetch($templateFile);
                
        $targetFile = $targetPath . "/" . $doName . ".xml";
        file_put_contents($targetFile, $content);        
        $this->m_GeneratedFiles['DataObjFiles']['TypeDO']=str_replace(MODULE_PATH,"",$targetFile);                
	}
	
	/**
	 * Generate Form Objects for Extend Type Feature
	 */
	protected function _genExtendTypeForm()
	{
		if($this->m_BuildOptions["gen_form_object"]!='1')
		{
			return false;
		}
		$this->m_GeneratedFiles['EditTypeForm']=str_replace(MODULE_PATH,"",$targetFile);
		
		$this->m_GeneratedFiles['TypeListForm']=str_replace(MODULE_PATH,"",$targetFile);
		
		$this->m_GeneratedFiles['TypeNewForm']=str_replace(MODULE_PATH,"",$targetFile);
		
		$this->m_GeneratedFiles['TypeEditForm']=str_replace(MODULE_PATH,"",$targetFile);
	}
	
	/**
	 * Generate Form Objects for Extend Type Feature
	 */
	protected function _genExtendTypeView()
	{
		if($this->m_BuildOptions["gen_view_object"]!='1')
		{
			return false;
		}
		$templateFile = $this->__getMetaTempPath().'/view/TypeView.xml.tpl';
		$viewName 	= $this->__getObjectName()."TypeView";
		$viewDesc 	= "Type of ".$this->m_ConfigModule['object_desc'];			
		$modName 	= $this->__getModuleName(); 	
		$modBaseName= $this->__getModuleName(false);
		$aclArr     = $this->_getACLArr();				
		$defaultFormName = $modName.'.form.'.$this->__getObjectName().'TypeListForm';
		
        $smarty = BizSystem::getSmartyTemplate();
        $smarty->assign("type_view_name", $viewName);
        $smarty->assign("type_view_desc", $viewDesc);        
        $smarty->assign("default_form_name", $defaultFormName);
        $smarty->assign("acl", $aclArr);
        $content = $smarty->fetch($templateFile);
                
        $targetPath = $moduleDir = MODULE_PATH . "/" . str_replace(".", "/", $modBaseName) . "/view";
        $targetFile = $targetPath . "/" . $viewName . ".xml";
        file_put_contents($targetFile, $content);      
		$this->m_GeneratedFiles['ViewObjFiles']['TypeManageView']=str_replace(MODULE_PATH,"",$targetFile);
	}
	
	private function __addDOField($fieldName)
	{
		$tableName 	= $this->m_DBTable;
		$db 		= BizSystem::dbConnection($this->m_DBName);	
		$sql 		= "ALTER TABLE `$tableName` ADD `type_id` INT( 11 ) NOT NULL AFTER `id` , ADD INDEX ( `type_id` ) ;";
		$db->query($sql);		
	}
	
	private function __isFieldExists($fieldName)
	{
		$db 	= BizSystem::dbConnection($this->m_DBName);
		$tableName 	= $this->m_DBTable;
		$sql	= "SHOW FULL COLUMNS FROM `$tableName`";
		$fieldsInfo = $db->fetchAssoc($sql);
		foreach($fieldsInfo as $field=>$info)
		{
			if($field == $fieldName)
			{
				return true;
			}
		}
		return false;
	}
	
	protected function _genSelfReferenceDO()
	{				
		if($this->m_BuildOptions["gen_data_object"]!='1')
		{
			return false;
		}
		
		// Generate Reference DataObject
		$templateFile = $this->__getMetaTempPath().'/do/DataObjectRef.xml.tpl';
		$doName 	= $this->m_ConfigModule['object_name'];
		$doDescRef 	= $this->m_ConfigModule['object_desc'].' - Reference DO';
		$modName 	= $this->__getModuleName(); 				
		$uniqueness = $this->_getUniqueness();
		$sortField  = $this->_getSortField();
		$aclArr     = $this->_getACLArr();		
		$features	= $this->_getExtendFeatures();		
		$doFullName = $modName.'.do.'.$this->m_ConfigModule['object_name'];
		$doNameRef	= str_replace("DO","RefDO",$doName);
		$targetPath = $moduleDir = MODULE_PATH . "/" . str_replace(".", "/", $modName) . "/do";
		
		if($this->m_ConfigModule['data_perm']=='0')
		{
			$doPermControl = "N";
		}
		else
		{
			$doPermControl = "Y";
		}				
		
        $smarty = BizSystem::getSmartyTemplate();
        
        $smarty->assign("do_name", $doNameRef);        
        $smarty->assign("do_desc", $doDescRef);
        $smarty->assign("db_name", $this->m_DBName);
        $smarty->assign("do_perm_control", $doPermControl);        
        $smarty->assign("table_name", $this->m_DBTable);
        $smarty->assign("fields", $this->m_DBFieldsInfo);        
        $smarty->assign("uniqueness", $uniqueness);
        $smarty->assign("sort_field", $sortField);
        $smarty->assign("features", $features);
        $smarty->assign("acl", $aclArr);
        
		$content = $smarty->fetch($templateFile);                
        $targetFile = $targetPath . "/" . $doNameRef . ".xml";
        file_put_contents($targetFile, $content); 
		$this->m_GeneratedFiles['DataObjFiles']['MainRefDO']=str_replace(MODULE_PATH,"",$targetFile);
		
        // Create a record_related table
        $tableNameRef = $this->m_DBTable.'_related';
        $tableRefId = strtolower($this->m_DBTable)."_id";
        $db 	= BizSystem::dbConnection($this->m_DBName);	
        $sql	= "DROP TABLE IF EXISTS `$tableNameRef`;";	
        $db->query($sql);
        		
		$sql 	= "
				CREATE TABLE `$tableNameRef` (
				  `id` int(10) unsigned NOT NULL auto_increment,
				  `$tableRefId` int(10) unsigned NOT NULL default '0',
				  `related_id` int(10) unsigned NOT NULL default '0',  
				  PRIMARY KEY  (`id`),
				  KEY `related_id` (`related_id`),
				  KEY `$tableRefId` (`$tableRefId`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8;";
		$db->query($sql);
        
        
		// Generate Related DataObject        
		$templateFile = $this->__getMetaTempPath().'/do/DataObjectRelated.xml.tpl';
		
		$doNameRelated	= str_replace("DO","RelatedDO",$doName);
		$doDescRelated 	= $this->m_ConfigModule['object_desc'].' - Related DO';
		
		$smarty->assign("do_name", $doNameRelated);
		$smarty->assign("do_desc", $doDescRelated);		
		$smarty->assign("table_name", $tableNameRef);
		$smarty->assign("table_ref_id", $tableRefId);			
						
		$content = $smarty->fetch($templateFile);                
        $targetFile = $targetPath . "/" . $doNameRelated . ".xml";
        file_put_contents($targetFile, $content); 
        $this->m_GeneratedFiles['DataObjFiles']['MainRelatedDO']=str_replace(MODULE_PATH,"",$targetFile);
	}
	
	protected function _getFieldsInfo()
	{
		if(is_array($this->m_DBFieldsInfo))
		{
			return $this->m_DBFieldsInfo;
		}
		else 
		{
			$fields = $this->m_DBFields;
			$tableName = $this->m_DBTable;
			
			$fieldStr = "'".implode("','", $fields)."'";		
			
			$db 	= BizSystem::dbConnection($this->m_DBName);				
			$sql 	= "SHOW FULL COLUMNS FROM `$tableName` WHERE `Field` IN ($fieldStr);";
			$resultSet = $db->fetchAssoc($sql);
			
			foreach($resultSet as $key=>$arr)
			{
				$arr['FieldName'] 	= ucwords($arr['Field']);
				$arr['FieldType'] 	= $this->__convertDataType($arr['Type']);
				$arr['Description'] = $this->__getFieldDesc($arr);
				$arr['FieldLabel'] 	= $arr['Description'];
				$resultSet[$key] 	= $arr;
				
				if($arr['Key']=='MUL')
				{
					$this->m_DBSearchField = $arr; 
				}
			}						
			
			$this->m_DBFieldsInfo = $resultSet;			
			return $resultSet;
		}		
	}
	
	/**
	 * 
	 * Get uniqueness fields list for Data Object
	 * @return string fieldList - e.g.: name;sku 
	 */
	protected function _getUniqueness()
	{
		$fields = $this->_getFieldsInfo();
		$fieldstr = "";
		foreach($fields as $key=>$arr)
		{
			if($arr[$key]=='UNI')
			{
				$fieldstr.=$arr['Key'].";";
			}
		}
		if(substr($fieldstr,strlen($fieldstr)-1,1)==';')
		{
			$fieldstr = substr($fieldstr,0,strlen($fieldstr)-1);
		}
		return $fieldstr;
	}		
	
	protected function _genInstallSQL()
	{
		if($this->m_BuildOptions["gen_mod"]!='1')
		{
			return false;
		}
		$modBaseName= $this->__getModuleName(false);
		
        $targetPath = $moduleDir = MODULE_PATH . "/" . str_replace(".", "/", $modBaseName) ;       
        $targetFile = $targetPath . "/mod.install.sql";
        
		if(is_file($targetFile)){
			$content = file_get_contents($targetFile);
		}
        
		$dbInfo = BizSystem::instance()->getConfiguration()->getDatabaseInfo($this->m_DBName);
		$host 	= $dbInfo["Server"];
        $user	= $dbInfo["User"];
        $pass	= $dbInfo["Password"];
        $db		= $dbInfo["DBName"];
        $port	= $dbInfo["Port"];
        $charset= $dbInfo["Charset"];
		$host   = $host.':'.$port;
		
		$svc = BizSystem::getObject("appbuilder.lib.MySQLDumpService");
		$svc->connect($host,$user,$pass,$db,$charset);
		$svc->droptableifexists=true;
		$svc->get_table_structure($this->m_DBTable);		
		$SQLStr = trim($svc->output)."\n\n";
				
		$content = str_replace($SQLStr, "", $content);
		$content .= $SQLStr;
        file_put_contents($targetFile, $content); 
	}
	
	protected function _genUninstallSQL()
	{
		if($this->m_BuildOptions["gen_mod"]!='1')
		{
			return false;
		}
		$modBaseName= $this->__getModuleName(false);
		
        $targetPath = $moduleDir = MODULE_PATH . "/" . str_replace(".", "/", $modBaseName) ;       
        $targetFile = $targetPath . "/mod.uninstall.sql";
        
		if(is_file($targetFile)){
			$content = file_get_contents($targetFile);
		}
        
		$SQLStr = "DROP TABLE IF EXISTS `$this->m_DBTable`;\n";
		$content = str_replace($SQLStr, "", $content);
		$content .= $SQLStr;
        file_put_contents($targetFile, $content);   
	}
	
	/**
	 * 
	 * Get sort field name for Data Object
	 * @return string fieldName - sort_order
	 */
	protected function _getSortField()
	{
		$fields = $this->_getFieldsInfo();
		foreach($fields as $key=>$arr)
		{
			if($key=="sortorder")
			{
				return $key;
			}
			elseif($key=="sort_order")
			{
				return $key;
			}
		}
	}
	
	protected function _getExtendFeatures()
	{
		$features	=	array(
						"picture"			=>	$this->m_ConfigModule['picture_feature'],
						"location"			=>	$this->m_ConfigModule['location_feature'],
						"changelog"			=>	$this->m_ConfigModule['changelog_feature'],
						"attachment"		=>	$this->m_ConfigModule['attachment_feature'],
						"self_reference"	=>	$this->m_ConfigModule['selfref_feature'],
						"extend"			=>	$this->m_ConfigModule['extend_feature']						
						);	
		return $features;		
	}
	
	/**
	 * 
	 * Get Resource Name
	 * @return string resource - alias of resource
	 */
	protected function _getResourceName()
	{
		return $this->m_ConfigModule['resource_name'];
	}
	
	/**
	 * 
	 * Get array of ACL list
	 * @return array ACLArr
	 */
	protected function _getACLArr()
	{
		if(is_array($this->m_ACLArr))
		{
			return $this->m_ACLArr;
		}
		else
		{
	        $resource = $this->_getResourceName();
	        $acl = $this->m_ConfigModule['acl_type'];
	        switch ($acl) {
	            case 0: //0 - No Access Control 
	                $this->m_ACLArr	   = array( 'access'=>'', 
	                							'manage'=>'', 
	                							'create'=>'', 
	                							'update'=>'', 
	                							'delete'=>''); 
	                break;	        	
	            case 1: //1 - Access and Manage (Default)
	                $this->m_ACLArr	   = array( 'access'=>$resource.'.Access', 
	                							'manage'=>$resource.'.Manage', 
	                                            'create'=>$resource.'.Manage', 
	                                            'update'=>$resource.'.Manage',
	                							'delete'=>$resource.'.Manage'); 
	                break;
	            case 2: //2 - Access, Create, Update and Delete
	                $this->m_ACLArr	   = array( 'access'=>$resource.'.Access', 
	                							'manage'=>$resource.'.Manage', 
	                                            'create'=>$resource.'.Create', 
	                                            'update'=>$resource.'.Update', 
	                                            'delete'=>$resource.'.Delete'); 
	                break;
	        }
	        return $this->m_ACLArr;
		}
	}
	
	protected function _getResourceACL()
	{
		if(is_array($this->m_ResourceACL))
		{
			return $this->m_ResourceACL;
		}
		else
		{
	        $resource = $this->_getResourceName();
	        $acl = $this->m_ConfigModule['acl_type'];
	        switch ($acl) {
	            case 0: //0 - No Access Control 
	                $this->m_ResourceACL[$resource]	= array(); 
	                break;	        	
	            case 1: //1 - Access and Manage (Default)
	                $this->m_ResourceACL[$resource]	= array( "Access"=>"Data access permission of $resource",
	                										 "Manage"=>"Data manage permission of $resource"
	                										); 
	                break;
	            case 2: //2 - Access, Create, Update and Delete
	                $this->m_ResourceACL[$resource]	= array( 'Access'=>"Data access permission of $resource", 					                							
					                                         'Create'=>"Data create permission of $resource", 
					                                         'Update'=>"Data update permission of $resource", 
					                                         'Delete'=>"Data delete permission of $resource"); 
	                break;
	        }
	        return $this->m_ResourceACL;
		}
	}
	
	protected function _getMenuSet()
	{
		$acl		= $this->_getACLArr();
		$resource 	= $this->_getResourceName();
		$features	= $this->_getExtendFeatures();
		$modName	= $this->__getModuleName();
		$modBaseName= $this->__getModuleName(false);
		
		//if it has a sub module name use that, or use table name
		if($this->m_ConfigModule['sub_module_name'])
		{
			$menuName = $this->m_ConfigModule['sub_module_name'];	
			$menuName = ucwords($menuName);
		}
		else
		{
			$tableName = $this->m_DBTable;
			$menuName = str_replace("_", " ", $tableName);
			$menuName = str_replace("-", " ", $menuName);	
			$menuName = ucwords($menuName);	
			$menuName = str_replace(" ", "_", $menuName);
		}
		
		$menu		= array();
		$menu[$resource] = array(
									"name" => $modBaseName.'_'.strtolower($menuName),
									"title" => str_replace("_", " ", $menuName),
									"description" => '',
									"uri" => '',
									"icon_css" => 'icon_'.$resource,
									"acl" => $acl['access'],		
								);
								
		$viewName = $this->__getViewName();
		$menuManageItem = array(
									"name" => $modBaseName.'_'.strtolower($menuName)."_manage",
									"title" => str_replace("_", " ", $menuName).' Manage',
									"description" => 'Manage of '.str_replace("_", " ", $menuName),
									"uri" => '{APP_INDEX}/'.$modBaseName.'/'.$viewName.'_manage',
									"icon_css" => '',
									"acl" => $acl['access'],
								);
		$menuDetailItem = array(
									"name" => $modBaseName.'_'.strtolower($menuName)."_detail",
									"title" => str_replace("_", " ", $menuName).' Detail',
									"description" => 'Detail of '.str_replace("_", " ", $menuName),
									"uri" => '{APP_INDEX}/'.$modBaseName.'/'.$viewName.'_detail',
									"icon_css" => '',
									"acl" => $acl['access'],
								);								
		$menuManageItem['child']['detail'] = $menuDetailItem;
		$menu[$resource]['child']['manage'] = $menuManageItem;
		
		if($features['extend']==1)
        {  
			$menuTypeItem = array(
									"name" => $modBaseName.'_'.strtolower($menuName)."_type",
									"title" => str_replace("_", " ", $menuName).' Type',
									"description" => 'Type of '.str_replace("_", " ", $menuName),
									"uri" => '{APP_INDEX}/'.$modBaseName.'/'.$viewName.'_type',
									"icon_css" => '',
									"acl" => $acl['access'],
									);
			$menu[$resource]['child']['type'] = $menuTypeItem;
        }
		return $menu;
	}
	
	protected function _genFormObj()
	{
		if($this->m_BuildOptions["gen_form_object"]!='1')
		{
			return false;
		}

		//generate list form metadata
		//shared variables
		$templateFile = $this->__getMetaTempPath().'/form/ListForm.xml.tpl';
		$doName 	= $this->m_ConfigModule['object_name'];
		$doDesc 	= $this->m_ConfigModule['object_desc'];					
		$modName 	= $this->__getModuleName(); 	
		$modShortName 	= $this->__getModuleName(false); 			
		$uniqueness = $this->_getUniqueness();
		$sortField  = $this->_getSortField();
		$aclArr     = $this->_getACLArr();		
		$features	= $this->_getExtendFeatures();		
		$doFullName = $modName.'.do.'.$this->m_ConfigModule['object_name'];
		$extendFeature = $features['extend'];
		$formClass  = "EasyForm";				
		$detailViewURL = $modShortName.'/'.$this->__getViewName().'_detail';
		
		$formListName 	= $this->__getObjectName().'ListForm';
		$formListFullName = $modName.'.form.'.$formListName;				
		$formDetailName  	= $this->__getObjectName().'DetailForm';
		$formDetailFullName  = $modName.'.form.'.$formDetailName;
		$formCopyName  	= $this->__getObjectName().'CopyForm';
		$formCopyFullName  = $modName.'.form.'.$formCopyName;		
		$formEditName  	= $this->__getObjectName().'EditForm';
		$formEditFullName  = $modName.'.form.'.$formEditName;		
		$formNewName  	= $this->__getObjectName().'NewForm';
		$formNewFullName  = $modName.'.form.'.$formNewName;
		
		
		$messageFile = "";
		if($this->m_GeneratedFiles['MessageFiles']['MessageFile']!='')
		{
			$messageFile = basename($this->m_GeneratedFiles['MessageFiles']['MessageFile']);
		}		
		
		if($this->m_ConfigModule['data_perm']=='0')
		{
			$doPermControl = "N";
		}
		else
		{
			$doPermControl = "Y";
		}		
		
		if(CLI){echo "Start generate form metadata $formName." . PHP_EOL;}
        $targetPath = $moduleDir = MODULE_PATH . "/" . str_replace(".", "/", $modName) . "/form";
        if (!file_exists($targetPath))
        {
            if(CLI){echo "Create directory $targetPath" . PHP_EOL;}
            mkdir($targetPath, 0777, true);
        }

	    if($features['extend']==1)
        {        	        	
        	$this->_genExtendTypeForm();    
        	$typeDoFullName = $this->m_TypeDOFullName;  
        	  	
        }
        
        if( $features['extend']==1 || $doPermControl=='Y' )
        {
        	$formTemplate = "form_grid_adv.tpl.html";
        }
        else
        {
        	$formTemplate = "form_grid.tpl.html";  
        }

        $smarty = BizSystem::getSmartyTemplate();
        
        $smarty->assign("do_full_name", $doFullName);
        $smarty->assign("do_name", $doName);                   
        $smarty->assign("fields", $this->m_DBFieldsInfo);
        $smarty->assign("search_field", $this->m_DBSearchField);      
        $smarty->assign("do_perm_control", $doPermControl);                               
        $smarty->assign("features", $features);
        $smarty->assign("acl", $aclArr);     
        $smarty->assign("detail_view_url", $detailViewURL);		
        	
		$smarty->assign("new_form_full_name", 	$formNewFullName);  
		$smarty->assign("new_form_name", 		$formNewName);  
        $smarty->assign("copy_form_full_name", 	$formCopyFullName);  
		$smarty->assign("copy_form_name", 		$formCopyName);
		$smarty->assign("edit_form_full_name", 	$formEditFullName);  
		$smarty->assign("edit_form_name", 		$formEditName);
		$smarty->assign("detail_form_full_name",$formDetailFullName);  
		$smarty->assign("detail_form_name", 	$formDetailName);
		$smarty->assign("list_form_full_name", 	$formListFullName);  
		$smarty->assign("list_form_name", 		$formListName);
		
		//form specified variables
		$formTitle  = $this->__getFormName()." Management";
		$formDescription = $this->m_ConfigModule['object_desc'];
		
		
		
		
		$eventName = $this->__getObjectName();		
		$formIcon = "{RESOURCE_URL}/$modShortName/images/".$this->__getObjectFileName().'_list.png';
		$shareIcons = array(
			"icon_private"				=>	'{RESOURCE_URL}/'.$modShortName.'/images/icon_'.$modShortName.'_private.gif',
			"icon_shared"				=>	'{RESOURCE_URL}/'.$modShortName.'/images/icon_'.$modShortName.'_shared.gif',
			"icon_assigned"				=>	'{RESOURCE_URL}/'.$modShortName.'/images/icon_'.$modShortName.'_assigned.gif',
			"icon_shared_distributed"	=>	'{RESOURCE_URL}/'.$modShortName.'/images/icon_'.$modShortName.'_distributed.gif',
			"icon_shared_group"			=>	'{RESOURCE_URL}/'.$modShortName.'/images/icon_'.$modShortName.'_shared_group.gif',
			"icon_shared_other"			=>	'{RESOURCE_URL}/'.$modShortName.'/images/icon_'.$modShortName.'_shared_other.gif'
		);
		
        $smarty->assign("form_name", 		$formListName);
        $smarty->assign("form_class",		$formClass);
        $smarty->assign("form_icon", 		$formIcon);
        $smarty->assign("form_title", 		$formTitle);
        $smarty->assign("form_description", $formDescription);
        $smarty->assign("form_template",	$formTemplate);
		$smarty->assign("form_do", 			$doFullName);
		$smarty->assign("form_type_do", 	$typeDoFullName);		
		$smarty->assign("event_name",		$eventName);
		$smarty->assign("message_file",		$messageFile);
		$smarty->assign("share_icons", 		$shareIcons);
        
		$content = $smarty->fetch($templateFile);
                
        $targetFile = $targetPath . "/" . $formListName . ".xml";
        file_put_contents($targetFile, $content);       
		$this->m_GeneratedFiles['FormObjFiles']['ListForm']=str_replace(MODULE_PATH,"",$targetFile);				
		
		
		
		//generate Detail form metadata		
		$formTitle  = $this->__getFormName()." Detail";	
		
		$formTemplate = "form_detail.tpl.html";		
		
		$templateFile = $this->__getMetaTempPath().'/form/DetailForm.xml.tpl';
		$smarty->assign("form_name", 		$formDetailNameName);
        $smarty->assign("form_class",		$formClass);
        $smarty->assign("form_icon", 		$formIcon);
        $smarty->assign("form_title", 		$formTitle);
        $smarty->assign("form_description", $formDescription);
        $smarty->assign("form_template",	$formTemplate);
		$smarty->assign("form_do", 			$doFullName);
		$smarty->assign("form_type_do", 	$typeDoFullName);		
		$smarty->assign("event_name",		$eventName);
		$smarty->assign("message_file",		$messageFile);        
		$content = $smarty->fetch($templateFile);
        $targetFile = $targetPath . "/" . $formDetailName . ".xml";
        file_put_contents($targetFile, $content);     
		$this->m_GeneratedFiles['FormObjFiles']['DetailForm']=str_replace(MODULE_PATH,"",$targetFile);

		
		//generate New form metadata		
		$this->m_GeneratedFiles['FormObjFiles']['NewForm']=str_replace(MODULE_PATH,"",$targetFile);
		
		$this->m_GeneratedFiles['FormObjFiles']['CopyForm']=str_replace(MODULE_PATH,"",$targetFile);
		
		$this->m_GeneratedFiles['FormObjFiles']['EditForm']=str_replace(MODULE_PATH,"",$targetFile);
		
		
		
	}
	
	protected function _genViewObj()
	{
		if($this->m_BuildOptions["gen_view_object"]!='1')
		{
			return false;
		}
		
		$modName 	= $this->__getModuleName(); 	
		$modBaseName= $this->__getModuleName(false);
		$features	= $this->_getExtendFeatures();	
		$aclArr     = $this->_getACLArr();
		
        $targetPath = $moduleDir = MODULE_PATH . "/" . str_replace(".", "/", $modBaseName) . "/view";
        if (!file_exists($targetPath))
        {
            if(CLI){echo "Create directory $targetPath" . PHP_EOL;}
            mkdir($targetPath, 0777, true);
        }
        
		//generate detail view
		$templateFile = $this->__getMetaTempPath().'/view/DetailView.xml.tpl';
		$viewName 	= $this->__getObjectName().'DetailView';
		$viewDesc 	= "Detail View of ".$this->m_ConfigModule['object_desc'];		
		$defaultFormName = $modName.'.form.'.$this->__getObjectName().'DetailForm';
		
		if(CLI){echo "Start generate view object $viewName." . PHP_EOL;}
        $smarty = BizSystem::getSmartyTemplate();
        $smarty->assign("view_name", $viewName);
        $smarty->assign("view_desc", $viewDesc);        
        $smarty->assign("default_form_name", $defaultFormName);
        $smarty->assign("acl", $aclArr);
        $content = $smarty->fetch($templateFile);
                        
        $targetFile = $targetPath . "/" . $viewName . ".xml";
        file_put_contents($targetFile, $content);      
		$this->m_GeneratedFiles['ViewObjFiles']['DetailView']=str_replace(MODULE_PATH,"",$targetFile);	 	
		
		//generate list view
		$templateFile = $this->__getMetaTempPath().'/view/ManageView.xml.tpl';
		$viewName 	= $this->__getObjectName().'ManageView';
		$viewDesc 	= $this->m_ConfigModule['object_desc']." Management";
		$defaultFormName = $modName.'.form.'.$this->__getObjectName().'ListForm';
		
		if(CLI){echo "Start generate view object $viewName." . PHP_EOL;}
		$smarty = BizSystem::getSmartyTemplate();
        $smarty->assign("view_name", $viewName);
        $smarty->assign("view_desc", $viewDesc);        
        $smarty->assign("default_form_name", $defaultFormName);
        $smarty->assign("acl", $aclArr);
        $content = $smarty->fetch($templateFile);
                        
        $targetFile = $targetPath . "/" . $viewName . ".xml";
        file_put_contents($targetFile, $content);      
		$this->m_GeneratedFiles['ViewObjFiles']['ManageView']=str_replace(MODULE_PATH,"",$targetFile);
		
		//generate type manager view
		if($features['extend']==1)
        {        	
        	$this->_genExtendTypeView();
        }
		
	}

	protected function _genTemplateFiles()
	{
		if($this->m_BuildOptions["gen_template_files"]!='1')
		{
			return false;
		}
		$modName 	= $this->__getModuleName(false);
		$templateFiles = $this->__getMetaTempPath().'/template/';
		$targetPath = MODULE_PATH . "/" . str_replace(".", "/", $modName) . "/template";										
		return $this->__recursiveCopy($templateFiles, $targetPath);
	}	
	
	protected function _genResourceFiles()
	{
		if($this->m_BuildOptions["gen_template_files"]!='1')
		{
			return false;
		}
		$modName 	= $this->__getModuleName(false);
		$modShortName = $this->__getModuleName(false);
		$templateFiles = $this->__getMetaTempPath().'/resource/';
		$targetPath = MODULE_PATH . "/" . str_replace(".", "/", $modName) . "/resource";
		$this->__recursiveCopy($templateFiles, $targetPath);
		
		$icons = array(
			"mod_list.png"						=> 	"images/".$this->__getObjectFileName().'_list.png',
			"mod_add.png"						=> 	"images/".$this->__getObjectFileName().'_add.png',
			"mod_edit.png"						=> 	"images/".$this->__getObjectFileName().'_edit.png',
			"mod_copy.png"						=> 	"images/".$this->__getObjectFileName().'_copy.png',
			"mod_detail.png"					=> 	"images/".$this->__getObjectFileName().'_detail.png',
			"icon_data_private.gif"				=>	'images/icon_'.$modShortName.'_private.gif',
			"icon_data_shared.gif"				=>	'images/icon_'.$modShortName.'_shared.gif',
			"icon_data_assigned.gif"			=>	'images/icon_'.$modShortName.'_assigned.gif',
			"icon_data_shared_distributed.gif"	=>	'images/icon_'.$modShortName.'_distributed.gif',
			"icon_data_shared_group.gif"		=>	'images/icon_'.$modShortName.'_shared_group.gif',
			"icon_data_shared_other.gif"		=>	'images/icon_'.$modShortName.'_shared_other.gif'
		);		
		foreach($icons as $key=>$value)
		{
			@copy($templateFiles.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.$key, 
					$targetPath.DIRECTORY_SEPARATOR.$value);
		}
		return ;
	}		
	
	protected function _genDashboardFiles()
	{
		if($this->m_BuildOptions["gen_dashboard"]!='1')
		{
			return false;
		}

		//Genereate Dashboard Form
		$modName 	  = $this->__getModuleName();
		$modBaseName  = $this->__getModuleName(false);
		
		$targetPath = MODULE_PATH . "/" . str_replace(".", "/", $modBaseName) . "/widget";
        if (!file_exists($targetPath))
        {
            if(CLI){echo "Create directory $targetPath" . PHP_EOL;}
            mkdir($targetPath, 0777, true);
        }
		
		$templateFile = $this->__getMetaTempPath().'/widget/DashboardWidget.xml.tpl';
		$formName 	= 'DashboardForm';
		$formTitle	= ucfirst($modBaseName).' Dashboard';

		//generate detail view
		if(CLI){echo "Start generate form object $formName." . PHP_EOL;}
        $smarty = BizSystem::getSmartyTemplate();
        $smarty->assign("form_name", 	$formName);
        $smarty->assign("form_title", 	$formTitle);
        $smarty->assign("form_css", 	strtolower($modBaseName));        
        $smarty->assign("mod_name", 	$modBaseName);        
        $content = $smarty->fetch($templateFile);
                        
        $targetFile = $targetPath . "/" . $formName . ".xml";
        file_put_contents($targetFile, $content);      
		$this->m_GeneratedFiles['FormObjFiles']['DashbaordForm']=str_replace(MODULE_PATH,"",$targetFile);	
		
		//Genereate Dashboard View				
        $targetPath = $moduleDir = MODULE_PATH . "/" . str_replace(".", "/", $modBaseName) . "/view";
        if (!file_exists($targetPath))
        {
            if(CLI){echo "Create directory $targetPath" . PHP_EOL;}
            mkdir($targetPath, 0777, true);
        }
        
		//generate detail view
		$templateFile = $this->__getMetaTempPath().'/view/DashboardView.xml.tpl';
		$viewName 	= 'DashbaordView';
		$viewDesc 	= "Dashboard View of ".$this->m_ConfigModule['object_desc'];		
		$defaultFormName = $modBaseName.'.widget.DashboardForm';
		
		if(CLI){echo "Start generate view object $viewName." . PHP_EOL;}
        $smarty = BizSystem::getSmartyTemplate();
        $smarty->assign("view_name", $viewName);
        $smarty->assign("view_desc", $viewDesc);        
        $smarty->assign("default_form_name", $defaultFormName);
        $smarty->assign("acl", $aclArr);
        $content = $smarty->fetch($templateFile);
                        
        $targetFile = $targetPath . "/" . $viewName . ".xml";
        file_put_contents($targetFile, $content);      
		$this->m_GeneratedFiles['ViewObjFiles']['DashbaordView']=str_replace(MODULE_PATH,"",$targetFile);	 	
				
	}
	
	private function __recursiveCopy($path, $dest){
	   if( is_dir($path) )
       {
            @mkdir( $dest );
            $objects = scandir($path);
            if( sizeof($objects) > 0 )
            {
                foreach( $objects as $file )
                {
                    if( $file == "." || $file == ".." || substr($file,0,1)=='.' )
                        continue;
                    // go on
                    if( is_dir( $path.DIRECTORY_SEPARATOR.$file ) )
                    {
                        $this->__recursiveCopy( $path.DIRECTORY_SEPARATOR.$file, $dest.DIRECTORY_SEPARATOR.$file );
                    }
                    else
                    {
                        copy( $path.DIRECTORY_SEPARATOR.$file, $dest.DIRECTORY_SEPARATOR.$file );
                    }
                }
            }
            return true;
        }
        elseif( is_file($path) )
        {
            return copy($path, $dest);
        }
        else
        {
            return false;
        }
	} 	
	
	protected function _genMessageFiles()
	{
		if($this->m_BuildOptions["gen_message_file"]!='1')
		{
			return false;
		}
		$modName 	= $this->__getModuleName(); 	
		$modBaseName= $this->__getModuleName(false);
		$objName	=  $this->__getObjectName();
		
        $targetPath = $moduleDir = MODULE_PATH . "/" . str_replace(".", "/", $modBaseName) . "/message";
        if (!file_exists($targetPath))
        {
            if(CLI){echo "Create directory $targetPath" . PHP_EOL;}
            mkdir($targetPath, 0777, true);
        }
        
        $templateFile = $this->__getMetaTempPath().'/message/Messages.ini.tpl';
        $content = file_get_contents($templateFile);
        
        $targetFile = $targetPath . "/" . $objName . ".ini";
        file_put_contents($targetFile, $content);      
		$this->m_GeneratedFiles['MessageFiles']['MessageFile']=str_replace(MODULE_PATH,"",$targetFile);
		if(CLI){echo "Start generate message file $objName.ini ." . PHP_EOL;}
	}	
	
	protected function _genModuleFile()
	{
		if($this->m_BuildOptions["gen_mod"]!='1')
		{
			return false;
		}		
		
		//generate mod loader
		$modName 	= $this->__getModuleName(false);
        $targetPath = MODULE_PATH . "/" . str_replace(".", "/", $modName) . "/lib";
        if (!file_exists($targetPath))
        {
            if(CLI){echo "Create directory $targetPath" . PHP_EOL;}
            mkdir($targetPath, 0777, true);
        }

        
        $smarty = BizSystem::getSmartyTemplate();                
        $smarty->assign("mod_name", ucfirst($modName));
		$templateFile = $this->__getMetaTempPath().'/lib/ModLoadHandler.php.tpl';
		
        $content = $smarty->fetch($templateFile);                
        $targetFile = $targetPath . "/" . ucfirst($modName) . "LoadHandler.php";
        file_put_contents($targetFile, $content);        

        $resourceACL = $this->_getResourceACL();
        $menu		 = $this->_getMenuSet();
        
        //load XML file if it exists
        $targetFile = MODULE_PATH . "/" . str_replace(".", "/", $modName) . "/mod.xml";
        if(is_file($targetFile))
        {
        	$metadata = file_get_contents($targetFile);
			$xmldata = new SimpleXMLElement($metadata);		
			foreach ($xmldata as $key=>$value){

			}
        }
        
        //generate mod xml file
        $smarty = BizSystem::getSmartyTemplate();
        $templateFile = $this->__getMetaTempPath().'/mod.xml.tpl';
        $targetPath = MODULE_PATH . "/" . str_replace(".", "/", $modName) ;
        $modDescription	=	$this->m_BuildOptions['mod_desc'];
        $modAuthor		=	$this->m_BuildOptions['mod_author'];
        $modVersion		=	$this->m_BuildOptions['mod_version'];
        $modLoader		=	$this->m_BuildOptions['mod_author'];
        $modLabel		=	ucfirst($modName);
        
        //gen_dashboard
        $modRootURI		=	"{APP_INDEX}/$modName/dashboard";
        
        $smarty->assign("mod_name", 		$modName);
        $smarty->assign("mod_label", 		$modLabel);
        $smarty->assign("mod_description", 	$modDescription);
        $smarty->assign("mod_author", 		$modAuthor);
        $smarty->assign("mod_version", 		$modVersion);
        $smarty->assign("mod_loader", 		ucfirst($modName) . "LoadHandler.php");
        $smarty->assign("mod_root_uri", 	$modRootURI);
        $smarty->assign("resource_acl",		$resourceACL);
        $smarty->assign("menu",				$menu);
        
        
        
        $content = $smarty->fetch($templateFile);        
        $targetFile = $targetPath . "/mod.xml";
        file_put_contents($targetFile, $content);    
        
        $this->m_GeneratedFiles['ModXMLFile']=str_replace(MODULE_PATH,"",$targetFile);
        
        //generate mod.install.sql
        $this->_genInstallSQL();
        
        //generate mod.uninstall.sql
        $this->_genUninstallSQL();
	}	

	
	private function __getObjectName()
	{
		$tableName = $this->m_DBTable;
		$name = str_replace("_", " ", $tableName);
		$name = str_replace("-", " ", $name);
		$name = ucwords($name);
		$name = str_replace(" ", "", $name);
		return $name;
	}
	
	private function __getObjectFileName()
	{
		$tableName = $this->m_DBTable;
		$name = str_replace("_", " ", $tableName);
		$name = str_replace("-", " ", $name);		
		$name = str_replace(" ", "_", $name);
		$name = strtolower($name);
		return $name;
	}
	
	
	private function __getViewName()
	{
		$tableName = $this->m_DBTable;
		$name = str_replace("_", " ", $tableName);
		$name = str_replace("-", " ", $name);
		$name = ucwords($name);
		$name = str_replace(" View", "", $name);
		$name = str_replace(" ", "_", $name);
		$name = strtolower($name);
		return $name;
	}	
	
	private function __getFormName()
	{
		switch($this->m_BuildOptions['naming_convention'])
		{
			case "name":
				$tableName = $this->m_DBTable;
				$name = str_replace("_", " ", $tableName);
				$name = str_replace("-", " ", $name);
				$name = ucwords($name);
				break;				
			case "comment":
				$tableName = $this->m_DBTable;
				$db 	= BizSystem::dbConnection($this->m_DBName);
				$tableInfos = $db->fetchAssoc("SHOW TABLE STATUS WHERE Name='$tableName'");
				$name = $tableInfos[$tableName]['Comment'];
				if(!$name)
				{
					$tableName = $this->m_DBTable;
					$name = str_replace("_", " ", $tableName);
					$name = str_replace("-", " ", $name);
					$name = ucwords($name);
				}
				break;
		}		
		return $name;
	}	
	
	private function __getMetaTempPath()
	{
		$this->m_MetaTempPath = MODULE_PATH.DIRECTORY_SEPARATOR.'appbuilder'.
											DIRECTORY_SEPARATOR.'resource'.
											DIRECTORY_SEPARATOR.'module_template';
		return $this->m_MetaTempPath; 
	}
	
	private function __getModuleName($processSubModule = true)
	{
		if($this->m_ConfigModule['module_type']==1)
		{
			$result = $this->m_ConfigModule['module_name_create'];
		}
		else
		{
			$result = $this->m_ConfigModule['module_name_exists'];
		}
		if( $processSubModule == true )
		{
			$result .= '.'.$this->m_ConfigModule['sub_module_name'];
		}
		return $result;
	}
	
	private function __getFieldDesc($fieldArr)
	{		
		switch($this->m_BuildOptions['naming_convention'])
		{
			case "name":
				$result = str_replace("-",	" ",	$fieldArr['Field']);
				$result = str_replace("_",	" ",	$result);
				$result = ucwords($result);
				break;				
			case "comment":
				$result = $fieldArr['Comment'];
				if(!$result)
				{
					$result = str_replace("-",	" ",	$fieldArr['Field']);
					$result = str_replace("_",	" ",	$result);
					$result = ucwords($result);
				}
				break;
		}
		return $result;
	}
	
	private function __convertDataType($type)
	{
		if(strpos($type,"("))
		{
			$type = substr($type,0,strpos($type,"("));
		}		
		switch ($type)
        {
            case "date":
                $type = "Date";
                break;

            case "timestamp":
            case "datetime":
                $type = "Datetime";
                break;

            case "int":
            case "float":
            case "bigint":
            case "tinyint":
                $type = "Number";
                break;

            case "text":
            case "shorttext":
            case "longtext":
        	    default:
                $type = "Text";
                break;
       }
       return $type;
	}
}
?>