<?
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Crm\SiteButton\Manager;
use Bitrix\Crm\SiteButton\Button;
use Bitrix\Crm\SiteButton\Internals;

if(!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true)
	die();

Loc::loadMessages(__FILE__);


class CCrmSiteButtonEditComponent extends \CBitrixComponent
{
	protected $errors = array();

	/** @var Button */
	protected $button;

	public function processPost()
	{
		global $USER;
		$request = \Bitrix\Main\Context::getCurrent()->getRequest();
		$items = is_array($request->get('ITEMS')) ? $request->get('ITEMS') : array();
		if ($request->get('DELAY_CHOISE') == 'N')
		{
			$delay = 0;
		}
		else
		{
			$delay = (int) $request->get('DELAY');
			$delay = $delay > 0 ? $delay : 0;
		}

		$params = array(
			'NAME' => $request->get('NAME'),
			'LOCATION' => (int) $request->get('LOCATION'),
			'DELAY' => $delay,
			'ITEMS' => $this->processPostItems($items),
			'BACKGROUND_COLOR' => $request->get('BACKGROUND_COLOR'),
			'ICON_COLOR' => $request->get('ICON_COLOR'),
			'SETTINGS' => array(
				'HELLO' => $this->processPostHello($request->get('HELLO')),
				'COPYRIGHT_REMOVED' => $this->processPostRemoveCopyRight($request->get('COPYRIGHT_REMOVED')),
			)
		);

		if(!$this->button->getId())
		{
			$params['CREATED_BY'] = $USER->GetID();
			$params['ACTIVE_CHANGE_BY'] = $USER->GetID();
		}

		$this->button->mergeData($params);
		$this->button->save();

		if(!$this->button->hasErrors())
		{
			if ($this->button->hasFileErrors() || $this->request->get('submit_apply'))
			{
				$urlAdd = $this->button->hasFileErrors() ? array('show_error' => 'file') : array();
				$this->redirectToList($urlAdd);
			}
			else
			{
				LocalRedirect($this->arParams['PATH_TO_BUTTON_LIST']);
			}
		}
		else
		{
			$this->errors = $this->button->getErrors();
			$this->arResult['BUTTON'] = $this->button->getData();
		}
	}

	protected function processPostHello($hello)
	{
		$hello = !is_array($hello) ? array() : $hello;
		if (!isset($hello['CONDITIONS']) || !is_array($hello['CONDITIONS']))
		{
			$conditions = array();
		}

		$isModeExclude = $hello['MODE'] == 'EXCLUDE';

		$conditions = array();
		foreach ($hello['CONDITIONS'] as $condition)
		{
			if (empty($condition['ICON']) || empty($condition['TEXT']))
			{
				continue;
			}

			$condition['DELAY'] = isset($condition['DELAY']) ? (int) $condition['DELAY'] : null;

			$pages = array(
				'MODE' => 'EXCLUDE',
				'LIST' => array()
			);
			if (!empty($condition['PAGES']) && !empty($condition['PAGES']['LIST']))
			{
				$list = $condition['PAGES']['LIST'];
				if (isset($list['EXCLUDE']) && is_array($list['EXCLUDE']))
				{
					$pages['LIST'] = $list['EXCLUDE'];
				}
				else if (isset($list['INCLUDE']) && is_array($list['INCLUDE']))
				{
					$pages['LIST'] = $list['INCLUDE'];
					$pages['MODE'] = 'INCLUDE';
				}

				TrimArr($pages['LIST'], true);
			}

			$conditions[] = array(
				'ICON' => $condition['ICON'],
				'NAME' => $condition['NAME'],
				'TEXT' => $condition['TEXT'],
				'PAGES' => $pages,
				'DELAY' => $condition['DELAY'],
			);
		}

		return array(
			'ACTIVE' => $hello['ACTIVE'] == 'Y',
			'MODE' => $isModeExclude ? 'EXCLUDE' : 'INCLUDE',
			'CONDITIONS' => $conditions
		);
	}

