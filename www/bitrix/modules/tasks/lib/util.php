<?
/**
 * Namespace contains functions\classes for different purposes
 * See also
 *      tasks/classes/general/tasktools.php
 *      tasks/tools.php
 */

namespace Bitrix\Tasks;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

class Util
{
	public static function trim($arg)
	{
		$arg = (string) $arg;
		if($arg == '')
		{
			return $arg;
		}

		$arg = trim($arg);
		// remove that annoying undying sequences from wysiwyg
		$arg = preg_replace('#(^((\x20)?(\xc2)?\xa0(\x20)?)+|((\x20)?(\xc2)?\xa0(\x20)?)+$)#', '', $arg);

		return $arg;
	}

	public static function escape($arg)
	{
		if(is_array($arg))
		{
			foreach($arg as $i => $value)
			{
				$arg[$i] = static::escape($value);
			}

			return $arg;
		}
		else
		{
			if(is_numeric($arg) && !is_string($arg))
			{
				return $arg;
			}
			else
			{
				return htmlspecialcharsbx($arg);
			}
		}
	}

	// see BX.util.hashCode()
	public static function hashCode($str)
	{
		$str = (string) $str;
		if($str == '')
		{
			return 0;
		}

		$hash = 0;
		for ($i = 0; $i < strlen($str); $i++)
		{
			$c = ord($str[$i]);
			$hash = (($hash << 5) - $hash) + $c;
			$hash = $hash & $hash;
		}
		return $hash;
	}

	public static function secureBackUrl($url)
	{
		return str_replace(array('//', '/\\'), '', (string) $url);
	}

	public static function replaceUrlParameters($url, array $paramsToAdd = array(), array $paramsToDelete = array(), array $options = array())
	{
		// CHTTP::url*Params() functions does not like #placeholders# in url, so a little trick is needed
		$found = array();
		preg_match_all("/#([a-zA-Z0-9_-]+)#/", $url, $found);

		$match = array();
		if(is_array($found[1]) && !empty($found[1]))
		{
			foreach($found[1] as $holder)
			{
				$match['#'.$holder.'#'] = '__'.$holder.'__';
			}
		}

		if(!empty($match))
		{
			$url = str_replace(array_keys($match), $match, $url);
		}

		// to avoid adding duplicates and delete other params
		$url = \CHTTP::urlDeleteParams($url, array_merge(array_keys($paramsToAdd), $paramsToDelete));
		$url = \CHTTP::urlAddParams($url, $paramsToAdd, $options);

		if(!empty($match))
		{
			$match = array_flip($match);
			$url = str_replace(array_keys($match), $match, $url);
		}

		return $url;
	}

	public static function getParser(array $parameters = array())
	{
		$parser = \Bitrix\Tasks\Integration\Forum::getParser($parameters);
		if($parser == null)
		{
			$parser = \Bitrix\Tasks\Integration\SocialNetwork::getParser($parameters);
		}
		if($parser == null)
		{
			$parser = new \CTextParser();
		}

		return $parser;
	}


	/**
	 * Generate v4 UUID
	 * Version 4 UUIDs are pseudo-random.
	 *
	 * @param bool $brackets
	 * @return string
	 */
	public static function generateUUID($brackets = true)
	{
		$uuid = '';

		if ($brackets)
		{
			$uuid .= '{';
		}

		$uuid .= sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			// 32 bits for "time_low"
			mt_rand(0, 0xffff), mt_rand(0, 0xffff),

			// 16 bits for "time_mid"
			mt_rand(0, 0xffff),

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand(0, 0x0fff) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand(0, 0x3fff) | 0x8000,

			// 48 bits for "node"
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);

		if ($brackets)
		{
			$uuid .= '}';
		}

		return ($uuid);
	}

	public static function getServerTimeZoneOffset()
	{
		$localTime = new \DateTime();
		return $localTime->getOffset();
	}

	public static function log($info)
	{
		$handler = Application::getInstance()->getExceptionHandler();

		if(is_subclass_of($info, '\\Bitrix\\Main\\SystemException'))
		{
			$handler->writeToLog($info);
		}
		else
		{
			$handler->writeToLog(new \TasksException((string) $info));
		}
	}

	public static function setOption($name, $value)
	{
		Option::set('tasks', $name, $value);
	}

	public static function getOption($name)
	{
		return Option::get('tasks', $name);
	}

	public static function unSetOption($name)
	{
		Option::delete('tasks', array('name' => $name));
	}

	public static function printDebug($data)
	{
		if(function_exists('_dump_r'))
		{
			//_dump_r($data);
		}
	}
}
