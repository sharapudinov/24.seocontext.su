<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use \Bitrix\Main\Loader,
	\Bitrix\Main\LoaderException,
	\Bitrix\Main\Localization\Loc,
	\Bitrix\Main\HttpApplication;


class ImOpenLinesComponentLinesEdit extends CBitrixComponent
{
	/** @var \Bitrix\ImOpenlines\Security\Permissions */
	protected $userPermissions;
	
	protected function checkModules()
	{
		if (!Loader::includeModule('imopenlines'))
		{
			\ShowError(Loc::getMessage('OL_COMPONENT_MODULE_NOT_INSTALLED'));
			return false;
		}
		return true;
	}

	private function updateLine()
	{
		if (!\check_bitrix_sessid())
			return false;
		
		$request = HttpApplication::getInstance()->getContext()->getRequest();
		$post = $request->getPostList()->toArray();
		
		$configManager = new \Bitrix\ImOpenLines\Config();
		if (!$configManager->canEditLine($post['CONFIG_ID']))
			return false;
		
		$boolParams = Array(
			'CRM',
			'CRM_FORWARD',
			'CRM_TRANSFER_CHANGE',
			'TIMEMAN',
			'RECORDING',
			'WORKTIME_ENABLE',
			'CATEGORY_ENABLE',
			'WELCOME_MESSAGE',
			'WELCOME_BOT_ENABLE'
		);
		foreach ($boolParams as $field)
		{
			$post['CONFIG'][$field] = isset($post['CONFIG'][$field])? $post['CONFIG'][$field]: 'N';
		}

		$post['CONFIG']['WORKTIME_DAYOFF'] = isset($post['CONFIG']['WORKTIME_DAYOFF'])? $post['CONFIG']['WORKTIME_DAYOFF']: Array();

		$queueList = Array();
		if (!empty($post['CONFIG']['QUEUE']['U']))
		{
			$arAccessCodes = Array();
			foreach ($post['CONFIG']['QUEUE']['U'] as $userCode)
			{
				$userId = substr($userCode, 1);
				if (\Bitrix\Im\User::getInstance($userId)->isExtranet())
					continue;
				
				$queueList[] = $userId;
				$arAccessCodes[] = $userCode;
			}
			\Bitrix\Main\FinderDestTable::merge(array(
				"CONTEXT" => "IMOPENLINES",
				"CODE" => \Bitrix\Main\FinderDestTable::convertRights($arAccessCodes, array('U'.$GLOBALS["USER"]->GetId()))
			));
		}

		$post['CONFIG']['QUEUE'] = $queueList;
		$post['CONFIG']['TEMPORARY'] = "N";
		$post['CONFIG']['WORKTIME_HOLIDAYS'] = explode(',', $post['CONFIG']['WORKTIME_HOLIDAYS']);
		
		$configManager = new \Bitrix\ImOpenLines\Config();
		$config = $configManager->get($post['CONFIG_ID']);
		if($config['TEMPORARY'] == 'Y' && !$configManager->canActivateLine())
		{
			$post['CONFIG']['ACTIVE'] = "N";
		}

		if (!$configManager->update($post['CONFIG_ID'], $post['CONFIG']))
		{
			$this->arResult['ERROR'] = $configManager->getError()->msg;
		}
		else if ($request->getPost('action') == 'save')
		{
			if(empty($request['back_url']))
				LocalRedirect($this->arResult['PATH_TO_LIST']);
			else
				LocalRedirect(urldecode($request['back_url']));

			return false;
		}

		return true;
	}