	public function processPostRemoveCopyRight($copyright)
	{
		return ($copyright == 'Y' && Manager::canRemoveCopyright()) ? 'Y' : 'N';
	}

	protected function processPostItems($items)
	{
		$result = array();

		$typeList = Manager::getWidgetList();
		foreach ($typeList as $typeItem)
		{
			if (!isset($items[$typeItem['TYPE']]) || !is_array($items[$typeItem['TYPE']]))
			{
				continue;
			}

			$item = $items[$typeItem['TYPE']];
			$pages = array();
			if (is_array($item['PAGES']))
			{
				if (!is_array($item['PAGES']['LIST']))
				{
					continue;
				}

				if (!is_array($item['PAGES']['LIST']['EXCLUDE']))
				{
					$item['PAGES']['LIST']['EXCLUDE'] = array();
				}

				if (!is_array($item['PAGES']['LIST']['INCLUDE']))
				{
					$item['PAGES']['LIST']['INCLUDE'] = array();
				}

				$excludeList = array();
				foreach ($item['PAGES']['LIST']['EXCLUDE'] as $exclude)
				{
					if (is_string($exclude) && trim($exclude))
					{
						$excludeList[] = trim($exclude);
					}
				}
				$includeList = array();
				foreach ($item['PAGES']['LIST']['INCLUDE'] as $include)
				{
					if (is_string($include) && trim($include))
					{
						$includeList[] = trim($include);
					}
				}

				$pages['LIST']['EXCLUDE'] = $excludeList;
				$pages['LIST']['INCLUDE'] = $includeList;

				$pages['MODE'] = $item['PAGES']['MODE'] == 'INCLUDE' ? $item['PAGES']['MODE'] : 'EXCLUDE';

			}

			$result[$typeItem['TYPE']] = array(
				'ACTIVE' => $item['ACTIVE'] == 'N' ? 'N' : 'Y',
				'EXTERNAL_ID' => is_string($item['EXTERNAL_ID']) ? $item['EXTERNAL_ID'] : '',
				'PAGES' => $pages
			);
		}

		return $result;
	}

	protected function redirectToList($urlAdd = array())
	{
		$replaceList = array('id' => $this->button->getId(), 'form_id' => $this->button->getId());
		$url = CComponentEngine::makePathFromTemplate($this->arParams['PATH_TO_BUTTON_EDIT'], $replaceList);
		$uri = new \Bitrix\Main\Web\Uri($url);
		$uri->addParams($urlAdd);
		LocalRedirect($uri->getLocator());
	}

	public function prepareResult()
	{
		/**@var $USER \CUser*/
		global $USER;
		$CrmPerms = new CCrmPerms($USER->GetID());

		if($CrmPerms->HavePerm('BUTTON', BX_CRM_PERM_NONE))
		{
			ShowError(Loc::getMessage('CRM_PERMISSION_DENIED'));
			return;
		}

		$this->arResult['ERRORS'] = array();
		$this->arResult['PERM_CAN_EDIT'] = !$CrmPerms->HavePerm('BUTTON', BX_CRM_PERM_NONE, 'WRITE');

		$id = $this->arParams['ELEMENT_ID'];
		$this->button = new Button($id);

		/* Set form data */
		$this->arResult['BUTTON'] = $this->button->getData();

		$request = \Bitrix\Main\Context::getCurrent()->getRequest();
		if($request->getRequestMethod() == "POST" && check_bitrix_sessid())
		{
			if(!$this->arResult['PERM_CAN_EDIT'])
			{
				ShowError(Loc::getMessage('CRM_PERMISSION_DENIED'));
				return;
			}
			else
			{
				$this->processPost();
				$this->arResult['ERRORS'] = $this->errors;
			}

		}

		/* Set location */
		$this->prepareResultLocation();

		/* Set delay */
		$this->prepareResultDelay();

		/* Set item types */
		$this->prepareResultItems();

		/* Set hello defaults */
		$this->prepareResultHello();

		/* Copyright */
		$this->arResult['CAN_REMOVE_COPYRIGHT'] = Manager::canRemoveCopyright();

		$replaceList = array('id' => $id, 'form_id' => $id);
		$this->arResult['PATH_TO_BUTTON_LIST'] = CComponentEngine::makePathFromTemplate($this->arParams['PATH_TO_BUTTON_LIST'], $replaceList);

		$this->arResult['SCRIPT'] = \Bitrix\Crm\SiteButton\Script::getScript($this->button);
	}

