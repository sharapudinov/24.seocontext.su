<?php

namespace Bitrix\ImOpenLines\Model;

use Bitrix\Main\Entity;

class RoleTable extends Entity\DataManager
{
	/**
	 * @inheritdoc
	 */
	public static function getTableName()
	{
		return 'b_imopenlines_role';
	}

	/**
	 * @inheritdoc
	 */
	public static function getMap()
	{
		return array(
			'ID' => new Entity\IntegerField('ID', array(
				'primary' => true,
				'autocomplete' => true,
			)),
			'NAME' => new Entity\StringField('NAME', array(
				'required' => true,
			)),
		);
	}
}