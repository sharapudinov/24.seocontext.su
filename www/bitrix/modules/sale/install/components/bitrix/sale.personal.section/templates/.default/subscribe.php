<?
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

use Bitrix\Main\Localization\Loc;

if ($arParams['SHOW_SUBSCRIBE_PAGE'] !== 'Y')
{
	LocalRedirect($arParams['SEF_FOLDER']);
}

$APPLICATION->AddChainItem(Loc::getMessage("SPS_CHAIN_MAIN"), $arResult['SEF_FOLDER']);
$APPLICATION->AddChainItem(Loc::getMessage("SPS_CHAIN_SUBSCRIBE_NEW"));
$APPLICATION->IncludeComponent(
	'bitrix:catalog.product.subscribe.list',
	'',
	array('SET_TITLE' => $arParams['SET_TITLE_SUBSCRIBE'])
	,
	$component
);