	protected function prepareResultLocation()
	{
		$this->arResult['BUTTON_LOCATION'] = array();
		$list = Internals\ButtonTable::getLocationList();

		$hasSelected = false;
		foreach ($list as $code => $name)
		{
			$isSelected = $this->arResult['BUTTON']['LOCATION'] == $code;
			if($isSelected)
			{
				$hasSelected = true;
			}
			$this->arResult['BUTTON_LOCATION'][$code] = array(
				'ID' => $code,
				'NAME' => $name,
				'SELECTED' => $isSelected
			);
		}

		if(!$hasSelected)
		{
			$this->arResult['BUTTON_LOCATION'][Internals\ButtonTable::ENUM_LOCATION_BOTTOM_RIGHT]['SELECTED'] = true;
		}

		$this->arResult['BUTTON_LOCATION'] = array_values($this->arResult['BUTTON_LOCATION']);
	}

	protected function prepareResultHello()
	{
		$settings = is_array($this->arResult['BUTTON']['SETTINGS']) ? $this->arResult['BUTTON']['SETTINGS'] : array();
		$hello = is_array($settings['HELLO']) ? $settings['HELLO'] : array();

		if (!is_array($hello['CONDITIONS']))
		{
			$conditions = array();
		}
		else
		{
			$conditions = $hello['CONDITIONS'];
		}

		$this->arResult['HELLO'] = array(
			'ACTIVE' => $hello['ACTIVE'] ? 'Y' : 'N',
			'MODE' => $hello['MODE'] == 'INCLUDE' ? 'INCLUDE' : 'EXCLUDE',
			'CONDITIONS' => $conditions,
		);
	}

	protected function prepareResultItems()
	{
		$this->arResult['BUTTON_ITEM_OPEN_LINE'] = $this->getPreparedItem(Manager::ENUM_TYPE_OPEN_LINE);
		$this->arResult['BUTTON_ITEM_CRM_FORM'] = $this->getPreparedItem(Manager::ENUM_TYPE_CRM_FORM);
		$this->arResult['BUTTON_ITEM_CALLBACK'] = $this->getPreparedItem(Manager::ENUM_TYPE_CALLBACK);

		$this->arResult['BUTTON_ITEMS_DICTIONARY_PATH_EDIT'] = array();
		foreach (Manager::getWidgetList() as $typeItem)
		{
			$this->arResult['BUTTON_ITEMS_DICTIONARY_PATH_EDIT'][$typeItem['TYPE']] = $typeItem['PATH_EDIT'];
		}
	}

	protected function getPreparedItem($type)
	{
		$item = $this->button->getItemByType($type);
		if(!is_array($item))
		{
			$item = array();
		}

		$isTypeFound = false;
		foreach (Manager::getWidgetList() as $typeItem)
		{
			if ($type != $typeItem['TYPE'])
			{
				continue;
			}

			$isTypeFound = true;
			$list = array();
			foreach ($typeItem['LIST'] as $external)
			{
				$external['SELECTED'] = $item['EXTERNAL_ID'] == $external['ID'];
				$list[] = $external;

			}

			$item['LIST'] = $list;
			$item['PATH_ADD'] = $typeItem['PATH_ADD'];
			$item['PATH_LIST'] = $typeItem['PATH_LIST'];
			$item['TYPE'] = $typeItem['TYPE'];
			$item['TYPE_NAME'] = $typeItem['NAME'];
		}

		if(!$isTypeFound)
		{
			return null;
		}

		if(!isset($item['PAGES']))
		{
			$item['PAGES'] = array(
				'MODE' => 'EXCLUDE',
				'LIST' => array()
			);
		}

		if($item['PAGES']['MODE'] == 'EXCLUDE' && $item['PAGES']['LIST']['EXCLUDE'])
		{
			$item['PAGES_USES'] = true;
		}
		else if($item['PAGES']['MODE'] == 'INCLUDE' && $item['PAGES']['LIST']['INCLUDE'])
		{
			$item['PAGES_USES'] = true;
		}
		else
		{
			$item['PAGES_USES'] = false;
		}

		$item['ACTIVE'] = $item['ACTIVE'] == 'N' ? 'N' : 'Y';

		return $item;
	}

