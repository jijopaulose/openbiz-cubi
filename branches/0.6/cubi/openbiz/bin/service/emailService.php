<?php
/**
 * PHPOpenBiz Framework
 *
 * LICENSE
 *
 * This source file is subject to the BSD license that is bundled
 * with this package in the file LICENSE.txt.
 *
 * @package   openbiz.bin.service
 * @copyright Copyright (c) 2005-2011, Rocky Swen
 * @license   http://www.opensource.org/licenses/bsd-license.php
 * @link      http://www.phpopenbiz.org/
 * @version   $Id: emailService.php 2553 2010-11-21 08:36:48Z mr_a_ton $
 */

/**
 * The email service provides access to a Zend_Mail object in conjunction with a predefined email account.
 * One can simply request the configured email object and work directly with the Zend_Mail API
 * or use one of several convenience functions.
 * Additional functions bundled with the class are to run email through a template filter
 * or to log attempted emails to a text file or DB Table.
 *
 * @package   openbiz.bin.service
 * @author    Rocky Swen
 * @copyright Copyright (c) 2005-2009, Rocky Swen
 * @access    public
 */
class emailService extends MetaObject
{
    /**
     * An array of Account objects
     *
     * @var array
     */
    public $m_Accounts = null;

    /**
     * Error Message returned by mail object
     *
     * @var string
     */
    private $_errorMessage;

    /**
     * The default account to be used when issuing email
     *
     * @var unknown_type
     */
    protected $m_UseAccount;

    /**
     * The mail object generated by Zend_Mail
     *
     * @var Zend_Mail
     */
    private $_mail;

    /**
     * Is logging enabled?
     *
     * @param boolean
     */
    private $_logEnabled = null;

    /**
     * How should log entries be stored?  Can either use the default logging service or
     *  a BizDataObject in order to log a DB table
     *
     * @param boolean
     */
    private $_logType = 'DEFAULT';

    /**
     * In the case of DB logging, what BizDataObject object should be used to store log entries?
     *
     * @param object
     */
    private $_logObject;

    /**
     * Initialize emailService with xml array metadata
     *
     * @param array $xmlArr
     * @return void
     */
    function __construct(&$xmlArr)
    {
        $this->readMetadata($xmlArr);
        $this->_constructMail();
        $acct = $this->m_Accounts->current();
        if (! $acct)
            return; //TODO Throw exception
        $this->useAccount($acct->m_Name);
    }

    /**
     * Read array meta-data, and store to meta-object
     *
     * @param array $xmlArr
     * @return void
     */
    protected function readMetadata(&$xmlArr)
    {
        parent::readMetaData($xmlArr);
        $this->m_Accounts = new MetaIterator($xmlArr["PLUGINSERVICE"]["ACCOUNTS"]["ACCOUNT"], "EmailAccount");
        $this->_logEnabled = $xmlArr["PLUGINSERVICE"]["LOGGING"]["ATTRIBUTES"]["ENABLED"];
        if ($this->_logEnabled)
        {
            $this->_logType = $xmlArr["PLUGINSERVICE"]["LOGGING"]["ATTRIBUTES"]["TYPE"];
            $this->_logObject = $xmlArr["PLUGINSERVICE"]["LOGGING"]["ATTRIBUTES"]["OBJECT"];
        }
    }

    /**
     * Get error message
     *
     * @return string
     */
    public function getErrorMsg()
    {
        return $this->_errorMessage;
    }

    /**
     * Will construct the initial mail object
     *
     * @return void
     */
    private function _constructMail ()
    {
        require_once 'Zend/Mail.php';
        $this->_mail = new Zend_Mail();
    }

    /**
     * Will set the Default Transport object for the mail object based on the supplied accountname
     *
     * @param string $accountName
     * @return boolean TRUE/FALSE
     */
    public function useAccount($accountName)
    {
        //If a mail object exists, overwrite with new object
        if ($this->_mail)
            $this->_constructMail();

        $this->m_UseAccount = $accountName;
        $account = $this->m_Accounts->get($accountName);

        if ($account->m_IsSMTP == "Y")
        {
            require_once 'Zend/Mail/Transport/Smtp.php';
            if ($account->m_SMTPAuth == "Y")
            {
                $config = array('auth' =>'login', 'username'=>$account->m_Username , 'password'=>$account->m_Password, "port"=>$account->m_Port, 'ssl'=>$account->m_SSL);
            }
            else
            {
                $config = array();
            }
            $mailTransport = new Zend_Mail_Transport_Smtp($account->m_Host, $config);
            $this->_mail->setDefaultTransport($mailTransport);
        }
        else 
        {
            require_once 'Zend/Mail/Transport/Sendmail.php';
            $mailTransport = new Zend_Mail_Transport_Sendmail();
            $this->_mail->setDefaultTransport($mailTransport);
        }
        $this->_mail->setFrom($account->m_FromEmail, $account->m_FromName);
    }

