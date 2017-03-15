<?
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

if (empty($arResult))return;
?>

<ul id="horizontal-multilevel-menu">
<?
$previousLevel = 0;
$firstRoot = false;
foreach($arResult as $itemIdex => $arItem):?>

<?if ($previousLevel && $arItem["DEPTH_LEVEL"] < $previousLevel):?>
	<?=str_repeat("		</ul></li>", ($previousLevel - $arItem["DEPTH_LEVEL"]));?>
<?endif?>

<?if ($arItem["IS_PARENT"]):?>
	<?if ($arItem["DEPTH_LEVEL"] == 1):?>
	<li class="<?if ($arItem["SELECTED"]):?>root-item-selected<?else:?>root-item<?endif?>"><?if ($itemIdex > 0):?><div class="root-separator"></div><?endif?><a href="<?=$arItem["LINK"]?>" class="<?if ($arItem["SELECTED"]):?>root-item-selected<?else:?>root-item<?endif?>"><?=$arItem["TEXT"]?></a>
		<ul>
	<?else:?>
	<li<?if ($arItem["SELECTED"]):?> class="item-selected"<?endif?>><a href="<?=$arItem["LINK"]?>" class="parent"><?=$arItem["TEXT"]?></a>
		<ul>
	<?endif?>
<?else:?>
	<?if ($arItem["PERMISSION"] > "D"):?>
		<?if ($arItem["DEPTH_LEVEL"] == 1):?>
		<li class="<?if ($arItem["SELECTED"]):?>root-item-selected<?else:?>root-item<?endif?>"><?if ($itemIdex > 0):?><div class="root-separator"></div><?endif?><a href="<?=$arItem["LINK"]?>" class="<?if ($arItem["SELECTED"]):?>root-item-selected<?else:?>root-item<?endif?>"><?=$arItem["TEXT"]?></a></li>
		<?else:
			$class = "";
			if ($arItem["SELECTED"])
				$class .= "item-selected";

			if (!isset($arResult[$itemIdex+1]) || (isset($arResult[$itemIdex+1]) && $arResult[$itemIdex+1]["DEPTH_LEVEL"] != $arResult[$itemIdex]["DEPTH_LEVEL"]))
				$class .= " item-last";

			if (strlen($class) > 0)
				$class = ' class="'.$class.'"';
		?>
			<li<?=$class?>><a href="<?=$arItem["LINK"]?>"><?=$arItem["TEXT"]?></a></li>
		<?endif?>
	<?else:?>
		<?if ($arItem["DEPTH_LEVEL"] == 1):?>
		<li class="<?if ($arItem["SELECTED"]):?>root-item-selected<?else:?>root-item<?endif?>"><?if ($itemIdex > 0):?><div class="root-separator"></div><?endif?><a href="" class="<?if ($arItem["SELECTED"]):?>root-item-selected<?else:?>root-item<?endif?>" title="<?=GetMessage("MENU_ITEM_ACCESS_DENIED")?>"><?=$arItem["TEXT"]?></a></li>
		<?else:
			$class = "";
			if (!isset($arResult[$itemIdex+1]) || (isset($arResult[$itemIdex+1]) && $arResult[$itemIdex+1]["DEPTH_LEVEL"] != $arResult[$itemIdex]["DEPTH_LEVEL"]))
				$class .= ' class="item-last"';
		?>
			<li<?=$class?>><a href="" class="denied" title="<?=GetMessage("MENU_ITEM_ACCESS_DENIED")?>"><?=$arItem["TEXT"]?></a></li>
		<?endif?>
	<?endif?>
<?endif;

	$previousLevel = $arItem["DEPTH_LEVEL"];
	if ($arItem["DEPTH_LEVEL"] == 1)
		$firstRoot = true;
?>
<?endforeach;

if ($previousLevel > 1)
	echo str_repeat("</ul></li>", ($previousLevel-1));
?>
</ul>
<div class="menu-clear-left"></div>