	private function getWorkTimeConfig()
	{
		$params["TIME_ZONE_ENABLED"] = CTimeZone::Enabled();
		$params["TIME_ZONE_LIST"] = CTimeZone::GetZones();

		$params["WEEK_DAYS"] = Array('MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU');

		$params["WORKTIME_LIST_FROM"] = array();
		$params["WORKTIME_LIST_TO"] = array();
		if (\Bitrix\Main\Loader::includeModule("calendar"))
		{
			$params["WORKTIME_LIST_FROM"][strval(0)] = CCalendar::FormatTime(0, 0);
			for ($i = 0; $i < 24; $i++)
			{
				if ($i !== 0)
				{
					$params["WORKTIME_LIST_FROM"][strval($i)] = CCalendar::FormatTime($i, 0);
					$params["WORKTIME_LIST_TO"][strval($i)] = CCalendar::FormatTime($i, 0);
				}
				$params["WORKTIME_LIST_FROM"][strval($i).'.30'] = CCalendar::FormatTime($i, 30);
				$params["WORKTIME_LIST_TO"][strval($i).'.30'] = CCalendar::FormatTime($i, 30);
			}
			$params["WORKTIME_LIST_TO"][strval('23.59')] = CCalendar::FormatTime(23, 59);
		}

		return $params;
	}
	private function getQueueDestination()
	{
		if (!\Bitrix\Main\Loader::includeModule('socialnetwork'))
			return Array();

		$structure = CSocNetLogDestination::GetStucture(array("LAZY_LOAD" => true));
		// TODO filter non-business users

		$destination = array(
			'DEST_SORT' => CSocNetLogDestination::GetDestinationSort(array(
				"DEST_CONTEXT" => "IMOPENLINES",
				"CODE_TYPE" => 'U'
			)),
			'LAST' => array(),
			"DEPARTMENT" => $structure['department'],
			"SELECTED" => array(
				"USERS" => array_values($this->arResult['CONFIG']["QUEUE"])
			)
		);
		CSocNetLogDestination::fillLastDestination($destination['DEST_SORT'], $destination['LAST']);

		$destinationUsers = array_values($this->arResult['CONFIG']["QUEUE"]);
		if (isset($destination['LAST']['USERS']))
		{
			foreach ($destination['LAST']['USERS'] as $value)
				$destinationUsers[] = str_replace('U', '', $value);
		}
		$destination['EXTRANET_USER'] = 'N';
		$destination['USERS'] = CSocNetLogDestination::GetUsers(Array('id' => $destinationUsers));

		return $destination;
	}

