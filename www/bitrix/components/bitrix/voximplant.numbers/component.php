<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();
/**
 * @var $arParams array
 * @var $arResult array
 * @var $this CBitrixComponent
 * @var $APPLICATION CMain
 * @var $USER CUser
 */

if (!CModule::IncludeModule('voximplant'))
	return;

$arResult = Array(
	"GRID_ID" => $this->__name,
	"USERS" => array()
);

$gridOptions = new CGridOptions($arResult['GRID_ID']);
$arSort = $gridOptions->getSorting(array('sort' => array('ID' => 'ASC'), 'vars' => array('by' => 'by', 'order' => 'order')));
$arNav  = $gridOptions->getNavParams(array('nPageSize' => 50));
$arSortArg = each($arSort['sort']);

$arFiler = array('ACTIVE' => 'Y');

if ($_REQUEST['act'] == 'search' && !empty($_REQUEST['FILTER']))
{
	$arFiler["NAME"] = $arResult['FILTER'] = $_REQUEST['FILTER'];
}

$dbUsers = CUser::GetList($arSortArg['key'], $arSortArg['value'], $arFiler,
	array(
		'FIELDS' => array('ID', 'LOGIN', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'PERSONAL_PHOTO', 'WORK_POSITION', "UF_PHONE_INNER", "UF_VI_BACKPHONE"),
		'SELECT' => array("UF_PHONE_INNER", "UF_VI_BACKPHONE")
	)
);

$dbUsers->navStart($arNav['nPageSize']);

while ($user = $dbUsers->fetch())
{
	$user['DETAIL_URL'] = COption::getOptionString('intranet', 'search_user_url', '/user/#ID#/');
	$user['DETAIL_URL'] = str_replace(array('#ID#', '#USER_ID#'), array($user['ID'], $user['ID']), $user['DETAIL_URL']);

	$user['PHOTO_THUMB'] = '<img src="/bitrix/components/bitrix/main.user.link/templates/.default/images/nopic_30x30.gif" border="0" alt="" width="32" height="32">';
	if (intval($user['PERSONAL_PHOTO']) > 0)
	{
		$imageFile = CFile::getFileArray($user['PERSONAL_PHOTO']);
		if ($imageFile !== false)
		{
			$arFileTmp = CFile::resizeImageGet(
				$imageFile, array('width' => 42, 'height' => 42),
				BX_RESIZE_IMAGE_EXACT, false
			);
			$user['PHOTO_THUMB'] = CFile::showImage($arFileTmp['src'], 32, 32);
		}
	}
	$arResult['USERS'][$user["ID"]] = $user;
}

$arResult['NAV_OBJECT'] = $dbUsers;
$arResult['NAV_OBJECT']->bShowAll = false;


if (!(isset($arParams['TEMPLATE_HIDE']) && $arParams['TEMPLATE_HIDE'] == 'Y'))
	$this->IncludeComponentTemplate();

return $arResult;
?>