	protected function prepareResultDelay()
	{
		$this->arResult['BUTTON_DELAY'] = array();
		$list = array(
			'3' => '3 ' . Loc::getMessage('CRM_BUTTON_EDIT_UNIT_SECOND'),
			'5' => '5 ' . Loc::getMessage('CRM_BUTTON_EDIT_UNIT_SECOND'),
			'7' => '7 ' . Loc::getMessage('CRM_BUTTON_EDIT_UNIT_SECOND'),
			'10' => '10 ' . Loc::getMessage('CRM_BUTTON_EDIT_UNIT_SECOND'),
			'15' => '15 ' . Loc::getMessage('CRM_BUTTON_EDIT_UNIT_SECOND'),
			'20' => '20 ' . Loc::getMessage('CRM_BUTTON_EDIT_UNIT_SECOND'),
			'25' => '25 ' . Loc::getMessage('CRM_BUTTON_EDIT_UNIT_SECOND'),
			'30' => '30 ' . Loc::getMessage('CRM_BUTTON_EDIT_UNIT_SECOND'),
			'40' => '40 ' . Loc::getMessage('CRM_BUTTON_EDIT_UNIT_SECOND'),
			'60' => '1 ' . Loc::getMessage('CRM_BUTTON_EDIT_UNIT_MINUTE'),
			'120' => '2 ' . Loc::getMessage('CRM_BUTTON_EDIT_UNIT_MINUTE'),
		);

		foreach ($list as $code => $name)
		{
			$this->arResult['BUTTON_DELAY'][] = array(
				'ID' => $code,
				'NAME' => $name,
				'SELECTED' => $this->arResult['BUTTON']['DELAY'] == $code
			);
		}
	}

	public function checkParams()
	{
		$this->arParams['NAME_TEMPLATE'] = empty($this->arParams['NAME_TEMPLATE']) ? CSite::GetNameFormat(false) : str_replace(array("#NOBR#","#/NOBR#"), array("",""), $this->arParams["NAME_TEMPLATE"]);

		return true;
	}

	public function executeComponent()
	{
		if (!$this->checkModules())
		{
			$this->showErrors();
			return;
		}

		if (!$this->checkParams())
		{
			$this->showErrors();
			return;
		}

		global $APPLICATION;
		$APPLICATION->SetTitle(
			$this->arParams['ELEMENT_ID'] > 0
				?
				Loc::getMessage('CRM_BUTTON_EDIT_TITLE_EDIT')
				:
				Loc::getMessage('CRM_BUTTON_EDIT_TITLE_ADD')
		);
		$this->prepareResult();

		if($this->request->get('show_error') == 'file')
		{
			$this->arResult['ERRORS'][] = Loc::getMessage('CRM_BUTTON_EDIT_ERROR_FILE');
		}

		$this->includeComponentTemplate();
	}

	protected function checkModules()
	{
		if(!Loader::includeModule('crm'))
		{
			$this->errors[] = Loc::getMessage('CRM_MODULE_NOT_INSTALLED');
			return false;
		}

		return true;
	}

	protected function hasErrors()
	{
		return (count($this->errors) > 0);
	}

	protected function showErrors()
	{
		if(count($this->errors) <= 0)
		{
			return;
		}

		foreach($this->errors as $error)
		{
			ShowError($error);
		}
	}
}