    /**
     * A convenience function that will issue an email based on the parameter provided
     * Will log email attempts but will NOT run the body through a template
     *
     * @param array $TOs
     * @param array $CCs
     * @param array $BCCs
     * @param array $subject
     * @param array $body
     * @param array $attachments
     * @param boolean $isHTML
     * @return boolean $result - TRUE on success, FALSE on failure
     */
    public function sendEmail ($TOs = null, $CCs = null, $BCCs = null, $subject, $body, $attachments = null, $isHTML = false)
    {
        $mail = $this->_mail;
        // add TO addresses
        if ($TOs)
        {
            foreach ($TOs as $to)
            {
            	if(is_array($to))
            	{
                	$mail->addTo($to['email'],$to['name']);
            	}
            	else
            	{
            		$mail->addTo($to);
            	}
            }
        }
        // add CC addresses
        if ($CCs)
        {
            foreach ($CCs as $cc)
            {
                if(is_array($cc))
                {
            		$mail->AddCC($cc['email'],$cc['name']);
                }
                else
                {
                	$mail->AddCC($cc);
                }
            }
        }
        // add BCC addresses
        if ($BCCs)
        {
            foreach ($BCCs as $bcc)
            {
                if(is_array($bcc))
                {
            		$mail->AddBCC($bcc['email'],$bcc['name']);
                }
                else
                {
                	$mail->AddBCC($bcc);
                }
            }
        }
        // add attachments
        if ($attachments)
            foreach ($attachments as $att)
                $mail->CreateAttachment(file_get_contents($att));
        $mail->setSubject($subject);
        $body = str_replace("\\n", "\n", $body);
        if ($isHTML == TRUE)
        {
            $mail->setBodyHTML($body);
        }
        else
        {
            $mail->setBodyText($body);
        }

        try
        {
            $result = $mail->Send(); //Will throw an exception if sending fails
            $this->logEmail('Success', $subject, $body, $TOs, $CCs, $BCCs);
            return TRUE;
        }
        catch (Exception $e)
        {
            $result = "ERROR: ".$e->getMessage();
            $this->logEmail($result, $subject, $body, $TOs, $CCs, $BCCs);
            return FALSE;
        }
    }

    /**
     * Log that an email attemp was made.
     * We assume it was successfull, since Zend_Mail throws an exception otherwise
     *
     * @param string $subject
     * @param array $To
     * @param array $CCs
     * @param array $BCCs
     * @return mixed boolean|string|void
     */
    public function logEmail($result, $subject, $body = NULL, $TOs = NULL, $CCs = NULL, $BCCs = NULL)
    {
        //Log the email attempt
        $recipients = '';
    // add TO addresses
        if ($TOs)
        {
            foreach ($TOs as $to)
            {
            	if(is_array($to))
            	{                	
                	$recipients .= $to['name']."<".$to['email'].">;";
            	}
            	else
            	{
            		$recipients .= $to.";";
            	}
            }
        }
        // add CC addresses
        if ($CCs)
        {
            foreach ($CCs as $cc)
            {
                if(is_array($cc))
                {
            		$recipients .= $cc['name']."<".$cc['email'].">;";
                }
                else
                {
                	$recipients .= $cc.";";
                }
            }
        }
        // add BCC addresses
        if ($BCCs)
        {
            foreach ($BCCs as $bcc)
            {
                if(is_array($bcc))
                {
            		$recipients .= $bcc['name']."<".$bcc['email'].">;";
                }
                else
                {
                	$recipients .= $bcc.";";
                }
            }
        }
        if ($this->_logType == 'DB')
        {
            $account = $this->m_Accounts->get($this->m_UseAccount);
            $sender_name = $account->m_FromName;
            $sender = $account->m_FromEmail;
            
            // Store the message log
            $boMessageLog = BizSystem::getObject($this->_logObject);
            $mlArr = $boMessageLog->newRecord();            
            $mlArr["sender"] = $sender;
            $mlArr["sender_name"] = $sender_name;
            $mlArr["recipients"] = $recipients;
            $mlArr["subject"] = $subject;
            $mlArr["content"] = $body;            
            $mlArr["result"] = $result;
            //Escape Data since this may contain quotes or other goodies
            foreach ($mlArr as $key => $value)
            {
                $mlArr[$key] = addslashes($value);
            }

            $ok = $boMessageLog->insertRecord($mlArr);
            if (! $ok)
            {
                return $boMessageLog->getErrorMessage();
            }
            else
            {
                return TRUE;
            }
        }
        else
        {
            $back_trace = debug_backtrace();
            if ($result == 'Success')
            {
                $logNum = LOG_INFO;
            } else
            {
                $logNum = LOG_ERR;
            }
            BizSystem::log($logNum, "EmailService", "Sent email with subject - \"$subject\" and body - $body to - $recipients with result $result.", NULL, $back_trace);
        }
    }
}

/**
 * An object that stores email account data from the service config file.
 *
 * @package   openbiz.bin.service
 * @author    Rocky Swen
 * @copyright Copyright (c) 2005-2009, Rocky Swen
 * @access    public
 */
class EmailAccount extends MetaObject
{
    public $m_Name;
    public $m_Host;
    public $m_Port;
    public $m_SSL;
    public $m_FromName;
    public $m_FromEmail;
    public $m_IsSMTP;
    public $m_SMTPAuth;
    public $m_Username;
    public $m_Password;

    /**
     * Load the supplied array of config data into the object
     *
     * @param array $xmlArr
     */
    public function __construct ($xmlArr)
    {
        $this->m_Name = $xmlArr["ATTRIBUTES"]["NAME"];
        $this->m_Host = $xmlArr["ATTRIBUTES"]["HOST"];
        $this->m_Port = $xmlArr["ATTRIBUTES"]["PORT"];
        $this->m_SSL = $xmlArr["ATTRIBUTES"]["SSL"];
        $this->m_FromName = $xmlArr["ATTRIBUTES"]["FROMNAME"];
        $this->m_FromEmail = $xmlArr["ATTRIBUTES"]["FROMEMAIL"];
        $this->m_IsSMTP = $xmlArr["ATTRIBUTES"]["ISSMTP"];
        $this->m_SMTPAuth = $xmlArr["ATTRIBUTES"]["SMTPAUTH"];
        $this->m_Username = $xmlArr["ATTRIBUTES"]["USERNAME"];
        $this->m_Password = $xmlArr["ATTRIBUTES"]["PASSWORD"];
    }
}