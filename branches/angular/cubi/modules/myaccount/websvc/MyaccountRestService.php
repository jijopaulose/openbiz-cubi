<?php

include_once MODULE_PATH.'/websvc/lib/RestService.php';
include_once OPENBIZ_BIN.'data/private/BizDataObj_SQLHelper.php';

class MyaccountRestService extends RestService
{
	protected $resourceDOMap = array('users'=>'system.do.UserDO');
	
	/*
	 * Query by page, rows, sort, sorder
	 *
	 * @param string $resource
	 * @param Object $request, Slim Request object
	 * @param Object $response, Slim Response object
     * @return void 
	 */
	public function query($resource, $request, $response)
    {
		$profile= BizSystem::getUserProfile();
		$userId = $profile['Id'];
	
		return parent::get('users', $userId, $request, $response);
	}
	
	/*
	 * Update data record
	 *
	 * @param string $resource
	 * @param Object $request, Slim Request object
	 * @param Object $response, Slim Response object
     * @return void 
	 */
	public function put($resource, $id, $request, $response)
    {
		$inputRecord = json_decode($request->getBody());
		try {
			$this->validateInputs($inputRecord);
		} catch (ValidationException $e) {
			$response->status(400);
			$errmsg = implode("\n",$e->m_Errors);
			$response->body($errmsg);
			return;
		}
		$DOName = $this->getDOName($resource);
		if (empty($DOName)) {
			$response->status(404);
			$response->body("Resource '$resource' is not found.");
			return;
		}
		$dataObj = BizSystem::getObject($DOName);
		$rec = $dataObj->fetchById($id);
		if (empty($rec)) {
			$response->status(400);
			$response->body("No data is found for $resource $id");
			return;
		}
		$dataRec = new DataRecord($rec, $dataObj);
		$dataRec['password'] = hash(HASH_ALG, $inputRecord->password_new);
        try {
           $dataRec->save();
        }
        catch (ValidationException $e) {
            $response->status(400);
			$errmsg = implode("\n",$e->m_Errors);
			$response->body($errmsg);
			return;
        }
        catch (BDOException $e) {
            $response->status(400);
			$response->body($e->getMessage());
			return;
        }

		$format = strtolower($request->params('format'));
		return $this->setResponse($dataRec->toArray(), $response, $format);
    }
	
	protected function validateInputs($inputRecord) {
	
		$profile= BizSystem::getUserProfile();
		$userId = $profile['Id'];
		$username = $profile['username'];
		
		//check old password
        $old_password = $inputRecord->password_old;
		$svcobj = BizSystem::getService(AUTH_SERVICE);
    	$result = $svcobj->authenticateUser($username,$old_password);
        if(!$result){
        	$errors = array("fld_password_old"=>"Invalid old password");
        	throw new ValidationException($errors);
        }
		
		// check repeat password
        $password_new = $inputRecord->password_new;  
		$password_repeat = $inputRecord->password_repeat;
		if ($password_new != $password_repeat) {
			$errors = array("fld_password_repeat"=>"Repeat password is not same as the password");
        	throw new ValidationException($errors);
		}
		return true;
	}
}
?>