	private function showConfig()
	{
		$request = HttpApplication::getInstance()->getContext()->getRequest();

		$configManager = new \Bitrix\ImOpenLines\Config();
		$configId = intval($request->get('ID'));
		if ($configId == 0)
		{
			if(!$configManager->canActivateLine())
			{
				LocalRedirect($this->arResult['PATH_TO_LIST']);
				return false;
			}
			
			if(!$this->userPermissions->canPerform(\Bitrix\ImOpenlines\Security\Permissions::ENTITY_LINES, \Bitrix\ImOpenlines\Security\Permissions::ACTION_MODIFY))
			{
				LocalRedirect($this->arResult['PATH_TO_LIST']);
				return false;
			}

			$configId = $configManager->create();
			if ($configId)
			{
				LocalRedirect($this->arResult['PATH_TO_LIST'] . 'edit.php?ID='.$configId);
			}
			else
			{
				LocalRedirect($this->arResult['PATH_TO_LIST']);
			}
			return false;
		}
		
		if (!\Bitrix\Main\Loader::includeModule('socialnetwork'))
		{
			LocalRedirect($this->arResult['PATH_TO_LIST']);
			return false;
		}
		
		if (!$configManager->canViewLine($configId))
		{
			LocalRedirect($this->arResult['PATH_TO_LIST']);
			return false;
		}
		
		$config = $configManager->get($configId);
		if (!$config)
		{
			LocalRedirect($this->arResult['PATH_TO_LIST']);
			return false;
		}

		$this->arResult['CAN_EDIT'] = $configManager->canEditLine($configId);
		$this->arResult['CAN_EDIT_CONNECTOR'] = $configManager->canEditConnector($configId);
		$this->arResult['IS_CRM_INSTALLED'] = IsModuleInstalled('crm')? 'Y': 'N';

		$config['WORKTIME_HOLIDAYS'] = implode(',', $config['WORKTIME_HOLIDAYS']);
		$this->arResult['CONFIG'] = $config;

		$this->arResult['QUEUE_DESTINATION'] = $this->getQueueDestination();

		$this->arResult['CRM_SOURCES'] = \Bitrix\Main\Loader::includeModule('crm')? CCrmStatus::GetStatusList('SOURCE'): Array();
		$this->arResult['CRM_SOURCES'] = Array("create" => Loc::getMessage('OL_COMPONENT_LE_CRM_SOURCE_CREATE'))+$this->arResult['CRM_SOURCES'];
		
		$this->arResult['BOT_LIST'] = Array();
		if (\Bitrix\Main\Loader::includeModule('im'))
		{
			$list = \Bitrix\Im\Bot::getListCache(\Bitrix\Im\Bot::LIST_OPENLINE);
			foreach ($list as $botId => $botData)
			{
				$this->arResult['BOT_LIST'][$botId] = \Bitrix\Im\User::getInstance($botId)->getFullName();
			}
		}

		if ($this->arResult["CONFIG"]["QUEUE_TYPE"] == "strictly" || $this->arResult["CONFIG"]["QUEUE_TYPE"] == "all")
		{
			$this->arResult['NO_ANSWER_RULES'] = Array();
			if ($this->arResult['IS_CRM_INSTALLED'] == 'Y')
			{
				$this->arResult['NO_ANSWER_RULES']["disabled"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_FORM');
			}
			$this->arResult['NO_ANSWER_RULES']["text"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_TEXT');
			$this->arResult['NO_ANSWER_RULES']["none"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_NONE');

		}
		else
		{
			$this->arResult['NO_ANSWER_RULES'] = Array();
			if ($this->arResult['IS_CRM_INSTALLED'] == 'Y')
			{
				$this->arResult['NO_ANSWER_RULES']["disabled"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_FORM');
			}
			$this->arResult['NO_ANSWER_RULES']["text"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_TEXT');
			$this->arResult['NO_ANSWER_RULES']["queue"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_QUEUE');
			$this->arResult['NO_ANSWER_RULES']["none"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_NONE');
		}

		$this->arResult['SELECT_RULES'] = Array();
		if ($this->arResult['IS_CRM_INSTALLED'] == 'Y')
		{
			$this->arResult['SELECT_RULES']["disabled"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_FORM');
		}
		$this->arResult['SELECT_RULES']["text"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_TEXT');
		$this->arResult['SELECT_RULES']["none"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_NONE');
		
		$this->arResult['CLOSE_RULES'] = Array();
		if ($this->arResult['IS_CRM_INSTALLED'] == 'Y')
		{
			$this->arResult['CLOSE_RULES']["disabled"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_FORM');
		}
		//$this->arResult['CLOSE_RULES']["quality"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_QUALITY');
		$this->arResult['CLOSE_RULES']["text"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_TEXT');
		$this->arResult['CLOSE_RULES']["none"] = Loc::getMessage('OL_COMPONENT_LE_OPTION_NONE');

		$workTimeConfig = $this->getWorkTimeConfig();
		$this->arResult["TIME_ZONE_ENABLED"] = $workTimeConfig["TIME_ZONE_ENABLED"];
		$this->arResult["TIME_ZONE_LIST"] = $workTimeConfig["TIME_ZONE_LIST"];
		$this->arResult["WEEK_DAYS"] = $workTimeConfig["WEEK_DAYS"];
		$this->arResult["WORKTIME_LIST_FROM"] = $workTimeConfig["WORKTIME_LIST_FROM"];
		$this->arResult["WORKTIME_LIST_TO"] = $workTimeConfig["WORKTIME_LIST_TO"];

		if (empty($this->arResult["CONFIG"]["WORKTIME_TIMEZONE"]))
		{
			if (LANGUAGE_ID == "ru")
				$tzByLang = "Europe/Moscow";
			elseif (LANGUAGE_ID == "de")
				$tzByLang = "Europe/Berlin";
			elseif (LANGUAGE_ID == "ua")
				$tzByLang = "Europe/Kiev";
			else
				$tzByLang = "America/New_York";

			$this->arResult["CONFIG"]["WORKTIME_TIMEZONE"] = $tzByLang;
		}

		$usersLimit = \Bitrix\Imopenlines\Limit::getLicenseUsersLimit();
		if ($usersLimit)
		{
			$this->arResult['BUSINESS_USERS'] = 'U'.implode(',U', $usersLimit);
			$this->arResult['BUSINESS_USERS_LIMIT'] = 'Y';
		}
		else
		{
			$this->arResult['BUSINESS_USERS'] = Array();
			$this->arResult['BUSINESS_USERS_LIMIT'] = 'N';
		}

		$this->includeComponentTemplate();

		return true;
	}
	
	public function executeComponent()
	{
		global $APPLICATION;

		$this->includeComponentLang('class.php');

		if (!$this->checkModules())
		{
			return false;
		}
		
		$this->userPermissions = \Bitrix\ImOpenlines\Security\Permissions::createWithCurrentUser();

		$this->arResult['PATH_TO_LIST'] = \Bitrix\ImOpenLines\Common::getPublicFolder() . "list/";
		
		$request = HttpApplication::getInstance()->getContext()->getRequest();
		if ($request->isPost() && $request->getPost('form') == 'imopenlines_edit_form')
		{
			if (!$this->updateLine())
			{
				LocalRedirect($this->arResult['PATH_TO_LIST']);
				return false;
			}
		}

		return $this->showConfig();
	}
};