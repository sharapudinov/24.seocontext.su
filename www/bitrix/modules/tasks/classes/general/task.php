<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage tasks
 * @copyright 2001-2013 Bitrix
 * 
 * @global $USER_FIELD_MANAGER CUserTypeManager
 * @global $APPLICATION CMain
 *
 * @deprecated
 */
global $USER_FIELD_MANAGER;

use Bitrix\Main\Application;
use Bitrix\Main\DB\MssqlConnection;
use Bitrix\Main\DB\MysqlCommonConnection;
use Bitrix\Main\DB\OracleConnection;

use \Bitrix\Tasks\Internals\Task\FavoriteTable;
use \Bitrix\Tasks\Internals\Task\ProjectDependenceTable;
use \Bitrix\Tasks\Internals\Task\SortingTable;
use \Bitrix\Tasks\Util\Type;

use Bitrix\Tasks\Util\Replicator;
use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\Internals\Task\MemberTable;

class CTasks
{
	//Task statuses: 1 - New, 2 - Pending, 3 - In Progress, 4 - Supposedly completed, 5 - Completed, 6 - Deferred, 7 - Declined
	// todo: using statuses in the way "-2, -1" is a bad idea. its better to have separate (probably runtime) fields called "viewed" and "expired"
	// todo: and then, if you want to know if the task is "virgin new", just apply filter array('=VIEWED' => false, '=STATUS' => 2/*or 1*/)
	const METASTATE_VIRGIN_NEW       = -2; // unseen
	const METASTATE_EXPIRED          = -1;
	const STATE_NEW                  =  1;
	const STATE_PENDING              =  2;	// Pending === Accepted
	const STATE_IN_PROGRESS          =  3;
	const STATE_SUPPOSEDLY_COMPLETED =  4;
	const STATE_COMPLETED            =  5;
	const STATE_DEFERRED             =  6;
	const STATE_DECLINED             =  7;

	const PRIORITY_LOW     = 0;
	const PRIORITY_AVERAGE = 1;
	const PRIORITY_HIGH    = 2;

    const MARK_POSITIVE =   'P';
    const MARK_NEGATIVE =   'N';

	const TIME_UNIT_TYPE_SECOND = 	'secs';
	const TIME_UNIT_TYPE_MINUTE = 	'mins';
	const TIME_UNIT_TYPE_HOUR = 	'hours';
	const TIME_UNIT_TYPE_DAY = 		'days';
	const TIME_UNIT_TYPE_WEEK = 	'weeks';
	const TIME_UNIT_TYPE_MONTH = 	'monts'; // 5 chars max :)
	const TIME_UNIT_TYPE_YEAR = 	'years';

	const PARAMETER_PROJECT_PLAN_FROM_SUBTASKS = 0x01;
	const PARAMETER_COMPLETE_TASK_FROM_SUBTASKS = 0x02;

	private $_errors = 	array();
	private $lastOperationResultData = array();
	private $previousData = array();

	private static $cacheIds = array();
	private static $cacheClearEnabled = true;

	function GetErrors()
	{
		return $this->_errors;
	}

	public function getLastOperationResultData()
	{
		return $this->lastOperationResultData;
	}

	public function getPreviousData()
	{
		return $this->previousData;
	}

	function CheckFields(&$arFields, $ID = false, $effectiveUserId = null)
	{
		global $APPLICATION;

		if ($effectiveUserId === null)
		{
			$effectiveUserId = User::getId();
			if(!$effectiveUserId)
			{
				$effectiveUserId = User::getAdminId();
			}
		}

		if ((is_set($arFields, "TITLE") || $ID === false))
		{
			$arFields["TITLE"] = trim((string) $arFields["TITLE"]);

			if(strlen($arFields["TITLE"]) <= 0)
			{
				$this->_errors[] = array("text" => GetMessage("TASKS_BAD_TITLE"), "id" => "ERROR_BAD_TASKS_TITLE");
			}
		}

		// you are not allowed to clear up END_DATE_PLAN while the task is linked
		if($ID && ((isset($arFields['END_DATE_PLAN']) && (string) $arFields['END_DATE_PLAN'] == '')))
		{
			if(ProjectDependenceTable::checkItemLinked($ID))
			{
				$this->_errors[] = array("text" => GetMessage("TASKS_IS_LINKED_END_DATE_PLAN_REMOVE"), "id" => "ERROR_TASKS_IS_LINKED");
			}
		}

		if($ID && (isset($arFields['PARENT_ID']) && intval($arFields['PARENT_ID']) > 0))
		{
			if(ProjectDependenceTable::checkLinkExists($ID, $arFields['PARENT_ID'], array('BIDIRECTIONAL' => true)))
			{
				$this->_errors[] = array("text" => GetMessage("TASKS_IS_LINKED_SET_PARENT"), "id" => "ERROR_TASKS_IS_LINKED");
			}
		}

		// If plan dates were set
		if (isset($arFields['START_DATE_PLAN'])
			&& isset($arFields['END_DATE_PLAN'])
			&& ($arFields['START_DATE_PLAN'] != '')
			&& ($arFields['END_DATE_PLAN'] != '')
		)
		{
			$startDate = MakeTimeStamp($arFields['START_DATE_PLAN']);
			$endDate   = MakeTimeStamp($arFields['END_DATE_PLAN']);

			// and they were really set
			if (($startDate > 0)
				&& ($endDate > 0)
			)
			{
				// and end date is before start date => then emit error
				if ($endDate < $startDate)
				{
					$this->_errors[] = array(
						'text' => GetMessage('TASKS_BAD_PLAN_DATES'),
						'id' => 'ERROR_BAD_TASKS_PLAN_DATES');
				}
			}
		}

		if ($ID === false && !is_set($arFields, "RESPONSIBLE_ID"))
		{
			$this->_errors[] = array("text" => GetMessage("TASKS_BAD_RESPONSIBLE_ID"), "id" => "ERROR_TASKS_BAD_RESPONSIBLE_ID");
		}

		if ($ID === false && !is_set($arFields, "CREATED_BY"))
			$this->_errors[] = array("text" => GetMessage("TASKS_BAD_CREATED_BY"), "id" => "ERROR_TASKS_BAD_CREATED_BY");

		if (is_set($arFields, "CREATED_BY"))
		{
			if ( ! ($arFields['CREATED_BY'] >= 1) )
				$this->_errors[] = array("text" => GetMessage("TASKS_BAD_CREATED_BY"), "id" => "ERROR_TASKS_BAD_CREATED_BY");
		}

		if (is_set($arFields, "RESPONSIBLE_ID"))
		{
			$r = CUser::GetByID($arFields["RESPONSIBLE_ID"]);
			if ($arUser = $r->Fetch())
			{
				if ($ID)
				{
					$rsTask = CTasks::GetList(array(), array("ID" => $ID), array("RESPONSIBLE_ID"), array('USER_ID' => $effectiveUserId));
					if ($arTask = $rsTask->Fetch())
					{
						$currentResponsible = $arTask["RESPONSIBLE_ID"];
					}
				}

				// new task or responsible changed
				if (!$ID || (isset($currentResponsible) && $currentResponsible != $arFields["RESPONSIBLE_ID"]))
				{
					// check if $createdBy is director for responsible
					$createdBy = $arFields["CREATED_BY"];

					$arSubDeps = CTasks::GetSubordinateDeps($createdBy);

					if ( ! is_array($arUser["UF_DEPARTMENT"]) )
						$bSubordinate = (sizeof(array_intersect($arSubDeps, array($arUser["UF_DEPARTMENT"]))) > 0);
					else
						$bSubordinate = (sizeof(array_intersect($arSubDeps, $arUser["UF_DEPARTMENT"])) > 0);

					if (!$arFields["STATUS"])
					{
						$arFields["STATUS"] = self::STATE_PENDING;
					}
					if (!$bSubordinate)
					{
						$arFields["ADD_IN_REPORT"] = "N";
					}

					$arFields["DECLINE_REASON"] = false;
				}
			}
			else
			{
				$this->_errors[] = array("text" => GetMessage("TASKS_BAD_RESPONSIBLE_ID_EX"), "id" => "ERROR_TASKS_BAD_RESPONSIBLE_ID_EX");
			}
		}

		// move 0 to null in PARENT_ID to avoid constraint and query problems
		// todo: move PARENT_ID, GROUP_ID and other "foreign keys" to the unique way of keeping absense of relation: null, 0 or ''
		if(array_key_exists('PARENT_ID', $arFields))
		{
			$parentId = intval($arFields['PARENT_ID']);
			if(!intval($parentId))
			{
				$arFields['PARENT_ID'] = false;
			}
		}

		if (is_set($arFields, "PARENT_ID") && intval($arFields["PARENT_ID"]) > 0)
		{
			$r = CTasks::GetByID($arFields["PARENT_ID"], true, array('USER_ID' => $effectiveUserId));
			if (!$r->Fetch())
			{
				$this->_errors[] = array("text" => GetMessage("TASKS_BAD_PARENT_ID"), "id" => "ERROR_TASKS_BAD_PARENT_ID");
			}
		}

		if($ID !== false && intval($arFields["PARENT_ID"]))
		{
			$result = \Bitrix\Tasks\Internals\Helper\Task\Dependence::canAttach($ID, $arFields["PARENT_ID"]);

			if(!$result->isSuccess())
			{
				foreach($result->getErrors()->getMessages() as $message)
				{
					$this->_errors[] = array("text" => $message, "id" => "ERROR_TASKS_PARENT_SELF");
				}
			}
		}

		if ($ID !== false && is_array($arFields["DEPENDS_ON"]) && in_array($ID, $arFields["DEPENDS_ON"]))
		{
			$this->_errors[] = array("text" => GetMessage("TASKS_DEPENDS_ON_SELF"), "id" => "ERROR_TASKS_DEPENDS_ON_SELF");
		}

		/*
		if(!$ID)
		{
			// since this time we dont allow to create tasks with a non-bbcode description
			if($arFields['DESCRIPTION_IN_BBCODE'] == 'N')
			{
				$this->_errors[] = array("text" => GetMessage("TASKS_DESCRIPTION_IN_BBCODE_NO_NOT_ALLOWED"), "id" => "ERROR_TASKS_DESCRIPTION_IN_BBCODE_NO_NOT_ALLOWED");
			}
			else
			{
				$arFields['DESCRIPTION_IN_BBCODE'] = 'Y';
			}
		}
		*/

		// accomplices & auditors
		Type::checkArrayOfUPIntegerKey($arFields, 'ACCOMPLICES');
		Type::checkArrayOfUPIntegerKey($arFields, 'AUDITORS');

		if(!Type::checkEnumKey($arFields, 'STATUS', array(
			CTasks::STATE_NEW,
			CTasks::STATE_PENDING,
			CTasks::STATE_IN_PROGRESS,
			CTasks::STATE_SUPPOSEDLY_COMPLETED,
			CTasks::STATE_COMPLETED,
			CTasks::STATE_DEFERRED,
			CTasks::STATE_DECLINED,
		)))
		{
			$this->_errors[] = array("text" => GetMessage("TASKS_INCORRECT_STATUS"), "id" => "ERROR_TASKS_INCORRECT_STATUS");
		}

		Type::checkEnumKey($arFields, 'PRIORITY', array(self::PRIORITY_LOW, self::PRIORITY_AVERAGE, self::PRIORITY_HIGH), self::PRIORITY_AVERAGE);
		Type::checkEnumKey($arFields, 'MARK', array(self::MARK_NEGATIVE, self::MARK_POSITIVE, ''));

		// flags
		Type::checkYNKey($arFields, 'ALLOW_CHANGE_DEADLINE');
		Type::checkYNKey($arFields, 'TASK_CONTROL');
		Type::checkYNKey($arFields, 'ADD_IN_REPORT');
		Type::checkYNKey($arFields, 'MATCH_WORK_TIME');
		Type::checkYNKey($arFields, 'REPLICATE');

		if (!empty($this->_errors))
		{
			$e = new CAdminException($this->_errors);
			$APPLICATION->ThrowException($e);
			return false;
		}

		return true;
	}

	/**
	 * This method is deprecated. Use CTaskItem::add() instead.
	 * @deprecated
	 */
	public function Add($arFields, $arParams = array())
	{
		global $DB, $USER_FIELD_MANAGER, $CACHE_MANAGER, $APPLICATION;
		
		if (isset($arFields['META::EVENT_GUID']))
		{
			$eventGUID = $arFields['META::EVENT_GUID'];
			unset($arFields['META::EVENT_GUID']);
		}
		else
			$eventGUID = sha1(uniqid('AUTOGUID', true));

		if ( ! array_key_exists('GUID', $arFields) )
			$arFields['GUID'] = CTasksTools::genUuid();

		if ( ! isset($arFields['SITE_ID']) )
			$arFields['SITE_ID'] = SITE_ID;

		if(!isset($arParams['CORRECT_DATE_PLAN']))
		{
			$arParams['CORRECT_DATE_PLAN'] = true;
		}

		// force GROUP_ID to 0 if not set (prevent occur as NULL in database)
		$arFields['GROUP_ID'] = intval($arFields['GROUP_ID']);

		$bWasFatalError = false;
		$spawnedByAgent = false;

		$effectiveUserId = null;

		$bCheckRightsOnFiles = false;	// for backward compatibility

		if (is_array($arParams))
		{
			if (isset($arParams['SPAWNED_BY_AGENT'])
				&& (
					($arParams['SPAWNED_BY_AGENT'] === 'Y')
					|| ($arParams['SPAWNED_BY_AGENT'] === true)
					)
				)
			{
				$spawnedByAgent = true;
			}

			if (isset($arParams['USER_ID']) && ($arParams['USER_ID'] > 0))
				$effectiveUserId = (int) $arParams['USER_ID'];

			if (isset($arParams['CHECK_RIGHTS_ON_FILES']))
			{
				if (
					($arParams['CHECK_RIGHTS_ON_FILES'] === 'Y')
					|| ($arParams['CHECK_RIGHTS_ON_FILES'] === true)
				)
				{
					$bCheckRightsOnFiles = true;
				}
				else
					$bCheckRightsOnFiles = false;
			}
		}

		self::processDurationPlanFields($arFields, $arFields['DURATION_TYPE']);

		if ($effectiveUserId === null)
		{
			$effectiveUserId = User::getId();
			if(!$effectiveUserId)
			{
				$effectiveUserId = 1; // nasty, but for compatibility :(
			}
		}

		if (
			( ! isset($arFields['CREATED_BY']) )
			|| ( ! $arFields['CREATED_BY'] )
		)
		{
			$arFields['CREATED_BY'] = $effectiveUserId;
		}

		if ($this->CheckFields($arFields, false, $effectiveUserId))
		{
			// never, never step on this option. hot lava!
			if($arParams['CLONE_DISK_FILE_ATTACHMENT'] === true || $arParams['CLONE_DISK_FILE_ATTACHMENT'] === 'Y')
			{
				// when you pass existing file attachments to add(), you must copy all the files and make new attachments
				// currently only for one field: UF_TASK_WEBDAV_FILES
				if(array_key_exists('UF_TASK_WEBDAV_FILES', $arFields) && is_array($arFields['UF_TASK_WEBDAV_FILES']))
				{
					$arFields['UF_TASK_WEBDAV_FILES'] = \Bitrix\Tasks\Integration\Disk::cloneFileAttachment($arFields['UF_TASK_WEBDAV_FILES'], $effectiveUserId);
				}
			}

			if ($USER_FIELD_MANAGER->CheckFields("TASKS_TASK", 0, $arFields, $effectiveUserId))
			{
				$nowDateTimeString = \Bitrix\Tasks\UI::formatDateTime(User::getTime());

				if(!isset($arFields["CREATED_DATE"])) // created date was not set manually
				{
					$arFields["CREATED_DATE"] = $nowDateTimeString;
				}

				if (!isset($arFields["CHANGED_BY"]))
				{
					$arFields["STATUS_CHANGED_BY"] = $arFields["CHANGED_BY"] = $arFields["CREATED_BY"];
					$arFields["STATUS_CHANGED_DATE"] = $arFields["CHANGED_DATE"] = $arFields["CREATED_DATE"] = $nowDateTimeString;
				}

				$shiftResult = null;
				if($arParams['CORRECT_DATE_PLAN'] && ((string) $arFields['START_DATE_PLAN'] != '' || (string) $arFields['END_DATE_PLAN'] != ''))
				{
					$scheduler = \Bitrix\Tasks\Processor\Task\Scheduler::getInstance($effectiveUserId);
					$shiftResult = $scheduler->processEntity(0, $arFields, array(
						'MODE' => 'BEFORE_ATTACH',
					));
					if($shiftResult->isSuccess())
					{
						$shiftData = $shiftResult->getImpactById(0);
						if($shiftData)
						{
							// will be saved...
							$arFields['START_DATE_PLAN'] = $shiftData['START_DATE_PLAN'];
							$arFields['END_DATE_PLAN'] = $shiftData['END_DATE_PLAN'];
							$arFields['DURATION_PLAN_SECONDS'] = $shiftData['DURATION_PLAN_SECONDS'];
						}
					}
				}

				$arFields["OUTLOOK_VERSION"] = 1;

				foreach(GetModuleEvents('tasks', 'OnBeforeTaskAdd', true) as $arEvent)
				{
					if (ExecuteModuleEventEx($arEvent, array(&$arFields))===false)
					{
						$e = $APPLICATION->GetException();

						if ($e)
						{
							if ($e instanceof CAdminException)
							{
								if (is_array($e->messages))
								{
									foreach($e->messages as $msg)
										$this->_errors[] = $msg;
								}
							}
							else
							{
								$this->_errors[] = array('text' => $e->getString(), 'id' => 'unknown');
							}
						}

						if (empty($this->_errors))
							$this->_errors[] = array("text" => GetMessage("TASKS_UNKNOWN_ADD_ERROR"), "id" => "ERROR_UNKNOWN_ADD_TASK_ERROR");

						return false;
					}
				}

				$ID = $DB->Add("b_tasks", $arFields, array("DESCRIPTION"), "tasks");

				$arFields["ACCOMPLICES"] = (array) $arFields["ACCOMPLICES"];
				$arFields["AUDITORS"] = (array) $arFields["AUDITORS"];

				if ($ID)
				{
					$rsTask = CTasks::GetByID($ID, false);
					if ($arTask = $rsTask->Fetch())
					{
						// add to favorite, if needed
						if(intval($arFields['PARENT_ID']) && FavoriteTable::check(array('TASK_ID' => $arFields['PARENT_ID'], 'USER_ID' => $effectiveUserId)))
						{
							FavoriteTable::add(array('TASK_ID' => $ID, 'USER_ID' => $effectiveUserId), array('CHECK_EXISTENCE' => false));
						}

						// drop, then re-add
						$res = MemberTable::getList(array('filter' => array('=TASK_ID' => $ID)));
						while($item = $res->fetch())
						{
							MemberTable::delete($item);
						}

						// add responsible and creator to the member "cache" table
						MemberTable::add(array(
							'TASK_ID' => $ID,
							'USER_ID' => $arTask['CREATED_BY'],
							'TYPE' => 'O',
						));
						MemberTable::add(array(
							'TASK_ID' => $ID,
							'USER_ID' => $arTask['RESPONSIBLE_ID'],
							'TYPE' => 'R',
						));

						CTasks::AddAccomplices($ID, $arFields["ACCOMPLICES"]);
						CTasks::AddAuditors($ID, $arFields["AUDITORS"]);

						CTasks::AddFiles(
							$ID,
							$arFields["FILES"],
							array(
								'USER_ID'               => $effectiveUserId,
								'CHECK_RIGHTS_ON_FILES' => $bCheckRightsOnFiles
							)
						);

						CTasks::AddTags($ID, $arTask["CREATED_BY"], $arFields["TAGS"], $effectiveUserId);
						CTasks::AddPrevious($ID, $arFields["DEPENDS_ON"]);

						$USER_FIELD_MANAGER->Update("TASKS_TASK", $ID, $arFields, $effectiveUserId);

						// backward compatibility with PARENT_ID
						$parentId = intval($arFields["PARENT_ID"]);
						if($parentId)
						{
							\Bitrix\Tasks\Internals\Helper\Task\Dependence::attachNew($ID, $parentId);
						}

						$arFields["ID"] = $ID;

						CTasks::__updateViewed($ID, $effectiveUserId, $onTaskAdd = true);
						CTaskCountersProcessor::onAfterTaskAdd($arFields);
						CTaskComments::onAfterTaskAdd($ID, $arFields);

						$occurAsUserId = CTasksTools::getOccurAsUserId();
						if ( ! $occurAsUserId )
							$occurAsUserId = ($effectiveUserId ? $effectiveUserId : 1);

						CTaskNotifications::SendAddMessage(
							array_merge($arFields, array('CHANGED_BY' => $occurAsUserId)), 
							array('SPAWNED_BY_AGENT' => $spawnedByAgent)
						);

						CTaskSync::AddItem($arFields); // MS Exchange

						// changes log
						$arLogFields = array(
							"TASK_ID" => $ID,
							"USER_ID" => $occurAsUserId,
							"CREATED_DATE" => $nowDateTimeString,
							"FIELD" => "NEW"
						);
						$log = new CTaskLog();
						$log->Add($arLogFields);

						try
						{
							$lastEventName = '';
							foreach(GetModuleEvents('tasks', 'OnTaskAdd', true) as $arEvent)
							{
								$lastEventName = $arEvent['TO_CLASS'] . '::' . $arEvent['TO_METHOD'] . '()';
								ExecuteModuleEventEx($arEvent, array($ID, &$arFields));
							}
						}
						catch (Exception $e)
						{
							CTaskAssert::logWarning(
								'[0x37eb64ae] exception in module event: ' . $lastEventName
							);
							\Bitrix\Tasks\Util::log($e);
						}

						CTasks::Index($arTask, $arFields["TAGS"]); // search index

						// clear cache
						if ($arFields["GROUP_ID"])
						{
							$CACHE_MANAGER->ClearByTag("tasks_group_".$arFields["GROUP_ID"]);
						}
						$arParticipants = array_unique(array_merge(array($arFields["CREATED_BY"], $arFields["RESPONSIBLE_ID"]), $arFields["ACCOMPLICES"], $arFields["AUDITORS"]));
						foreach($arParticipants as $userId)
						{
							$CACHE_MANAGER->ClearByTag("tasks_user_".$userId);
						}

						// Emit pull event
						try
						{
							$arPullRecipients = array();

							foreach($arParticipants as $userId)
								$arPullRecipients[] = (int) $userId;

							$taskGroupId = 0;	// no group

							if (isset($arFields['GROUP_ID']) && ($arFields['GROUP_ID'] > 0))
								$taskGroupId = (int) $arFields['GROUP_ID'];

							$arPullData = array(
								'TASK_ID' => (int) $ID,
								'AFTER' => array(
									'GROUP_ID' => $taskGroupId
								),
								'TS' => time(),
								'event_GUID' => $eventGUID
							);

							self::EmitPullWithTagPrefix(
								$arPullRecipients, 
								'TASKS_GENERAL_', 
								'task_add', 
								$arPullData
							);

							self::EmitPullWithTag(
								$arPullRecipients, 
								'TASKS_TASK_' . (int) $ID, 
								'task_add', 
								$arPullData
							);
						}
						catch (Exception $e)
						{
							$bWasFatalError = true;
							$this->_errors[] = 'at line ' . $e->GetLine() 
								. ', ' . $e->GetMessage();
						}

						// tasks dependence

						if($shiftResult !== null)
						{
							$shiftResult->save(array('!ID' => 0));
						}

						if ($arFields['GROUP_ID'] && CModule::IncludeModule("socialnetwork"))
							CSocNetGroup::SetLastActivity($arFields['GROUP_ID']);
					}
				}

				if ($bWasFatalError)
					soundex('push&pull: bWasFatalError === true');

				return $ID;
			}
			else
			{
				$e = $APPLICATION->GetException();
				foreach($e->messages as $msg)
				{
					$this->_errors[] = $msg;
				}
			}
		}

		if (empty($this->_errors))
			$this->_errors[] = array("text" => GetMessage("TASKS_UNKNOWN_ADD_ERROR"), "id" => "ERROR_UNKNOWN_ADD_TASK_ERROR");

		return false;
	}

	private static function processDurationPlanFields(&$arFields, $type)
	{
		$durationPlan = false;
		if(isset($arFields['DURATION_PLAN_SECONDS']))
		{
			$durationPlan = $arFields['DURATION_PLAN_SECONDS'];
		}
		elseif(isset($arFields['DURATION_PLAN']))
		{
			$durationPlan = self::convertDurationToSeconds($arFields['DURATION_PLAN'], $type);
		}

		if($durationPlan !== false) // smth were done
		{
			$arFields['DURATION_PLAN'] = $durationPlan;
			unset($arFields['DURATION_PLAN_SECONDS']);
		}
	}

	/**
	 * This method is deprecated. Use CTaskItem::update() instead.
	 * @deprecated
	 */
	public function Update($ID, $arFields, $arParams = array('CORRECT_DATE_PLAN_DEPENDENT_TASKS' => true, 'CORRECT_DATE_PLAN' => true, 'THROTTLE_MESSAGES' => false))
	{
		//$GLOBALS['LS'] = true;

		global $DB, $USER_FIELD_MANAGER, $APPLICATION;

		if(!isset($arParams['CORRECT_DATE_PLAN']))
		{
			$arParams['CORRECT_DATE_PLAN'] = true;
		}
		if(!isset($arParams['CORRECT_DATE_PLAN_DEPENDENT_TASKS']))
		{
			$arParams['CORRECT_DATE_PLAN_DEPENDENT_TASKS'] = true;
		}
		if(!isset($arParams['THROTTLE_MESSAGES']))
		{
			$arParams['THROTTLE_MESSAGES'] = false;
		}

		$this->lastOperationResultData = array();

		if (isset($arFields['META::EVENT_GUID']))
		{
			$eventGUID = $arFields['META::EVENT_GUID'];
			unset($arFields['META::EVENT_GUID']);
		}
		else
			$eventGUID = sha1(uniqid('AUTOGUID', true));

		$bWasFatalError = false;

		$ID = intval($ID);
		if ($ID < 1)
			return false;

		$userID = null;

		$bCheckRightsOnFiles = false;	// for backward compatibility

		if (!is_array($arParams))
		{
			$arParams = array();
		}

		if (isset($arParams['USER_ID']) && ($arParams['USER_ID'] > 0))
		{
			$userID = (int) $arParams['USER_ID'];
		}

		if (isset($arParams['CHECK_RIGHTS_ON_FILES']))
		{
			if (
				($arParams['CHECK_RIGHTS_ON_FILES'] === 'Y')
				|| ($arParams['CHECK_RIGHTS_ON_FILES'] === true)
			)
			{
				$bCheckRightsOnFiles = true;
			}
			else
				$bCheckRightsOnFiles = false;
		}

		if(!isset($arParams['CORRECT_DATE_PLAN_DEPENDENT_TASKS']))
		{
			$arParams['CORRECT_DATE_PLAN_DEPENDENT_TASKS'] = true;
		}

		if(!isset($arParams['CORRECT_DATE_PLAN']))
		{
			$arParams['CORRECT_DATE_PLAN'] = true;
		}

		if ($userID === null)
		{
			$userID = User::getId();
			if(!$userID)
			{
				$userID = 1; // nasty, but for compatibility :(
			}
		}

		$rsTask = CTasks::GetByID($ID, false, array('USER_ID' => $userID));
		if ($arTask = $rsTask->Fetch())
		{
			if ($this->CheckFields($arFields, $ID, $userID))
			{
				$ufCheck = true;
				$hasUfs = \Bitrix\Tasks\Util\UserField::checkContainsUFKeys($arFields);
				if($hasUfs)
				{
					$ufCheck = $USER_FIELD_MANAGER->CheckFields("TASKS_TASK", $ID, $arFields, $userID);
				}

				if ($ufCheck)
				{
					unset($arFields["ID"]);

					$arBinds = array(
						"DESCRIPTION" => $arFields["DESCRIPTION"],
						"DECLINE_REASON" => $arFields["DECLINE_REASON"]
					);

					$time = User::getTime();

					$arFields["CHANGED_BY"] = $userID;
					$arFields["CHANGED_DATE"] = \Bitrix\Tasks\UI::formatDateTime($time);

					$occurAsUserId = CTasksTools::getOccurAsUserId();
					if ( ! $occurAsUserId )
						$occurAsUserId = ($arFields["CHANGED_BY"] ? $arFields["CHANGED_BY"] : 1);

					if (!$arFields["OUTLOOK_VERSION"])
					{
						$arFields["OUTLOOK_VERSION"] = ($arTask["OUTLOOK_VERSION"] ? $arTask["OUTLOOK_VERSION"] : 1) + 1;
					}

					// If new status code given AND new status code != current status => than update
					if (isset($arFields["STATUS"]) 
						&& ( (int) $arTask['STATUS'] !== (int) $arFields['STATUS'] )
					)
					{
						$arFields["STATUS_CHANGED_BY"] = $userID;
						$arFields["STATUS_CHANGED_DATE"] = \Bitrix\Tasks\UI::formatDateTime($time);

						if ($arFields["STATUS"] == 5 || $arFields["STATUS"] == 4)
						{
							$arFields["CLOSED_BY"] = $userID;
							$arFields["CLOSED_DATE"] = \Bitrix\Tasks\UI::formatDateTime($time);
						}
						else
						{
							$arFields["CLOSED_BY"] = false;
							$arFields["CLOSED_DATE"] = false;
							if ($arFields["STATUS"] == 3)
							{
								$arFields["DATE_START"] = \Bitrix\Tasks\UI::formatDateTime($time);
							}
						}
					}

					$shiftResult = null;
					if($arParams['CORRECT_DATE_PLAN'])
					{
						$parentChanged =
							array_key_exists('PARENT_ID', $arFields)
							&& ($arTask['PARENT_ID'] && $arFields['PARENT_ID'] != $arTask['PARENT_ID']);

						$datesChanged = static::datesChanged($arTask, $arFields);
						$followDatesChanged = static::followDatesSetTrue($arFields);

						if($parentChanged)
						{
							// task was attached previously, and now it is being unattached or reattached to smth else
							// then we need to recalculate its previous parent...
							$scheduler = \Bitrix\Tasks\Processor\Task\Scheduler::getInstance($userID);
							$shiftResultPrev = $scheduler->processEntity($ID, $arTask, array(
								'MODE' => 'BEFORE_DETACH',
							));
							if($shiftResultPrev->isSuccess())
							{
								$shiftResultPrev->save(array('!ID' => $ID));
							}
						}

						// when updating end or start date plan, we need to be sure the time is correct
						if($parentChanged || $datesChanged || $followDatesChanged)
						{
							$scheduler = \Bitrix\Tasks\Processor\Task\Scheduler::getInstance($userID);
							$shiftResult = $scheduler->processEntity($ID, $arFields, array(
								'MODE' => $parentChanged ? 'BEFORE_ATTACH' : '',
							));
							if($shiftResult->isSuccess())
							{
								$shiftData = $shiftResult->getImpactById($ID);
								if($shiftData)
								{
									// will be saved...
									$arFields['START_DATE_PLAN'] = $shiftData['START_DATE_PLAN'];
									$arFields['END_DATE_PLAN'] = $shiftData['END_DATE_PLAN'];
									$arFields['DURATION_PLAN_SECONDS'] = $shiftData['DURATION_PLAN_SECONDS'];

									$this->lastOperationResultData['SHIFT_RESULT'][$ID] = $shiftData;
								}
							}
						}
					}

					// END_DATE_PLAN will be dropped
					if(isset($arFields['END_DATE_PLAN']) && (string) $arFields['END_DATE_PLAN'] == '')
					{
						// duration is no longer adequate
						$arFields['DURATION_PLAN'] = 0;
					}

					self::processDurationPlanFields($arFields, (string) $arFields['DURATION_TYPE'] != '' ? $arFields['DURATION_TYPE'] : $arTask['DURATION_TYPE']);

					$arTaskCopy = $arTask;	// this will allow transfer data by pointer for speed-up
					foreach(GetModuleEvents('tasks', 'OnBeforeTaskUpdate', true) as $arEvent)
					{
						if (ExecuteModuleEventEx($arEvent, array($ID, &$arFields, &$arTaskCopy))===false)
						{
							$errmsg = GetMessage("TASKS_UNKNOWN_UPDATE_ERROR");
							$errno  = 'ERROR_UNKNOWN_UPDATE_TASK_ERROR';

							if ($ex = $APPLICATION->getException())
							{
								$errmsg = $ex->getString();
								$errno  = $ex->getId();
							}

							$this->_errors[] = array('text' => $errmsg, 'id' => $errno);

							return false;
						}
					}

					$strUpdate = $DB->PrepareUpdate("b_tasks", $arFields, "tasks");
					$strSql = "UPDATE b_tasks SET ".$strUpdate." WHERE ID=".$ID;
					$result = $DB->QueryBind($strSql, $arBinds, false, "File: ".__FILE__."<br>Line: ".__LINE__);

					if ($result)
					{

						$arParticipants = array_merge(
							array(
								$arTask['CREATED_BY'], 
								$arTask['RESPONSIBLE_ID']
							), 
							(array) $arTask['ACCOMPLICES'],
							(array) $arTask['AUDITORS']
						);

						if (isset($arFields['CREATED_BY']))
							$arParticipants[] = $arFields['CREATED_BY'];

						if (isset($arFields['RESPONSIBLE_ID']))
							$arParticipants[] = $arFields['RESPONSIBLE_ID'];

						if (isset($arFields['ACCOMPLICES']))
						{
							$arParticipants = array_merge(
								$arParticipants,
								(array) $arFields['ACCOMPLICES']
							);
						}

						if (isset($arFields['AUDITORS']))
						{
							$arParticipants = array_merge(
								$arParticipants,
								(array) $arFields['AUDITORS']
							);
						}

						$arParticipants = array_unique($arParticipants);

						// Emit pull event
						try
						{
							$arPullRecipients = array();

							foreach($arParticipants as $userId)
								$arPullRecipients[] = (int) $userId;

							$taskGroupId = 0;	// no group
							$taskGroupIdBeforeUpdate = 0;	// no group

							if (isset($arTask['GROUP_ID']) && ($arTask['GROUP_ID'] > 0))
								$taskGroupId = (int) $arTask['GROUP_ID'];

							// if $arFields['GROUP_ID'] not given, than it means, 
							// that group not changed during this update, so
							// we must take existing group_id (from $arTask)
							if ( ! array_key_exists('GROUP_ID', $arFields) )
							{
								if (isset($arTask['GROUP_ID']) && ($arTask['GROUP_ID'] > 0))
									$taskGroupIdBeforeUpdate = (int) $arTask['GROUP_ID'];
								else
									$taskGroupIdBeforeUpdate = 0;	// no group
							}
							else	// Group given, use it
							{
								if ($arFields['GROUP_ID'] > 0)
									$taskGroupIdBeforeUpdate = (int) $arFields['GROUP_ID'];
								else
									$taskGroupIdBeforeUpdate = 0;	// no group
							}
								
							$arPullData = array(
								'TASK_ID' => (int) $ID,
								'BEFORE' => array(
									'GROUP_ID' => $taskGroupId
								),
								'AFTER' => array(
									'GROUP_ID' => $taskGroupIdBeforeUpdate
								),
								'TS' => time(),
								'event_GUID' => $eventGUID
							);

							self::EmitPullWithTagPrefix(
								$arPullRecipients, 
								'TASKS_GENERAL_', 
								'task_update', 
								$arPullData
							);

							self::EmitPullWithTag(
								$arPullRecipients, 
								'TASKS_TASK_' . (int) $ID, 
								'task_update', 
								$arPullData
							);
						}
						catch (Exception $e)
						{
							$bWasFatalError = true;
							$this->_errors[] = 'at line ' . $e->GetLine() 
								. ', ' . $e->GetMessage();
						}

						// changes log
						$arTmp = array('arTask' => $arTask, 'arFields' => $arFields);

						if (isset($arTask['DURATION_PLAN']))// && isset($arTask['DURATION_TYPE']))
						{
							$arTmp['arTask']['DURATION_PLAN_SECONDS'] = $arTask['DURATION_PLAN_SECONDS'];
							unset($arTmp['arTask']['DURATION_PLAN']);
						}

						if (isset($arFields['DURATION_PLAN']))// && isset($arFields['DURATION_TYPE']))
						{
							// at this point, $arFields['DURATION_PLAN'] in seconds
							$arTmp['arFields']['DURATION_PLAN_SECONDS'] = $arFields['DURATION_PLAN'];
							unset($arTmp['arFields']['DURATION_PLAN']);
						}

						$arChanges = CTaskLog::GetChanges($arTmp['arTask'], $arTmp['arFields']);

						unset($arTmp);

						foreach ($arChanges as $key => $value)
						{
							$arLogFields = array(
								"TASK_ID" => $ID,
								"USER_ID" => $occurAsUserId,
								"CREATED_DATE" => $arFields["CHANGED_DATE"],
								"FIELD" => $key,
								"FROM_VALUE" => $value["FROM_VALUE"],
								"TO_VALUE" => $value["TO_VALUE"]
							);

							$log = new CTaskLog();
							$log->Add($arLogFields);
						}

						if (isset($arFields["RESPONSIBLE_ID"]) && isset($arChanges["RESPONSIBLE_ID"]))
						{
							CTaskMembers::updateForTask($ID, array($arFields['RESPONSIBLE_ID']), 'R');
						}
						if (isset($arFields["CREATED_BY"]) && isset($arChanges["CREATED_BY"]))
						{
							CTaskMembers::updateForTask($ID, array($arFields['CREATED_BY']), 'O');
						}

						if (isset($arFields["ACCOMPLICES"]) && isset($arChanges["ACCOMPLICES"]))
						{
							CTaskMembers::updateForTask($ID, $arFields["ACCOMPLICES"], 'A');
						}

						if (isset($arFields["AUDITORS"]) && isset($arChanges["AUDITORS"]))
						{
							CTaskMembers::updateForTask($ID, $arFields["AUDITORS"], 'U');
						}

						if (isset($arFields["FILES"]) && (isset($arChanges["NEW_FILES"]) || isset($arChanges["DELETED_FILES"])))
						{
							$arNotDeleteFiles = $arFields["FILES"];
							CTaskFiles::DeleteByTaskID($ID, $arNotDeleteFiles);
							CTasks::AddFiles(
								$ID,
								$arFields["FILES"],
								array(
									'USER_ID'               => $userID,
									'CHECK_RIGHTS_ON_FILES' => $bCheckRightsOnFiles
								)
							);
						}

						if (isset($arFields["TAGS"]) && isset($arChanges["TAGS"]))
						{
							CTaskTags::DeleteByTaskID($ID);
							CTasks::AddTags($ID, $arTask["CREATED_BY"], $arFields["TAGS"], $userID);
						}

						if (isset($arFields["DEPENDS_ON"]) && isset($arChanges["DEPENDS_ON"]))
						{
							CTaskDependence::DeleteByTaskID($ID);
							CTasks::AddPrevious($ID, $arFields["DEPENDS_ON"]);
						}

						if($hasUfs)
						{
							$USER_FIELD_MANAGER->Update("TASKS_TASK", $ID, $arFields, $userID);
						}

						// backward compatibility with PARENT_ID
						if(array_key_exists('PARENT_ID', $arFields))
						{
							// PARENT_ID changed, reattach subtree from previous location to new one
							\Bitrix\Tasks\Internals\Helper\Task\Dependence::attach($ID, intval($arFields['PARENT_ID']));
						}

						// tasks dependence

						if($shiftResult !== null && $arParams['CORRECT_DATE_PLAN_DEPENDENT_TASKS'])
						{
							$saveResult = $shiftResult->save(array('!ID' => $ID));
							if($saveResult->isSuccess())
							{
								$this->lastOperationResultData['SHIFT_RESULT'] = $shiftResult->exportData();
							}
						}

						if(array_key_exists('STATUS', $arFields) && $arFields['STATUS'] == 5)
						{
							if($arParams['AUTO_CLOSE'] !== false)
							{
								$closer = \Bitrix\Tasks\Processor\Task\AutoCloser::getInstance($userID);
								$closeResult = $closer->processEntity($ID, $arFields);
								if($closeResult->isSuccess())
								{
									$closeResult->save(array('!ID' => $ID));
								}
							}
						}

						$bSkipNotification = (isset($arParams['SKIP_NOTIFICATION']) && $arParams['SKIP_NOTIFICATION']);
						$notifArFields = array_merge($arFields, array('CHANGED_BY' => $occurAsUserId));

						if (($status = intval($arFields["STATUS"])) && $status > 0 && $status < 8
							&& ( (int) $arTask['STATUS'] !== (int) $arFields['STATUS'] )	// only if status changed
						)
						{
							if ($status == 7)
							{
								$arTask["DECLINE_REASON"] = $arFields["DECLINE_REASON"];
							}

							if (!$bSkipNotification)
							{
								CTaskNotifications::SendStatusMessage($arTask, $status, $notifArFields);
							}
						}

						if (!$bSkipNotification)
						{
							CTaskNotifications::SendUpdateMessage($notifArFields, $arTask, false, $arParams);
						}

						CTaskComments::onAfterTaskUpdate($ID, $arTask, $arFields);

						$arFields["ID"] = $ID;

						$arMergedFields = array_merge($arTask, $arFields);

						CTaskSync::UpdateItem($arFields, $arTask); // MS Exchange

						$arFields['META:PREV_FIELDS'] = $arTask;

						try
						{
							$lastEventName = '';
							foreach(GetModuleEvents('tasks', 'OnTaskUpdate', true) as $arEvent)
							{
								$lastEventName = $arEvent['TO_CLASS'] . '::' . $arEvent['TO_METHOD'] . '()';
								ExecuteModuleEventEx($arEvent, array($ID, &$arFields, &$arTaskCopy));
							}
						}
						catch (Exception $e)
						{
							CTaskAssert::logWarning(
								'[0xee8999a8] exception in module event: ' . $lastEventName
								. '; at file: ' . $e->getFile() . ':' . $e->getLine() . ";\n"
							);
							\Bitrix\Tasks\Util::log($e);
						}

						unset($arFields['META:PREV_FIELDS']);

						CTasks::Index($arMergedFields, $arFields["TAGS"]); // search index

						// clear cache
						static::addCacheIdToClear("tasks_".$ID);

						if ($arTask["GROUP_ID"])
						{
							static::addCacheIdToClear("tasks_group_".$arTask["GROUP_ID"]);
						}

						if ($arFields['GROUP_ID'] && ($arFields['GROUP_ID'] != $arTask['GROUP_ID']))
						{
							static::addCacheIdToClear("tasks_group_".$arFields["GROUP_ID"]);
						}

						foreach($arParticipants as $userId)
						{
							static::addCacheIdToClear("tasks_user_".$userId);
						}

						CTaskCountersProcessor::onAfterTaskUpdate($arTask, $arFields);

						static::clearCache();

						if ($bWasFatalError)
						{
							soundex('push&pull: bWasFatalError === true');
						}

						//_dump_r($this->lastOperationResultData['SHIFT_RESULT']);

						$this->previousData = $arTask;

						return true;
					}
				}
				else
				{
					$e = $APPLICATION->GetException();
					foreach($e->messages as $msg)
					{
						$this->_errors[] = $msg;
					}
				}
			}
		}

		if (sizeof($this->_errors) == 0)
			$this->_errors[] = array("text" => GetMessage("TASKS_UNKNOWN_UPDATE_ERROR"), "id" => "ERROR_UNKNOWN_UPDATE_TASK_ERROR");

		return false;
	}

	private static function datesChanged($was, $now)
	{
		if(!array_key_exists('START_DATE_PLAN', $now) && !array_key_exists('END_DATE_PLAN', $now))
		{
			return false;
		}

		return ((string) $was['START_DATE_PLAN'] != (string) $now['START_DATE_PLAN'])
				||
				((string) $was['END_DATE_PLAN'] != (string) $now['END_DATE_PLAN']);
	}

	private static function followDatesSetTrue($fields)
	{
		if(array_key_exists('SE_PARAMETER', $fields) && is_array($fields['SE_PARAMETER']))
		{
			foreach($fields['SE_PARAMETER'] as $parameter)
			{
				if($parameter['CODE'] == 1 && $parameter['VALUE'] == 'Y')
				{
					return true;
				}
			}
		}

		return false;
	}

	public static function checkCacheAutoClearEnabled()
	{
		return static::$cacheClearEnabled;
	}

	public static function disableCacheAutoClear()
	{
		if(!static::$cacheClearEnabled)
		{
			return false;
		}

		static::$cacheClearEnabled = false;

		return true;
	}
	public static function enableCacheAutoClear($clearNow = true)
	{
		static::$cacheClearEnabled = true;

		if($clearNow)
		{
			static::clearCache();
		}
	}

	private static function addCacheIdToClear($cacheId)
	{
		if((string) $cacheId === '')
		{
			return;
		}

		static::$cacheIds[$cacheId] = true;
	}
	private static function clearCache()
	{
		if(!static::$cacheClearEnabled)
		{
			return;
		}

		global $CACHE_MANAGER;

		if(!empty(static::$cacheIds))
		{
			foreach(static::$cacheIds as $id => $void)
			{
				$CACHE_MANAGER->ClearByTag($id);
			}

			static::$cacheIds = array();
		}
	}

	/**
	 * This method is deprecated. Use CTaskItem::delete() instead.
	 * @deprecated
	 */
	public static function Delete($ID, $arParams = array())
	{
		global $DB, $CACHE_MANAGER;

		$actorUserId = User::getId();
		if(!$actorUserId)
		{
			$actorUserId = User::getAdminId();
		}

		if (isset($arParams['META::EVENT_GUID']))
		{
			$eventGUID = $arParams['META::EVENT_GUID'];
			unset($arParams['META::EVENT_GUID']);
		}
		else
			$eventGUID = sha1(uniqid('AUTOGUID', true));

		$paramSkipExchangeSync = false;

		if (is_array($arParams))
		{
			if (
				isset($arParams['skipExchangeSync'])
				&& (
					($arParams['skipExchangeSync'] === 'Y')
					|| ($arParams['skipExchangeSync'] === true)
				)
			)
			{
				$paramSkipExchangeSync = true;
			}
		}

		$ID = intval($ID);
		if ($ID < 1)
			return false;

		$rsTask = CTasks::GetByID($ID, false);
		if ($arTask = $rsTask->Fetch())
		{
			foreach(GetModuleEvents('tasks', 'OnBeforeTaskDelete', true) as $arEvent)
			{
				if (ExecuteModuleEventEx($arEvent, array($ID, $arTask))===false)
				{
					return false;
				}
			}

			CTaskMembers::DeleteAllByTaskID($ID);
			CTaskFiles::DeleteByTaskID($ID);
			CTaskDependence::DeleteByTaskID($ID);
			CTaskDependence::DeleteByDependsOnID($ID);
			CTaskTags::DeleteByTaskID($ID);

			FavoriteTable::deleteByTaskId($ID, array('LOW_LEVEL' => true));
			SortingTable::deleteByTaskId($ID);

			// todo: this function was not included into tablet file, but still should be placed to the business logic layer of the "viewed" entity
			$list = \Bitrix\Tasks\Internals\Task\ViewedTable::getList(array(
				"select" => array("TASK_ID", "USER_ID"),
				"filter" => array(
					"=TASK_ID" => $ID,
				),
			));
			while ($item = $list->fetch())
			{
				\Bitrix\Tasks\Internals\Task\ViewedTable::delete($item);
			}

			CTaskReminders::DeleteByTaskID($ID);

			// delete parameters
			$list = \Bitrix\Tasks\Internals\Task\ParameterTable::getList(array(
				"select" => array("ID"),
				"filter" => array(
					"=TASK_ID" => $ID,
				),
			));
			while ($item = $list->fetch())
			{
				\Bitrix\Tasks\Internals\Task\ParameterTable::delete($item);
			}

			// by default, CTasks::Delete() should not delete the entire sub-tree, so we need to delete only node itself
			\Bitrix\Tasks\Internals\Helper\Task\Dependence::delete($ID);
			// todo: we need to update PARENT_ID of each sub-task

			if($arTask['PARENT_ID'] && $arTask['START_DATE_PLAN'] && $arTask['END_DATE_PLAN'])
			{
				// we need to scan for parent bracket tasks change...
				$scheduler = \Bitrix\Tasks\Processor\Task\Scheduler::getInstance($actorUserId);
				// we could use MODE => DETACH here, but there we can act in more effective way by
				// re-calculating tree of PARENT_ID after removing link between ID and PARENT_ID
				// we also do not need to calculate detached tree
				// it is like DETACH_AFTER
				$shiftResult = $scheduler->processEntity($arTask['PARENT_ID']);
				if($shiftResult->isSuccess())
				{
					$shiftResult->save();
				}
			}

			// todo: see \CTaskPlannerMaintance::reviseTaskLists(), move task list from option to a table, and then just do cleaning
			// todo: dayplan by TASK_ID here for each user, regardless to the role; the following solution works only for current user, creator and responsible
			//\CTaskPlannerMaintance::plannerActions(array('remove' => array($ID)));
			//\CTaskPlannerMaintance::plannerActions(array('remove' => array($ID)), SITE_ID, $arTask['CREATED_BY']);
			//\CTaskPlannerMaintance::plannerActions(array('remove' => array($ID)), SITE_ID, $arTask['RESPONSIBLE_ID']);

			$CACHE_MANAGER->ClearByTag("tasks_".$ID);

			// clear cache
			if ($arTask["GROUP_ID"])
			{
				$CACHE_MANAGER->ClearByTag("tasks_group_".$arTask["GROUP_ID"]);
			}
			$arParticipants = array_unique(array_merge(array($arTask["CREATED_BY"], $arTask["RESPONSIBLE_ID"]), $arTask["ACCOMPLICES"], $arTask["AUDITORS"]));
			foreach($arParticipants as $userId)
			{
				$CACHE_MANAGER->ClearByTag("tasks_user_".$userId);
			}

			$strSql = "UPDATE b_tasks_template SET TASK_ID = NULL WHERE TASK_ID = ".$ID;
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			$strSql = "UPDATE b_tasks_template SET PARENT_ID = ".($arTask["PARENT_ID"] ? $arTask["PARENT_ID"] : "NULL")." WHERE PARENT_ID = ".$ID;
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			$strSql = "UPDATE b_tasks SET PARENT_ID = ".($arTask["PARENT_ID"] ? $arTask["PARENT_ID"] : "NULL")." WHERE PARENT_ID = ".$ID;
			$DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			$strUpdate = $DB->PrepareUpdate(
				"b_tasks",
				array(
					'ZOMBIE'       => 'Y',
					'CHANGED_BY'   => $actorUserId, 
					'CHANGED_DATE' => \Bitrix\Tasks\UI::formatDateTime(User::getTime())
				),
				"tasks"
			);

			$strSql = "UPDATE b_tasks SET " . $strUpdate . " WHERE ID = " . (int) $ID;

			if ($DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__))
			{
				CTaskNotifications::SendDeleteMessage($arTask);

				// todo: replace with event binding or processor
				\Bitrix\Tasks\Integration\Forum\Task\Topic::delete($arTask["FORUM_TOPIC_ID"]);

				if ( ! $paramSkipExchangeSync )
					CTaskSync::DeleteItem($arTask); // MS Exchange

				// Emit pull event
				try
				{
					$arPullRecipients = array();

					foreach($arParticipants as $userId)
						$arPullRecipients[] = (int) $userId;

					$taskGroupId = 0;	// no group

					if (isset($arTask['GROUP_ID']) && ($arTask['GROUP_ID'] > 0))
						$taskGroupId = (int) $arTask['GROUP_ID'];

					$arPullData = array(
						'TASK_ID' => (int) $ID,
						'BEFORE' => array(
							'GROUP_ID' => $taskGroupId
						),
						'TS' => time(),
						'event_GUID' => $eventGUID
					);

					self::EmitPullWithTagPrefix(
						$arPullRecipients, 
						'TASKS_GENERAL_', 
						'task_remove', 
						$arPullData
					);

					self::EmitPullWithTag(
						$arPullRecipients, 
						'TASKS_TASK_' . (int) $ID, 
						'task_remove', 
						$arPullData
					);
				}
				catch (Exception $e)
				{
				}

				foreach(GetModuleEvents('tasks', 'OnTaskDelete', true) as $arEvent)
					ExecuteModuleEventEx($arEvent, array($ID));

				if (CModule::IncludeModule("search"))
				{
					CSearch::DeleteIndex("tasks", $ID);
				}

				CTaskCountersProcessor::onAfterTaskDelete($arTask);
			}

			return true;
		}

		return false;
	}


	/**
	 * @param $ID
	 *
	 * This method MUST be called after sync with all Outlook clients.
	 * We can't determine such moment, so we should terminate zombies
	 * for some time after task been deleted.
	 */
	private static function terminateZombie($ID)
	{
		global $DB, $USER_FIELD_MANAGER;

		$res = CTasks::GetList(
			array(),
			array('ID' => (int) $ID, 'ZOMBIE' => 'Y'),
			array('ID'),
			array('bGetZombie' => true)
		);

		if ($res && ($task = $res->fetch()))
		{
			foreach(GetModuleEvents('tasks', 'OnBeforeTaskZombieDelete', true) as $arEvent)
				ExecuteModuleEventEx($arEvent, array($ID));

			CTaskCheckListItem::deleteByTaskId($ID);
			CTaskLog::DeleteByTaskId($ID);

			$USER_FIELD_MANAGER->Delete("TASKS_TASK", $ID);

			$strSql = "DELETE FROM b_tasks WHERE ID = " . (int) $ID;

			$DB->query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

			foreach(GetModuleEvents('tasks', 'OnTaskZombieDelete', true) as $arEvent)
				ExecuteModuleEventEx($arEvent, array($ID));
		}
	}


	protected static function GetSqlByFilter ($arFilter, $userID, $sAliasPrefix, $bGetZombie, $bMembersTableJoined = false)
	{
		global $DB;

		$bFullJoin = null;

		if ( ! is_array($arFilter) )
			throw new TasksException('GetSqlByFilter: expected array, but something other given: ' . var_export($arFilter, true));

		$logicStr = ' AND ';

		if (isset($arFilter['::LOGIC']))
		{
			switch ($arFilter['::LOGIC'])
			{
				case 'AND':
					$logicStr = ' AND ';
				break;

				case 'OR':
					$logicStr = ' OR ';
				break;

				default:
					throw new TasksException('Unknown logic in filter');
				break;
			}
		}

		$arSqlSearch = array();

		foreach ($arFilter as $key => $val)
		{
			// Skip meta-key
			if ($key === '::LOGIC')
				continue;

			// Skip markers
			if ($key === '::MARKERS')
				continue;

			// Subfilter?
			if (static::isSubFilterKey($key))
			{
				$arSqlSearch[] = self::GetSqlByFilter ($val, $userID, $sAliasPrefix, $bGetZombie, $bMembersTableJoined);
				continue;
			}

			$key = ltrim($key);

			// This type of operations should be processed in special way
			// Fields like "META:DEADLINE_TS" will be replaced to "DEADLINE"
			if (substr($key, -3) === '_TS')
			{
				$arSqlSearch = array_merge(
					$arSqlSearch,
					self::getSqlForTimestamps($key, $val, $userID, $sAliasPrefix, $bGetZombie)
				);

				continue;
			}

			$res = CTasks::MkOperationFilter($key);
			$key = $res["FIELD"];
			$cOperationType = $res["OPERATION"];

			$key = strtoupper($key);

			switch ($key)
			{
				case 'META::ID_OR_NAME':
					if (strtoupper($DB->type) == "ORACLE")
						$arSqlSearch[] = " (" . $sAliasPrefix . "T.ID = '" . intval($val) . "' OR (UPPER(" . $sAliasPrefix . "T.TITLE) UPPER('%" . $DB->ForSqlLike($val) ."%')) ) ";
					else
						$arSqlSearch[] = " (" . $sAliasPrefix . "T.ID = '" . intval($val) . "' OR (UPPER(" . $sAliasPrefix . "T.TITLE) LIKE UPPER('%" . $DB->ForSqlLike($val) . "%')) ) ";
					break;

				//case "DURATION_PLAN": // temporal
				case "PARENT_ID":
				case "GROUP_ID":
				case "STATUS_CHANGED_BY":
				case "FORUM_TOPIC_ID":
					$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix."T.".$key, $val, "number", $bFullJoin, $cOperationType);
					break;

				case "ID":
				case "PRIORITY":
				case "CREATED_BY":
				case "RESPONSIBLE_ID":
				case 'TIME_ESTIMATE':
					$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix."T.".$key, $val, "number_wo_nulls", $bFullJoin, $cOperationType);
					break;

				case "REFERENCE:RESPONSIBLE_ID":
					$key = 'RESPONSIBLE_ID';
					$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix."T.".$key, $val, 'reference', $bFullJoin, $cOperationType);
					break;

				case 'META:GROUP_ID_IS_NULL_OR_ZERO':
					$key = 'GROUP_ID';
					$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix."T.".$key, $val, "null_or_zero", $bFullJoin, $cOperationType, false);
					break;

				case "CHANGED_BY":
					$arSqlSearch[] = CTasks::FilterCreate("CASE WHEN ".$sAliasPrefix."T.".$key." IS NULL THEN ".$sAliasPrefix."T.CREATED_BY ELSE ".$sAliasPrefix."T.".$key." END", $val, "number", $bFullJoin, $cOperationType);
					break;

				case 'GUID':
				case 'TITLE':
					$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix."T.".$key, $val, "string", $bFullJoin, $cOperationType);
					break;

				case "TAG":
					if (!is_array($val))
					{
						$val = array($val);
					}
					$arConds = array();
					foreach ($val as $tag)
					{
						if ($tag)
						{
							$arConds[] = "(".$sAliasPrefix."TT.NAME = '".$DB->ForSql($tag)."')";
						}
					}
					if (sizeof($arConds))
					{
						$arSqlSearch[] = "EXISTS(
							SELECT
								'x'
							FROM
								b_tasks_tag ".$sAliasPrefix."TT
							WHERE
								(".implode(" OR ", $arConds).")
							AND
								".$sAliasPrefix."TT.TASK_ID = ".$sAliasPrefix."T.ID
						)";
					}
					break;

				case 'REAL_STATUS':
					$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix . "T.STATUS", $val, "number", $bFullJoin, $cOperationType);
				break;

				case 'DEADLINE_COUNTED':
					$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix . "T.DEADLINE_COUNTED", $val, "number_wo_nulls", $bFullJoin, $cOperationType);
				break;

				case 'VIEWED':
					$arSqlSearch[] = CTasks::FilterCreate("
						CASE
							WHEN
								".$sAliasPrefix."TV.USER_ID IS NULL
								AND
								(".$sAliasPrefix."T.STATUS = 1 OR ".$sAliasPrefix."T.STATUS = 2)
							THEN
								'0'
							ELSE
								'1'
						END
					", $val, "number", $bFullJoin, $cOperationType);
				break;

				case "STATUS_EXPIRED": // expired: deadline in past and

					$arSqlSearch[] = ($cOperationType == 'N' ? 'not' : '')."(".$sAliasPrefix."T.DEADLINE < ".$DB->CurrentTimeFunction()." AND ".$sAliasPrefix."T.STATUS != '4' AND ".$sAliasPrefix."T.STATUS != '5' AND (".$sAliasPrefix."T.STATUS != '7' OR ".$sAliasPrefix."T.RESPONSIBLE_ID != ".$userID."))";

					break;

				case "STATUS_NEW": // viewed by a specified user + status is either new or pending

					$arSqlSearch[] = ($cOperationType == 'N' ? 'not' : '')."(

						".$sAliasPrefix."TV.USER_ID IS NULL
						AND
						".$sAliasPrefix."T.CREATED_BY != ".$userID."
						AND
						(".$sAliasPrefix."T.STATUS = 1 OR ".$sAliasPrefix."T.STATUS = 2)

					)";
					$bFullJoin = true; // join TV

					break;

				case "STATUS":
					$arSqlSearch[] = CTasks::FilterCreate("
						CASE
							WHEN
								".$sAliasPrefix."T.DEADLINE < ".$DB->CurrentTimeFunction()." AND ".$sAliasPrefix."T.STATUS != '4' AND ".$sAliasPrefix."T.STATUS != '5' AND (".$sAliasPrefix."T.STATUS != '7' OR ".$sAliasPrefix."T.RESPONSIBLE_ID != ".$userID.")
							THEN
								'-1'
							WHEN
								".$sAliasPrefix."TV.USER_ID IS NULL
								AND
								".$sAliasPrefix."T.CREATED_BY != ".$userID."
								AND
								(".$sAliasPrefix."T.STATUS = 1 OR ".$sAliasPrefix."T.STATUS = 2)
							THEN
								'-2'
							ELSE
								".$sAliasPrefix."T.STATUS
						END
					", $val, "number", $bFullJoin, $cOperationType);

					break;

				case 'MARK':
				case 'XML_ID':
				case 'SITE_ID':
				case 'ZOMBIE':
				case 'ADD_IN_REPORT':
				case 'ALLOW_TIME_TRACKING':
				case 'ALLOW_CHANGE_DEADLINE':
				case 'MATCH_WORK_TIME':
					$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix."T.".$key, $val, "string_equal", $bFullJoin, $cOperationType);
					break;

				case "END_DATE_PLAN":
				case "START_DATE_PLAN":
				case "DATE_START":
				case "DEADLINE":
				case "CREATED_DATE":
				case "CLOSED_DATE":
					if (($val === false) || ($val === ''))
						$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix."T.".$key, $val, "date", $bFullJoin, $cOperationType, $bSkipEmpty = false);
					else
						$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix."T.".$key, $DB->CharToDateFunction($val), "date", $bFullJoin, $cOperationType);
					break;

				case "CHANGED_DATE":
					$arSqlSearch[] = CTasks::FilterCreate("CASE WHEN ".$sAliasPrefix."T.".$key." IS NULL THEN ".$sAliasPrefix."T.CREATED_DATE ELSE ".$sAliasPrefix."T.".$key." END", $DB->CharToDateFunction($val), "date", $bFullJoin, $cOperationType);
					break;

				case "ACCOMPLICE":
					if (!is_array($val))
						$val = array($val);

					$val = array_filter($val);

					$arConds = array();

					if ($bMembersTableJoined)
					{
						if ($cOperationType !== 'N')
						{
							foreach ($val as $id)
								$arConds[] = "(".$sAliasPrefix."TM.USER_ID = '".intval($id)."')";

							if ( ! empty($arConds) )
								$arSqlSearch[] = '(' . $sAliasPrefix . "TM.TYPE = 'A' AND (" . implode(" OR ", $arConds) . '))';
						}
						else
						{
							foreach ($val as $id)
								$arConds[] = "(".$sAliasPrefix."TM.USER_ID != '".intval($id)."')";

							if ( ! empty($arConds) )
								$arSqlSearch[] = '(' . $sAliasPrefix . "TM.TYPE = 'A' AND (" . implode(" AND ", $arConds) . '))';
						}
					}
					else
					{
						foreach ($val as $id)
							$arConds[] = "(".$sAliasPrefix."TM.USER_ID = '".intval($id)."')";

						if ( ! empty($arConds) )
						{
							$arSqlSearch[] = ($cOperationType !== 'N' ? 'EXISTS' : 'NOT EXISTS') . "(
								SELECT
									'x'
								FROM
									b_tasks_member ".$sAliasPrefix."TM
								WHERE
									(".implode(" OR ", $arConds).")
								AND
									".$sAliasPrefix."TM.TASK_ID = ".$sAliasPrefix."T.ID
								AND
									".$sAliasPrefix."TM.TYPE = 'A'
							)";
						}
					}
					break;

				case "PERIOD":
				case "ACTIVE":
					if ($val["START"] || $val["END"])
					{
						$strDateStart = $strDateEnd = false;

						if (MakeTimeStamp($val['START']) > 0)
						{
							$strDateStart = $DB->CharToDateFunction(
								$DB->ForSql(
									CDatabase::FormatDate(
										$val['START'], 
										FORMAT_DATETIME
									)
								)
							);
						}

						if (MakeTimeStamp($val['END']))
						{
							$strDateEnd = $DB->CharToDateFunction(
								$DB->ForSql(
									CDatabase::FormatDate(
										$val['END'], 
										FORMAT_DATETIME
									)
								)
							);
						}

						if (($strDateStart !== false) && ($strDateEnd !== false))
						{
							$arSqlSearch[] = "(
									(T.CREATED_DATE >= $strDateStart AND T.CREATED_DATE <= $strDateEnd)
								OR
									(T.CLOSED_DATE >= $strDateStart AND T.CLOSED_DATE <= $strDateEnd)
								)";
						}
						elseif (($strDateStart !== false) && ($strDateEnd === false))
						{
							$arSqlSearch[] = "(
									(T.CREATED_DATE >= $strDateStart)
								OR
									(T.CLOSED_DATE >= $strDateStart)
								)";
						}
						elseif (($strDateStart === false) && ($strDateEnd !== false))
						{
							$arSqlSearch[] = "(
									(T.CREATED_DATE <= $strDateEnd)
								OR
									(T.CLOSED_DATE <= $strDateEnd)
								)";
						}
					}
					break;

				case "AUDITOR":
					if (!is_array($val))
						$val = array($val);

					$val = array_filter($val);

					$arConds = array();

					if ($bMembersTableJoined)
					{
						if ($cOperationType !== 'N')
						{
							foreach ($val as $id)
								$arConds[] = "(".$sAliasPrefix."TM.USER_ID = '".intval($id)."')";

							if ( ! empty($arConds) )
								$arSqlSearch[] = '(' . $sAliasPrefix . "TM.TYPE = 'U' AND (" . implode(" OR ", $arConds) . '))';
						}
						else
						{
							foreach ($val as $id)
								$arConds[] = "(".$sAliasPrefix."TM.USER_ID != '".intval($id)."')";

							if ( ! empty($arConds) )
								$arSqlSearch[] = '(' . $sAliasPrefix . "TM.TYPE = 'U' AND (" . implode(" AND ", $arConds) . '))';
						}
					}
					else
					{
						foreach ($val as $id)
							$arConds[] = "(".$sAliasPrefix."TM.USER_ID = '".intval($id)."')";

						if ( ! empty($arConds) )
						{						
							$arSqlSearch[] = ($cOperationType !== 'N' ? 'EXISTS' : 'NOT EXISTS') . "(
								SELECT
									'x'
								FROM
									b_tasks_member ".$sAliasPrefix."TM
								WHERE
									(".implode(" OR ", $arConds).")
								AND
									".$sAliasPrefix."TM.TASK_ID = ".$sAliasPrefix."T.ID
								AND
									".$sAliasPrefix."TM.TYPE = 'U'
							)";
						}
					}

					break;

				case "DOER":
					$val = intval($val);
					$arSqlSearch[] = "(
						".$sAliasPrefix."T.RESPONSIBLE_ID = ".$val."
						OR
						EXISTS(
							SELECT 'x'
							FROM
								b_tasks_member ".$sAliasPrefix."TM
							WHERE
								".$sAliasPrefix."TM.TASK_ID = ".$sAliasPrefix."T.ID
								AND
								".$sAliasPrefix."TM.USER_ID = '".$val."'
								AND
								".$sAliasPrefix."TM.TYPE = 'A'
							)
						)";
					break;

				case "MEMBER":
					$val = intval($val);
					$arSqlSearch[] = "(
						".$sAliasPrefix."T.CREATED_BY = ".intval($val)."
						OR
						".$sAliasPrefix."T.RESPONSIBLE_ID = ".intval($val)."
						OR
						EXISTS(
							SELECT 'x' FROM b_tasks_member ".$sAliasPrefix."TM
							WHERE
								".$sAliasPrefix."TM.TASK_ID = ".$sAliasPrefix."T.ID
								AND
								".$sAliasPrefix."TM.USER_ID = '".$val."'
						)
					)";
					break;

				case "DEPENDS_ON":
					if (!is_array($val))
					{
						$val = array($val);
					}
					$arConds = array();
					foreach ($val as $id)
					{
						if ($id)
						{
							$arConds[] = "(".$sAliasPrefix."TD.TASK_ID = '".intval($id)."')";
						}
					}
					if (sizeof($arConds))
					{
						$arSqlSearch[] = "EXISTS(
							SELECT
								'x'
							FROM
								b_tasks_dependence ".$sAliasPrefix."TD
							WHERE
								(".implode(" OR ", $arConds).")
							AND
								".$sAliasPrefix."TD.DEPENDS_ON_ID = ".$sAliasPrefix."T.ID
						)";
					}
					break;

				case "ONLY_ROOT_TASKS":
					if ($val == "Y")
					{
						$arSqlSearch[] = "(".$sAliasPrefix."T.PARENT_ID IS NULL OR ".$sAliasPrefix."T.PARENT_ID = '0' OR NOT EXISTS (".CTasks::GetRootSubquery($arFilter, $bGetZombie, $sAliasPrefix)."))";
					}
					break;

				case "SUBORDINATE_TASKS":
					if ($val == "Y")
					{
						$arSubSqlSearch = array(
							$sAliasPrefix."T.CREATED_BY = ".$userID,
							$sAliasPrefix."T.RESPONSIBLE_ID = ".$userID,
							"EXISTS(
								SELECT 'x'
								FROM
									b_tasks_member ".$sAliasPrefix."TM
								WHERE
									".$sAliasPrefix."TM.TASK_ID = ".$sAliasPrefix."T.ID
									AND
									".$sAliasPrefix."TM.USER_ID = ".$userID."
							)"
						);
						// subordinate check
						if ($strSql = CTasks::GetSubordinateSql($sAliasPrefix, array('USER_ID' => $userID)))
						{
							$arSubSqlSearch[] = "EXISTS(".$strSql.")";
						}

						$arSqlSearch[] = "(".implode(" OR ", $arSubSqlSearch).")";
					}
					break;

				case "OVERDUED":
					if ($val == "Y")
					{
						$arSqlSearch[] = $sAliasPrefix."T.CLOSED_DATE IS NOT NULL AND ".$sAliasPrefix."T.DEADLINE IS NOT NULL AND ".$sAliasPrefix."T.DEADLINE < CLOSED_DATE";
					}
					break;

				case "SAME_GROUP_PARENT":
					if ($val == "Y" && !array_key_exists("ONLY_ROOT_TASKS", $arFilter))
					{
						$arSqlSearch[] = "EXISTS(
							SELECT
								'x'
							FROM
								b_tasks ".$sAliasPrefix."PT
							WHERE
								".$sAliasPrefix."T.PARENT_ID = ".$sAliasPrefix."PT.ID
							AND
								(" . $sAliasPrefix . "PT.GROUP_ID = " . $sAliasPrefix . "T.GROUP_ID 
								OR (" . $sAliasPrefix . "PT.GROUP_ID IS NULL AND " . $sAliasPrefix . "T.GROUP_ID IS NULL)
								OR (" . $sAliasPrefix . "PT.GROUP_ID = 0 AND " . $sAliasPrefix . "T.GROUP_ID IS NULL)
								OR (" . $sAliasPrefix . "PT.GROUP_ID IS NULL AND " . $sAliasPrefix . "T.GROUP_ID = 0)
								)
							" . ($bGetZombie ? "" : " AND " . $sAliasPrefix . "PT.ZOMBIE = 'N' ") . "
						)";
					}
					break;

				case "DEPARTMENT_ID":
					if ($strSql = CTasks::GetDeparmentSql($val, $sAliasPrefix))
					{
						$arSqlSearch[] = "EXISTS(".$strSql.")";
					}
					break;

				case 'CHECK_PERMISSIONS':
				break;

				case 'FAVORITE':
					$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix."FVT.TASK_ID", $val, "left_existence", $bFullJoin, $cOperationType, false);
				break;

				case 'SORTING':
					$arSqlSearch[] = CTasks::FilterCreate($sAliasPrefix."SRT.TASK_ID", $val, "left_existence", $bFullJoin, $cOperationType, false);
				break;

				default:
					if (
						(strlen($key) >= 3)
						&& (substr($key, 0, 3) === 'UF_')
					)
					{
						;	// It's OK, this fields will be processed by UserFieldManager
					}
					else
					{
						$extraData = '';

						if (isset($_POST['action']) && ($_POST['action'] === 'group_action'))
						{
							$extraData = '; Extra data: <data0>' 
								. serialize(array($_POST['arFilter'], $_POST['action'], $arFilter)) 
								. '</data0>';
						}
						else
						{
							$extraData = '; Extra data: <data1>' 
								. serialize($arFilter) 
								. '</data1>';
						}

						//CTaskAssert::logError('[0x6024749e] unexpected field in filter: ' . $key . $extraData);

						//throw new TasksException('Bad filter argument: '.$key, TasksException::TE_WRONG_ARGUMENTS);
					}
				break;
			}
		}

		$sql = implode(
			$logicStr,
			array_filter(
				$arSqlSearch
			)
		);

		if ($sql == '')
			$sql = '1=1';

		return ('(' . $sql . ')');
	}


	private static function getSqlForTimestamps($key, $val, $userID, $sAliasPrefix, $bGetZombie)
	{
		static $ts = null;		// some fixed timestamp of "now" (for consistency)

		if ($ts === null)
			$ts = CTasksPerHitOption::getHitTimestamp();

		$bTzWasDisabled = ! CTimeZone::enabled();

		if ($bTzWasDisabled)
			CTimeZone::enable();

		// Adjust UNIX TS to "Bitrix timestamp"
		$tzOffset = CTimeZone::getOffset();
		$ts  += $tzOffset;

		if ($bTzWasDisabled)
			CTimeZone::disable();		

		$arSqlSearch = array();

		$arFilter = array(
			'::LOGIC' => 'AND'
		);

		$key = ltrim($key);

		$res = CTasks::MkOperationFilter($key);
		$fieldName      = substr($res["FIELD"], 5, -3);	// Cutoff prefix "META:" and suffix "_TS"
		$cOperationType = $res["OPERATION"];

		$operationSymbol = substr($key, 0, -1 * strlen($res["FIELD"]));

		if (substr($cOperationType, 0, 1) !== '#')
		{
			switch ($operationSymbol)
			{
				case '<':
					$operationCode = CTaskFilterCtrl::OP_STRICTLY_LESS;
				break;

				case '>':
					$operationCode = CTaskFilterCtrl::OP_STRICTLY_GREATER;
				break;

				case '<=':
					$operationCode = CTaskFilterCtrl::OP_LESS_OR_EQUAL;
				break;

				case '>=':
					$operationCode = CTaskFilterCtrl::OP_GREATER_OR_EQUAL;
				break;

				case '!=':
					$operationCode = CTaskFilterCtrl::OP_NOT_EQUAL;
				break;

				case '':
				case '=':
					$operationCode = CTaskFilterCtrl::OP_EQUAL;
				break;

				default:
					CTaskAssert::log(
						'Unknown operation code: ' . $operationSymbol . '; $key = ' . $key
							. '; it will be silently ignored, incorrect results expected',
						CTaskAssert::ELL_ERROR	// errors, incorrect results expected
					);
					return ($arSqlSearch);
				break;
			}
		}
		else
			$operationCode = (int) substr($cOperationType, 1);

		$date1 = $date2 = $cOperationType1 = $cOperationType2 = null;

		// sometimes we can have DAYS in $val, not TIMESTAMP
		if($operationCode != CTaskFilterCtrl::OP_DATE_NEXT_DAYS && $operationCode != CTaskFilterCtrl::OP_DATE_LAST_DAYS)
		{
			$val += $tzOffset;
		}

		// Convert cOperationType to format accepted by self::FilterCreate
		switch ($operationCode)
		{
			case CTaskFilterCtrl::OP_EQUAL:
			case CTaskFilterCtrl::OP_DATE_TODAY:
			case CTaskFilterCtrl::OP_DATE_YESTERDAY:
			case CTaskFilterCtrl::OP_DATE_TOMORROW:
			case CTaskFilterCtrl::OP_DATE_CUR_WEEK:
			case CTaskFilterCtrl::OP_DATE_PREV_WEEK:
			case CTaskFilterCtrl::OP_DATE_NEXT_WEEK:
			case CTaskFilterCtrl::OP_DATE_CUR_MONTH:
			case CTaskFilterCtrl::OP_DATE_PREV_MONTH:
			case CTaskFilterCtrl::OP_DATE_NEXT_MONTH:
			case CTaskFilterCtrl::OP_DATE_NEXT_DAYS:
			case CTaskFilterCtrl::OP_DATE_LAST_DAYS:
				$cOperationType1 = '>=';
				$cOperationType2 = '<=';
			break;

			case CTaskFilterCtrl::OP_LESS_OR_EQUAL:
				$cOperationType1 = '<=';
			break;

			case CTaskFilterCtrl::OP_GREATER_OR_EQUAL:
				$cOperationType1 = '>=';
			break;

			case CTaskFilterCtrl::OP_NOT_EQUAL:
				$cOperationType1 = '<';
				$cOperationType2 = '>';
			break;

			case CTaskFilterCtrl::OP_STRICTLY_LESS:
				$cOperationType1 = '<';
			break;

			case CTaskFilterCtrl::OP_STRICTLY_GREATER:
				$cOperationType1 = '>';
			break;

			default:
				CTaskAssert::log(
					'Unknown operation code: ' . $operationCode . '; $key = ' . $key
						. '; it will be silently ignored, incorrect results expected',
					CTaskAssert::ELL_ERROR	// errors, incorrect results expected
				);
				return ($arSqlSearch);
			break;
		}

		// Convert/generate dates
		$ts1 = $ts2 = null;
		switch ($operationCode)
		{
			case CTaskFilterCtrl::OP_DATE_TODAY:
				$ts1 = $ts2 = $ts;
			break;

			case CTaskFilterCtrl::OP_DATE_YESTERDAY:
				$ts1 = $ts2 = $ts - 86400;
			break;

			case CTaskFilterCtrl::OP_DATE_TOMORROW:
				$ts1 = $ts2 = $ts + 86400;
			break;

			case CTaskFilterCtrl::OP_DATE_CUR_WEEK:
				$weekDay = date('N');	// numeric representation of the day of the week (1 to 7)
				$ts1 = $ts - ($weekDay - 1) * 86400;
				$ts2 = $ts + (7 - $weekDay) * 86400;
			break;

			case CTaskFilterCtrl::OP_DATE_PREV_WEEK:
				$weekDay = date('N');	// numeric representation of the day of the week (1 to 7)
				$ts1 = $ts - ($weekDay - 1 + 7) * 86400;
				$ts2 = $ts - $weekDay * 86400;
			break;

			case CTaskFilterCtrl::OP_DATE_NEXT_WEEK:
				$weekDay = date('N');	// numeric representation of the day of the week (1 to 7)
				$ts1 = $ts + (7 - $weekDay + 1) * 86400;
				$ts2 = $ts + (7 - $weekDay + 7) * 86400;
			break;

			case CTaskFilterCtrl::OP_DATE_CUR_MONTH:
				$ts1 = mktime(0, 0, 0, date('n', $ts), 1, date('Y', $ts));
				$ts2 = mktime(23, 59, 59, date('n', $ts) + 1, 0, date('Y', $ts));
			break;

			case CTaskFilterCtrl::OP_DATE_PREV_MONTH:
				$ts1 = mktime(0, 0, 0, date('n', $ts) - 1, 1, date('Y', $ts));
				$ts2 = mktime(23, 59, 59, date('n', $ts), 0, date('Y', $ts));
			break;

			case CTaskFilterCtrl::OP_DATE_NEXT_MONTH:
				$ts1 = mktime(0, 0, 0, date('n', $ts) + 1, 1, date('Y', $ts));
				$ts2 = mktime(23, 59, 59, date('n', $ts) + 2, 0, date('Y', $ts));
			break;

			case CTaskFilterCtrl::OP_DATE_LAST_DAYS:
				$ts1 = $ts - ((int) $val) * 86400; // val in days
				$ts2 = $ts;
			break;

			case CTaskFilterCtrl::OP_DATE_NEXT_DAYS:
				$ts1 = $ts;
				$ts2 = $ts + ((int) $val) * 86400; // val in days
			break;

			case CTaskFilterCtrl::OP_GREATER_OR_EQUAL:
			case CTaskFilterCtrl::OP_LESS_OR_EQUAL:
			case CTaskFilterCtrl::OP_STRICTLY_LESS:
			case CTaskFilterCtrl::OP_STRICTLY_GREATER:
				$ts1 = $val;
			break;

			case CTaskFilterCtrl::OP_EQUAL:
				$ts1 = mktime(0, 0, 0, date('n', $val), date('j', $val), date('Y', $val));
				$ts2 = mktime(23, 59, 59, date('n', $val), date('j', $val), date('Y', $val));
			break;

			case CTaskFilterCtrl::OP_NOT_EQUAL:
				$ts1 = mktime(0, 0, 0, date('n', $val), date('j', $val), date('Y', $val));
				$ts2 = mktime(23, 59, 59, date('n', $val), date('j', $val), date('Y', $val));
			break;

			default:
				CTaskAssert::log(
					'Unknown operation code: ' . $operationCode . '; $key = ' . $key
						. '; it will be silently ignored, incorrect results expected',
					CTaskAssert::ELL_ERROR	// errors, incorrect results expected
				);
				return ($arSqlSearch);
			break;
		}

		if ($ts1)
			$date1 = ConvertTimeStamp(mktime(0, 0, 0, date('n', $ts1), date('j', $ts1), date('Y', $ts1)), 'FULL');

		if ($ts2)
			$date2 = ConvertTimeStamp(mktime(23, 59, 59, date('n', $ts2), date('j', $ts2), date('Y', $ts2)), 'FULL');

		if (($cOperationType1 !== null) && ($date1 !== null))
		{
			$arrayKey = $cOperationType1 . $fieldName;
			while(isset($arFilter[$arrayKey]))
				$arrayKey = ' ' . $arrayKey;

			$arFilter[$arrayKey] = $date1;
		}

		if (($cOperationType2 !== null) && ($date2 !== null))
		{
			$arrayKey = $cOperationType2 . $fieldName;
			while(isset($arFilter[$arrayKey]))
				$arrayKey = ' ' . $arrayKey;

			$arFilter[$arrayKey] = $date2;
		}

		$arSqlSearch[] = self::GetSqlByFilter($arFilter, $userID, $sAliasPrefix, $bGetZombie);

		return ($arSqlSearch);
	}

	private static function GetFilteredKeys($arFilter)
	{
		$result = array();

		if(is_array($arFilter))
		{
			foreach($arFilter as $key => $v)
			{
				// Skip meta-key
				if ($key === '::LOGIC')
					continue;

				// Skip markers
				if ($key === '::MARKERS')
					continue;

				// Subfilter?
				if (static::isSubFilterKey($key))
				{
					$result = array_merge($result, self::GetFilteredKeys($v));
					continue;
				}

				$res = CTasks::MkOperationFilter($key);

				if((string) $res['FIELD'] != '')
				{
					$result[] = $res['FIELD'];
				}
			}
		}

		return array_unique($result);
	}

	public static function isSubFilterKey($key)
	{
		return is_numeric($key) || (substr((string) $key, 0, 12) === '::SUBFILTER-');
	}

	public static function GetFilter($arFilter, $sAliasPrefix = "", $arParams = false)
	{
		if (!is_array($arFilter))
			$arFilter = array();

		$arSqlSearch = array();

		if (is_array($arParams) && array_key_exists('USER_ID', $arParams) && ($arParams['USER_ID'] > 0))
			$userID = (int) $arParams['USER_ID'];
		else
		{
			$userID = User::getId();
		}

		$bGetZombie = false;
		if (isset($arParams['bGetZombie']))
			$bGetZombie = (bool) $arParams['bGetZombie'];

		// if TRUE will be generated constraint for members
		$bMembersTableJoined = false;
		if (isset($arParams['bMembersTableJoined']))
			$bMembersTableJoined = (bool) $arParams['bMembersTableJoined'];

		$sql = self::GetSqlByFilter($arFilter, $userID, $sAliasPrefix, $bGetZombie, $bMembersTableJoined);
		if (strlen($sql))
			$arSqlSearch[] = $sql;

		// enable legacy access if no option passed (by default)
		// disable legacy access when ENABLE_LEGACY_ACCESS === true
		// we can not switch legacy access off by default, because getFilter() can be used separately
		$enableLegacyAccess = !is_array($arParams) || $arParams['ENABLE_LEGACY_ACCESS'] !== false;
		if($enableLegacyAccess && static::needAccessRestriction($arFilter, $arParams))
		{
			list($arSubSqlSearch, $fields) = static::getPermissionFilterConditions($arParams, array('ALIAS' => $sAliasPrefix));

			if(!empty($arSubSqlSearch))
			{
				$arSqlSearch[] = " \n/*access LEGACY BEGIN*/\n (".implode(" OR ", $arSubSqlSearch).") \n/*access LEGACY END*/\n";
			}
		}

		return $arSqlSearch;
	}

	private static function placeFieldSql($field, $behaviour, &$fields)
	{
		if($behaviour['USE_PLACEHOLDERS'])
		{
			$fields[] = $field;
			return '%s';
		}

		return $behaviour['ALIAS'].'T.'.$field;
	}

	/**
	 * @param $arParams
	 * @param array $behaviour
	 * @return array
	 * @deprecated
	 */
	public static function getPermissionFilterConditions($arParams, $behaviour = array('ALIAS' => '', 'USE_PLACEHOLDERS' => false))
	{
		if(!is_array($behaviour))
		{
			$behaviour = array();
		}
		if(!isset($behaviour['ALIAS']))
		{
			$behaviour['ALIAS'] = '';
		}
		if(!isset($behaviour['USE_PLACEHOLDERS']))
		{
			$behaviour['USE_PLACEHOLDERS'] = false;
		}

		$arSubSqlSearch = array();
		$fields = array();

		$a = $behaviour['ALIAS'];
		$b = $behaviour;
		$f =& $fields;

		if (is_array($arParams) && array_key_exists('USER_ID', $arParams) && ($arParams['USER_ID'] > 0))
			$userID = (int) $arParams['USER_ID'];
		else
			$userID = User::getId();

		if (!User::isSuper($userID))
		{
			$arSubSqlSearch = array(
				static::placeFieldSql('CREATED_BY', 	$b, $f)." = '".$userID."'",
				static::placeFieldSql('RESPONSIBLE_ID', $b, $f)." = '".$userID."'",
				"EXISTS(
					SELECT 'x'
					FROM b_tasks_member ".$a."TM
					WHERE
						".$a."TM.TASK_ID = ".static::placeFieldSql('ID', $b, $f)." AND ".$a."TM.USER_ID = '".$userID."'
				)"
			);

			// subordinate check
			$arParams['FIELDS'] =& $fields;
			if ($strSql = CTasks::GetSubordinateSql($a, $arParams, $behaviour))
			{
				$arSubSqlSearch[] = "EXISTS(".$strSql.")";
			}

			// group permission check
			if ($arAllowedGroups = CTasks::GetAllowedGroups($arParams))
				$arSubSqlSearch[] = "(".static::placeFieldSql('GROUP_ID', $b, $f)." IN (".implode(",", $arAllowedGroups)."))";
		}

		return array($arSubSqlSearch, $fields);
	}

	public static function MkOperationFilter($key)
	{
		static $arOperationsMap = null;	// will be loaded on demand

		$key = ltrim($key);

		$firstSymbol = substr($key, 0, 1);
		$twoSymbols  = substr($key, 0, 2);

		if ($firstSymbol == "=") //Identical
		{
			$key = substr($key, 1);
			$cOperationType = "I";
		}
		elseif ($twoSymbols == "!=") //not Identical
		{
			$key = substr($key, 2);
			$cOperationType = "NI";
		}
		elseif ($firstSymbol == "%") //substring
		{
			$key = substr($key, 1);
			$cOperationType = "S";
		}
		elseif ($twoSymbols == "!%") //not substring
		{
			$key = substr($key, 2);
			$cOperationType = "NS";
		}
		elseif ($firstSymbol == "?") //logical
		{
			$key = substr($key, 1);
			$cOperationType = "?";
		}
		elseif ($twoSymbols == "><") //between
		{
			$key = substr($key, 2);
			$cOperationType = "B";
		}
		elseif (substr($key, 0, 3) == "!><") //not between
		{
			$key = substr($key, 3);
			$cOperationType = "NB";
		}
		elseif ($twoSymbols == ">=") //greater or equal
		{
			$key = substr($key, 2);
			$cOperationType = "GE";
		}
		elseif ($firstSymbol == ">")  //greater
		{
			$key = substr($key, 1);
			$cOperationType = "G";
		}
		elseif ($twoSymbols == "<=")  //less or equal
		{
			$key = substr($key, 2);
			$cOperationType = "LE";
		}
		elseif ($firstSymbol == "<")  //less
		{
			$key = substr($key, 1);
			$cOperationType = "L";
		}
		elseif ($firstSymbol == "!") // not field LIKE val
		{
			$key = substr($key, 1);
			$cOperationType = "N";
		}
		elseif ($firstSymbol === '#')
		{
			// Preload and cache in static variable
			if ($arOperationsMap === null)
			{
				$arManifest = CTaskFilterCtrl::getManifest();
				$arOperationsMap = $arManifest['Operations map'];
			}

			// Resolve operation code and cutoff operation prefix from item name
			$operation = null;
			foreach ($arOperationsMap as $operationCode => $operationPrefix)
			{
				$pattern = '/^' . preg_quote($operationPrefix) . '[A-Za-z]/';
				if (preg_match($pattern, $key))
				{
					$operation = $operationCode;
					$key  = substr($key, strlen($operationPrefix));
					break;
				}
			}

			CTaskAssert::assert($operation !== null);

			$cOperationType = "#" . $operation;
		}
		else
			$cOperationType = "E"; // field LIKE val

		return array("FIELD" => $key, "OPERATION" => $cOperationType);
	}


	public static function FilterCreate($fname, $vals, $type, &$bFullJoin, $cOperationType=false, $bSkipEmpty = true)
	{
		global $DB;
		if (!is_array($vals))
		{
			$vals = array($vals);
		}
		else
		{
			$vals = array_unique(array_values($vals));
		}

		if (count($vals) < 1)
			return "";

		if (is_bool($cOperationType))
		{
			if ($cOperationType === true)
				$cOperationType = "N";
			else
				$cOperationType = "E";
		}

		if ($cOperationType == "G")
			$strOperation = ">";
		elseif ($cOperationType == "GE")
			$strOperation = ">=";
		elseif ($cOperationType == "LE")
			$strOperation = "<=";
		elseif ($cOperationType == "L")
			$strOperation = "<";
		elseif ($cOperationType === "NI")
			$strOperation = "!=";
		else
			$strOperation = "=";

		$bFullJoin = false;
		$bWasLeftJoin = false;

		// special case for array of number
		if($type === 'number' && is_array($vals) && count($vals) > 1 && count($vals) < 80)
		{
			$vals = implode(', ', array_unique(array_map('intval', $vals)));

			$res = $fname.' '.($cOperationType == 'N' ? 'not' : '').' in ('.$vals.')';

			// INNER JOIN in this case
			if ($cOperationType != "N")
				$bFullJoin = true;

			return $res;
		}

		$res = array();
		for ($i = 0, $valsCnt = count($vals); $i < $valsCnt; $i++)
		{
			$val = $vals[$i];

			if (($type === 'number') && !$val)
				$val = 0;

			if (!$bSkipEmpty || strlen($val) > 0 || (is_bool($val) && $val === false))
			{
				switch ($type)
				{
					case "string_equal":
						if (strlen($val) <= 0)
							$res[] =
									($cOperationType == "N" ? "NOT" : "").
									"(".
									$fname." IS NULL OR ".$DB->Length($fname).
									"<=0)";
						else
							$res[] =
									"(".
									($cOperationType == "N" ? " ".$fname." IS NULL OR NOT (" : "").
									$fname.$strOperation."'".$DB->ForSql($val)."'".
									($cOperationType == "N" ? ")" : "").
									")";
						break;

					case "string":
						if ($cOperationType == "?")
						{
							if (strlen($val) > 0)
								$res[] = GetFilterQuery($fname, $val, "Y", array(), "N");
						}
						elseif ($cOperationType == "S")
						{
							$res[] = "(UPPER(".$fname.") LIKE UPPER('%".$DB->ForSqlLike($val)."%'))";
						}
						elseif ($cOperationType == "NS")
						{
							$res[] = "(UPPER(".$fname.") NOT LIKE UPPER('%".$DB->ForSqlLike($val)."%'))";
						}
						elseif (strlen($val) <= 0)
						{
							$res[] = ($cOperationType == "N" ? "NOT" : "")."(".$fname." IS NULL OR ".$DB->Length($fname)."<=0)";
						}
						else
						{
							if ($strOperation == "=")
								$res[] =
										"(".
										($cOperationType == "N" ? " ".$fname." IS NULL OR NOT (" : "").
										(strtoupper($DB->type) == "ORACLE"
											?
											$fname." LIKE "."'".$DB->ForSqlLike($val)."'"." ESCAPE '\\'" 
											: 
											$fname." ".($strOperation == "=" ? "LIKE" : $strOperation)." '".$DB->ForSqlLike($val)."'").
										($cOperationType == "N" ? ")" : "").
										")";
							else
								$res[] =
										"(".
										($cOperationType == "N" ? " ".$fname." IS NULL OR NOT (" : "").
										(strtoupper($DB->type) == "ORACLE" ? $fname." ".$strOperation." "."'".$DB->ForSql($val)."'"." " : $fname." ".$strOperation." '".$DB->ForSql($val)."'").
										($cOperationType == "N" ? ")" : "").
										")";
						}
						break;

					case "date":
						if (strlen($val) <= 0)
							$res[] = ($cOperationType == "N" ? "NOT" : "")."(".$fname." IS NULL)";
						else
							$res[] =
									"(".
									($cOperationType == "N" ? " ".$fname." IS NULL OR NOT (" : "").
									$fname." ".$strOperation." ".$val."".
									($cOperationType == "N" ? ")" : "").
									")";
						break;

					case "number":

						if (($vals[$i] === false) || (strlen($val) <= 0))
							$res[] = ($cOperationType == "N" ? "NOT" : "")."(".$fname." IS NULL)";
						else
							$res[] =
									"(".
									($cOperationType == "N" ? " ".$fname." IS NULL OR NOT (" : "").
									$fname." ".$strOperation." '".DoubleVal($val).
									($cOperationType == "N" ? "')" : "'").
									")";
						break;

					case "number_wo_nulls":
						$res[] =
								"(".
								($cOperationType == "N" ? "NOT (" : "").
								$fname." ".$strOperation." ".DoubleVal($val).
								($cOperationType == "N" ? ")" : "").
								")";
						break;

					case "null_or_zero":
						if ($cOperationType == "N")
							$res[] = "((" . $fname . " IS NOT NULL) AND (" . $fname . " != 0))";
						else
							$res[] = "((" . $fname . " IS NULL) OR (" . $fname . " = 0))";

						break;

					case "left_existence":

						if($strOperation != '=')
						{
							CTaskAssert::logError('Operation type not supported for '.$fname.': ' . $strOperation);
						}
						elseif($val != 'Y' && $val != 'N')
						{
							CTaskAssert::logError('Filter value not supported for '.$fname.': ' . $val);
						}
						else
						{
							$otNot = $cOperationType == "N";

							if(($val == 'Y' && !$otNot) || ($val == 'N' && $otNot))
								$res[] = "(" . $fname . " IS NOT NULL)";
							else
								$res[] = "(" . $fname . " IS NULL)";
						}

						break;

					case 'reference':

						$val = trim($val);

						if(preg_match('#^[a-z0-9_]+(\.{1}[a-z0-9_]+)*$#i', $val))
						{
							if ($cOperationType === 'E')
								$res[] = '(' . $fname . ' = ' . $DB->ForSql($val) . ')';
							elseif ($cOperationType === 'N')
								$res[] = '(' . $fname . ' != ' . $DB->ForSql($val) . ')';
							else
								CTaskAssert::logError('[0xcf017223] Operation type not supported: ' . $cOperationType);
						}
						else
						{
							CTaskAssert::logError("Bad reference: ".$fname." => '".$val."'");
						}

						break;
				}

				// INNER JOIN in this case
				if (strlen($val) > 0 && $cOperationType != "N")
					$bFullJoin = true;
				else
					$bWasLeftJoin = true;
			}
		}

		$strResult = "";
		for ($i = 0, $resCnt = count($res); $i < $resCnt; $i++)
		{
			if ($i > 0)
				$strResult .= ( $cOperationType == "N" ? " AND " : " OR ");
			$strResult .= $res[$i];
		}

		if (count($res) > 1)
			$strResult = "(".$strResult.")";

		if ($bFullJoin && $bWasLeftJoin && $cOperationType != "N")
			$bFullJoin = false;

		return $strResult;
	}


	/**
	 * This method is deprecated. Use CTaskItem class instead.
	 * @deprecated
	 */
	public static function GetByID($ID, $bCheckPermissions = true, $arParams = array())
	{
		$bReturnAsArray = false;
		$bSkipExtraData = false;
		$arGetListParams = array();

		if (isset($arParams['returnAsArray']))
			$bReturnAsArray = ($arParams['returnAsArray'] === true);

		if (isset($arParams['bSkipExtraData']))
			$bSkipExtraData = ($arParams['bSkipExtraData'] === true);

		if (isset($arParams['USER_ID']))
			$arGetListParams['USER_ID'] = $arParams['USER_ID'];

		$arFilter = array("ID" => (int) $ID);

		if (!$bCheckPermissions)
			$arFilter["CHECK_PERMISSIONS"] = "N";

		$res = CTasks::GetList(array(), $arFilter, array("*", "UF_*"), $arGetListParams);
		if ($res && ($task = $res->Fetch()))
		{
			$task["ACCOMPLICES"] = $task["AUDITORS"] = array();
			$rsMembers = CTaskMembers::GetList(array(), array("TASK_ID" => $ID));
			while ($arMember = $rsMembers->Fetch())
			{
				if ($arMember["TYPE"] == "A")
				{
					$task["ACCOMPLICES"][] = $arMember["USER_ID"];
				}
				elseif ($arMember["TYPE"] == "U")
				{
					$task["AUDITORS"][] = $arMember["USER_ID"];
				}
			}

			if ( ! $bSkipExtraData )
			{
				$arTagsFilter = array("TASK_ID" => $ID);
				$arTagsOrder = array("NAME" => "ASC");
				$rsTags = CTaskTags::GetList($arTagsOrder, $arTagsFilter);
				$task["TAGS"] = array();
				while ($arTag = $rsTags->Fetch())
				{
					$task["TAGS"][] = $arTag["NAME"];
				}

				$rsFiles = CTaskFiles::GetList(array(), array("TASK_ID" => $ID));
				$task["FILES"] = array();
				while ($arFile = $rsFiles->Fetch())
				{
					$task["FILES"][] = $arFile["FILE_ID"];
				}

				$rsDependsOn = CTaskDependence::GetList(array(), array("TASK_ID" => $ID));
				$task["DEPENDS_ON"] = array();
				while ($arDependsOn = $rsDependsOn->Fetch())
				{
					$task["DEPENDS_ON"][] = $arDependsOn["DEPENDS_ON_ID"];
				}
			}

			if ($bReturnAsArray)
				return ($task);
			else
			{
				$rsTask = new CDBResult;
				$rsTask->InitFromarray(array($task));
				return $rsTask;
			}
		}
		else
		{
			if ($bReturnAsArray)
				return (false);
			else
				return $res;
		}
	}


	/**
	 * @param null $userID
	 * @return array
	 * @deprecated
	 */
	public static function GetSubordinateDeps($userID = null)
	{
		return \Bitrix\Tasks\Integration\Intranet\Department::getSubordinateIds($userID, true);
	}

	/**
	 * @param array $arParams
	 * @return mixed
	 * @deprecated
	 * @see \Bitrix\Tasks\Integration\SocialNetwork\Group::getIdsByAllowedAction
	 */
	public static function GetAllowedGroups($arParams = array())
	{
		global $DB;
		static $ALLOWED_GROUPS = array();

		$userId = null;

		if (is_array($arParams) && isset($arParams['USER_ID']))
			$userId = $arParams['USER_ID'];
		else
		{
			$userId = User::getId();
		}

		if ( ! ($userId >= 1) )
			$userId = 0;

		$bGetZombie = false;
		if (isset($arParams['bGetZombie']))
			$bGetZombie = (bool) $arParams['bGetZombie'];

		if (!isset($ALLOWED_GROUPS[$userId]) && CModule::IncludeModule("socialnetwork"))
		{
			// bottleneck
			$strSql = "SELECT DISTINCT(T.GROUP_ID) FROM b_tasks T WHERE T.GROUP_ID IS NOT NULL";
			if ( ! $bGetZombie )
				$strSql .= " AND T.ZOMBIE = 'N'";

			$rsGroups = $DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);
			$ALLOWED_GROUPS[$userId] = $arGroupsWithTasks = array();
			while ($arGroup = $rsGroups->Fetch())
			{
				$arGroupsWithTasks[] = $arGroup["GROUP_ID"];
			}
			if (is_array($arGroupsWithTasks) && sizeof($arGroupsWithTasks))
			{
				if ($userId === 0)
					$featurePerms = CSocNetFeaturesPerms::CurrentUserCanPerformOperation(SONET_ENTITY_GROUP, $arGroupsWithTasks, "tasks", "view_all");
				else
					$featurePerms = CSocNetFeaturesPerms::CanPerformOperation($userId, SONET_ENTITY_GROUP, $arGroupsWithTasks, "tasks", "view_all");

				if (is_array($featurePerms))
				{
					$ALLOWED_GROUPS[$userId] = array_keys(array_filter($featurePerms));
				}
			}
		}

		return $ALLOWED_GROUPS[$userId];
	}


	public static function GetDepartmentManagers($arDepartments, $skipUserId=false, $arSelectFields = array('ID'))
	{
		global $CACHE_MANAGER;

		if ( ( ! is_array($arDepartments) )
			|| empty($arDepartments) 
			|| ( ! is_array($arSelectFields) ) 
		)
		{
			return false;
		}

		// We need ID in any case
		if ( ! in_array('ID', $arSelectFields) )
			$arSelectFields[] = 'ID';

		$arManagers = array();
		$obCache = new CPHPCache();
		$lifeTime = CTasksTools::CACHE_TTL_UNLIM;
		$cacheDir = "/tasks/subordinatedeps";
		$cacheFPrint = sha1(
			serialize($arDepartments) 
			. '|' . serialize($arSelectFields)
			);
		if($obCache->InitCache($lifeTime, $cacheFPrint, $cacheDir))
		{
			$arManagers = $obCache->GetVars();
		}
		elseif ($obCache->StartDataCache())
		{
			$IBlockID = COption::GetOptionInt('intranet', 'iblock_structure', 0);

			$CACHE_MANAGER->StartTagCache($cacheDir);
			$CACHE_MANAGER->RegisterTag("iblock_id_".$IBlockID);

			$arUserIDs = self::GetDepartmentManagersIDs($arDepartments, $IBlockID);

			if (count($arUserIDs) > 0)
			{
				$arFilter = array(
					'ID' => implode('|', $arUserIDs)
					);

				// Prevent using users, that doesn't activate it's account
				// http://jabber.bx/view.php?id=29118
				if (IsModuleInstalled('bitrix24'))
					$arFilter['!LAST_LOGIN'] = false;

				$dbUser = CUser::GetList(
					$by = 'ID', 
					$order = 'ASC', 
					$arFilter,
					array('FIELDS' => $arSelectFields)	// selects only $arSelectFields fields
					);
				while ($arUser = $dbUser->GetNext())
					$arManagers[(int) $arUser["ID"]] = $arUser;
			}

			$CACHE_MANAGER->EndTagCache();
			$obCache->EndDataCache($arManagers);
		}

		// remove user to be skipped
		if ( ($skipUserId !== false) 
			&& (isset($arManagers[(int) $skipUserId]))
		)
		{
			unset ($arManagers[(int) $skipUserId]);
		}

		return $arManagers;
	}


	protected static function GetDepartmentManagersIDs($arDepartments, $IBlockID)
	{
		if(!CModule::IncludeModule('iblock'))
		{
			return array();
		}

		$dbSections = CIBlockSection::GetList(
			array('SORT' => 'ASC'), 
			array(
				'ID'                => $arDepartments, 
				'IBLOCK_ID'         => $IBlockID, 
				'CHECK_PERMISSIONS' => 'N'), 
			false, 								// don't count
			array(
				'ID', 
				'UF_HEAD', 
				'IBLOCK_SECTION_ID')
			);

		$arUserIDs = array();
		while ($arSection = $dbSections->Fetch())
		{
			if ($arSection['UF_HEAD'] > 0)
				$arUserIDs[] = $arSection['UF_HEAD'];

			if ($arSection['IBLOCK_SECTION_ID'] > 0)
			{
				$arUserIDs = array_merge(
					$arUserIDs,
					self::GetDepartmentManagersIDs(array($arSection['IBLOCK_SECTION_ID']), $IBlockID)
					);
			}
		}

		return $arUserIDs;
	}


	/**
	 * @param $employeeID1
	 * @param $employeeID2
	 * @return bool true if $employeeID2 is manager of $employeeID1
	 */
	public static function IsSubordinate($employeeID1, $employeeID2)
	{
		if ($employeeID1 == $employeeID2)
		{
			return false;
		}

		$dbRes = CUser::GetList(
			$by = 'ID', 
			$order = 'ASC', 
			array('ID' => $employeeID1), 
			array('SELECT' => array('UF_DEPARTMENT'))
		);

		if (
			($arRes = $dbRes->Fetch()) 
			&& is_array($arRes['UF_DEPARTMENT']) 
			&& (count($arRes['UF_DEPARTMENT']) > 0)
		)
		{
			$arManagers = array_keys(CTasks::GetDepartmentManagers($arRes['UF_DEPARTMENT'], $employeeID1));

			if (in_array($employeeID2, $arManagers))
				return true;
		}

		return false;
	}

	public static function getSelectSqlByFilter(array $filter = array(), $alias = '', array $filterParams = array())
	{
		$userID = intval($filterParams['USER_ID']);

		$obUserFieldsSql = new CUserTypeSQL();
		$obUserFieldsSql->SetEntity("TASKS_TASK", $alias."T.ID");
		$obUserFieldsSql->SetFilter($filter);

		if (isset($filter['::LOGIC']))
			CTaskAssert::assert($filter['::LOGIC'] === 'AND');

		$arSqlSearch = CTasks::GetFilter($filter, $alias, $filterParams);

		//if ( ! $bGetZombie )
		//	$arSqlSearch[] = " T.ZOMBIE = 'N' ";

		$r = $obUserFieldsSql->GetFilter();
		if (strlen($r) > 0)
		{
			$arSqlSearch[] = "(".$r.")";
		}

		$keysFiltered = CTasks::GetFilteredKeys($filter);

		$bNeedJoinFavoritesTable = in_array('FAVORITE', $keysFiltered, true);

		// todo: try to join b_tasks_viewed ONLY when it is required!
		return "
			SELECT
				".$alias."T.ID
			FROM
				b_tasks ".$alias."T
			INNER JOIN b_user ".$alias."CU ON ".$alias."CU.ID = ".$alias."T.CREATED_BY 
			INNER JOIN b_user ".$alias."RU ON ".$alias."RU.ID = ".$alias."T.RESPONSIBLE_ID 
			LEFT JOIN b_tasks_viewed TV ON TV.TASK_ID = T.ID 
				AND TV.USER_ID = " . $userID . "
			"

			// related joins
			. ($bNeedJoinFavoritesTable ? "
				LEFT JOIN ".FavoriteTable::getTableName()." ".$alias."FVT ON ".$alias."FVT.TASK_ID = ".$alias."T.ID and ".$alias."FVT.USER_ID = '".$userID."'
				" : "")

			// UF_* join
			. $obUserFieldsSql->GetJoin($alias."T.ID") . " "

			// filter
			. (sizeof($arSqlSearch) ? " WHERE ".implode(" AND ", $arSqlSearch) : "") . " ";
	}

	public static function GetList($arOrder=array(), $arFilter=array(), $arSelect = array(), $arParams = array())
	{
		global $DB, $USER_FIELD_MANAGER;

		$bIgnoreErrors = false;
		$nPageTop = false;
		$bGetZombie = false;

		if ( ! is_array($arParams) )
		{
			$nPageTop = $arParams;
			$arParams = false;
		}
		else
		{
			if (isset($arParams['nPageTop']))
				$nPageTop = $arParams['nPageTop'];

			if (isset($arParams['bIgnoreErrors']))
				$bIgnoreErrors = (bool) $arParams['bIgnoreErrors'];

			if (isset($arParams['bGetZombie']))
				$bGetZombie = (bool) $arParams['bGetZombie'];
		}

		$obUserFieldsSql = new CUserTypeSQL();
		$obUserFieldsSql->SetEntity("TASKS_TASK", "T.ID");
		$obUserFieldsSql->SetSelect($arSelect);
		$obUserFieldsSql->SetFilter($arFilter);
		$obUserFieldsSql->SetOrder($arOrder);

		if (is_array($arParams) && array_key_exists('USER_ID', $arParams) && ($arParams['USER_ID'] > 0))
		{
			$userID = (int) $arParams['USER_ID'];
		}
		else
		{
			$userID = User::getId();
		}

		$arFields = array(
			"ID" => "T.ID",
			"TITLE" => "T.TITLE",
			"DESCRIPTION" => "T.DESCRIPTION",
			"DESCRIPTION_IN_BBCODE" => "T.DESCRIPTION_IN_BBCODE",
			"DECLINE_REASON" => "T.DECLINE_REASON",
			"PRIORITY" => "T.PRIORITY",
			// 1) deadline in past, real status is not STATE_SUPPOSEDLY_COMPLETED and not STATE_COMPLETED and (not STATE_DECLINED or responsible is not me (user))
			// 2) viewed by noone(?) and created not by me (user) and (STATE_NEW or STATE_PENDING)
			"STATUS" => "
				CASE
					WHEN
						T.DEADLINE < ".$DB->CurrentTimeFunction()." AND T.STATUS != '4' AND T.STATUS != '5' AND (T.STATUS != '7' OR T.RESPONSIBLE_ID != ".intval($userID).")
					THEN
						'-1'
					WHEN
						TV.USER_ID IS NULL
						AND
						T.CREATED_BY != ".intval($userID)."
						AND
						(T.STATUS = 1 OR T.STATUS = 2)
					THEN
						'-2'
					ELSE
						T.STATUS
				END
			",
			// used in ORDER BY to make completed tasks go after (or before) all other tasks
			"STATUS_COMPLETE" => "
				CASE
					WHEN
						T.STATUS = '5'
					THEN
						'2'
					ELSE
						'1'
					END
			",
			"REAL_STATUS" => "T.STATUS",
			"MULTITASK" => "T.MULTITASK",
			"RESPONSIBLE_ID" => "T.RESPONSIBLE_ID",
			"RESPONSIBLE_NAME" => "RU.NAME",
			"RESPONSIBLE_LAST_NAME" => "RU.LAST_NAME",
			"RESPONSIBLE_SECOND_NAME" => "RU.SECOND_NAME",
			"RESPONSIBLE_LOGIN" => "RU.LOGIN",
			"RESPONSIBLE_WORK_POSITION" => "RU.WORK_POSITION",
			"RESPONSIBLE_PHOTO" => "RU.PERSONAL_PHOTO",
			"DATE_START" => $DB->DateToCharFunction("T.DATE_START", "FULL"),
			"DURATION_FACT" => "(SELECT SUM(TE.MINUTES) FROM b_tasks_elapsed_time TE WHERE TE.TASK_ID = T.ID GROUP BY TE.TASK_ID)",
			"TIME_ESTIMATE" => "T.TIME_ESTIMATE",
			"TIME_SPENT_IN_LOGS" => "(SELECT SUM(TE.SECONDS) FROM b_tasks_elapsed_time TE WHERE TE.TASK_ID = T.ID GROUP BY TE.TASK_ID)",
			"REPLICATE" => "T.REPLICATE",
			"DEADLINE" => $DB->DateToCharFunction("T.DEADLINE", "FULL"),
			"DEADLINE_ORIG" => "T.DEADLINE",
			"START_DATE_PLAN" => $DB->DateToCharFunction("T.START_DATE_PLAN", "FULL"),
			"END_DATE_PLAN" => $DB->DateToCharFunction("T.END_DATE_PLAN", "FULL"),
			"CREATED_BY" => "T.CREATED_BY",
			"CREATED_BY_NAME" => "CU.NAME",
			"CREATED_BY_LAST_NAME" => "CU.LAST_NAME",
			"CREATED_BY_SECOND_NAME" => "CU.SECOND_NAME",
			"CREATED_BY_LOGIN" => "CU.LOGIN",
			"CREATED_BY_WORK_POSITION" => "CU.WORK_POSITION",
			"CREATED_BY_PHOTO" => "CU.PERSONAL_PHOTO",
			"CREATED_DATE" => $DB->DateToCharFunction("T.CREATED_DATE", "FULL"),
			"CHANGED_BY" => "T.CHANGED_BY",
			"CHANGED_DATE" => $DB->DateToCharFunction("T.CHANGED_DATE", "FULL"),
			"STATUS_CHANGED_BY" => "T.CHANGED_BY",
			"STATUS_CHANGED_DATE" => 
				'CASE WHEN T.STATUS_CHANGED_DATE IS NULL THEN ' 
				. $DB->DateToCharFunction("T.CHANGED_DATE", "FULL") 
				. ' ELSE ' 
				. $DB->DateToCharFunction("T.STATUS_CHANGED_DATE", "FULL") 
				. ' END ',
			"CLOSED_BY" => "T.CLOSED_BY",
			"CLOSED_DATE" => $DB->DateToCharFunction("T.CLOSED_DATE", "FULL"),
			'GUID' => 'T.GUID',
			"XML_ID" => "T.XML_ID",
			"MARK" => "T.MARK",
			"ALLOW_CHANGE_DEADLINE" => "T.ALLOW_CHANGE_DEADLINE",
			"ALLOW_TIME_TRACKING" => 'T.ALLOW_TIME_TRACKING',
			"MATCH_WORK_TIME" => "T.MATCH_WORK_TIME",
			"TASK_CONTROL" => "T.TASK_CONTROL",
			"ADD_IN_REPORT" => "T.ADD_IN_REPORT",
			"GROUP_ID" => "CASE WHEN T.GROUP_ID IS NULL THEN 0 ELSE T.GROUP_ID END",
			"FORUM_TOPIC_ID" => "T.FORUM_TOPIC_ID",
			"PARENT_ID" => "T.PARENT_ID",
			"COMMENTS_COUNT" => "FT.POSTS",
			"FORUM_ID" => "FT.FORUM_ID",
			"SITE_ID" => "T.SITE_ID",
			"SUBORDINATE" => ($strSql = CTasks::GetSubordinateSql('', $arParams)) ? "CASE WHEN EXISTS(".$strSql.") THEN 'Y' ELSE 'N' END" : "'N'",
			"EXCHANGE_MODIFIED" => "T.EXCHANGE_MODIFIED",
			"EXCHANGE_ID" => "T.EXCHANGE_ID",
			"OUTLOOK_VERSION" => "T.OUTLOOK_VERSION",
			"VIEWED_DATE" => $DB->DateToCharFunction("TV.VIEWED_DATE", "FULL"),
			"DEADLINE_COUNTED" => "T.DEADLINE_COUNTED",
			"FORKED_BY_TEMPLATE_ID" => "T.FORKED_BY_TEMPLATE_ID",

			"FAVORITE" => "CASE WHEN FVT.TASK_ID IS NULL THEN 'N' ELSE 'Y' END",
			"SORTING" => "SRT.SORT",

			"DURATION_PLAN_SECONDS" => "T.DURATION_PLAN",
			"DURATION_TYPE_ALL" => "T.DURATION_TYPE",

			"DURATION_PLAN" => "
				case 
					when 
						T.DURATION_TYPE = '".self::TIME_UNIT_TYPE_MINUTE."' or T.DURATION_TYPE = '".self::TIME_UNIT_TYPE_HOUR."' 
					then
						ROUND(T.DURATION_PLAN / 3600, 0)
					when
						T.DURATION_TYPE = '".self::TIME_UNIT_TYPE_DAY."' or T.DURATION_TYPE = '' or T.DURATION_TYPE is null
					then
						ROUND(T.DURATION_PLAN / 86400, 0)
					else
						T.DURATION_PLAN
				end
			",
			"DURATION_TYPE" => "
				case 
					when 
						T.DURATION_TYPE = '".self::TIME_UNIT_TYPE_MINUTE."' 
					then
						'".self::TIME_UNIT_TYPE_HOUR."'
					else
						T.DURATION_TYPE
				end
			",
		);

		if ($bGetZombie)
			$arFields['ZOMBIE'] = 'T.ZOMBIE';

		if (count($arSelect) <= 0 || in_array("*", $arSelect))
		{
			$arSelect = array_keys($arFields);
		}
		elseif (!in_array("ID", $arSelect))
		{
			$arSelect[] = "ID";
		}

		// add fields that are NOT selected by default
		//$arFields["FAVORITE"] = "CASE WHEN FVT.TASK_ID IS NULL THEN 'N' ELSE 'Y' END";

		// If DESCRIPTION selected, than BBCODE flag must be selected too
		if (
			in_array('DESCRIPTION', $arSelect)
			&& ( ! in_array('DESCRIPTION_IN_BBCODE', $arSelect) )
		)
		{
			$arSelect[] = 'DESCRIPTION_IN_BBCODE';
		}

		if(!\Bitrix\Tasks\Integration\Forum::isInstalled())
		{
			$arSelect = array_diff($arSelect, array('COMMENTS_COUNT', 'FORUM_ID'));
		}

		if (!is_array($arOrder))
			$arOrder = array();

		$arSqlOrder = array();
		foreach ($arOrder as $by => $order)
		{
			$needle = null;
			$by = strtolower($by);
			$order = strtolower($order);

			if ($by === 'deadline')
			{
				if ( ! in_array($order, array('asc', 'desc', 'asc,nulls', 'desc,nulls'), true) )
					$order = 'asc,nulls';
			}
			else
			{
				if ($order !== 'asc')
					$order = 'desc';
			}

			switch ($by)
			{
				case 'id':
					$arSqlOrder[] = " ID ".$order." ";
				break;

				case 'title':
					$arSqlOrder[] = " TITLE ".$order." ";
					$needle = 'TITLE';
				break;

				case 'date_start':
					$arSqlOrder[] = " T.DATE_START ".$order." ";
					$needle = 'DATE_START';
				break;

				case 'created_date':
					$arSqlOrder[] = " T.CREATED_DATE ".$order." ";
					$needle = 'CREATED_DATE';
				break;

				case 'changed_date':
					$arSqlOrder[] = " T.CHANGED_DATE ".$order." ";
					$needle = 'CHANGED_DATE';
				break;

				case 'closed_date':
					$arSqlOrder[] = " T.CLOSED_DATE ".$order." ";
					$needle = 'CLOSED_DATE';
				break;

				case 'start_date_plan':
					$arSqlOrder[] = " T.START_DATE_PLAN ".$order." ";
					$needle = 'START_DATE_PLAN';
				break;

				case 'end_date_plan':
					$arSqlOrder[] = " T.END_DATE_PLAN ".$order." ";
					$needle = 'END_DATE_PLAN';
				break;

				case 'deadline':
					$orderClause = self::getOrderSql(
						'T.DEADLINE',
						$order,
						$default_order = 'asc,nulls',
						$nullable = true
					);
					$needle = 'DEADLINE_ORIG';

					if ( ! is_array($orderClause) )
						$arSqlOrder[] = $orderClause;
					else   // we have to add select field in order to correctly sort
					{
						//         COLUMN ALIAS      COLUMN EXPRESSION
						$arFields[$orderClause[1]] = $orderClause[0];

						if ( ! in_array($orderClause[1], $arSelect) )
							$arSelect[] = $orderClause[1];

						$arSqlOrder[] = $orderClause[2];	// order expression
					}
				break;

				case 'status':
					$arSqlOrder[] = " STATUS ".$order." ";
					$needle = 'STATUS';
				break;

				case 'status_complete':
					$arSqlOrder[] = " STATUS_COMPLETE ".$order." ";
					$needle = 'STATUS_COMPLETE';
				break;

				case 'priority':
					$arSqlOrder[] = " PRIORITY ".$order." ";
					$needle = 'PRIORITY';
				break;

				case 'mark':
					$arSqlOrder[] = " MARK ".$order." ";
					$needle = 'MARK';
				break;

				case 'created_by':
					$arSqlOrder[] = " CREATED_BY_LAST_NAME ".$order." ";
					$needle = 'CREATED_BY_LAST_NAME';
				break;

				case 'responsible_id':
					$arSqlOrder[] = " RESPONSIBLE_LAST_NAME ".$order." ";
					$needle = 'RESPONSIBLE_LAST_NAME';
				break;

				case 'group_id':
					$arSqlOrder[] = " GROUP_ID ".$order." ";
					$needle = 'GROUP_ID';
				break;

				case 'time_estimate':
					$arSqlOrder[] = " TIME_ESTIMATE ".$order." ";
					$needle = 'TIME_ESTIMATE';
				break;

				case 'allow_change_deadline':
					$arSqlOrder[] = " ALLOW_CHANGE_DEADLINE ".$order." ";
					$needle = 'ALLOW_CHANGE_DEADLINE';
				break;

				case 'allow_time_tracking':
					$arSqlOrder[] = " ALLOW_TIME_TRACKING ".$order." ";
					$needle = 'ALLOW_TIME_TRACKING';
				break;

				case 'match_work_time':
					$arSqlOrder[] = " MATCH_WORK_TIME ".$order." ";
					$needle = 'MATCH_WORK_TIME';
				break;

				case 'favorite':
					$arSqlOrder[] = " FAVORITE ".$order." ";
					$needle = 'FAVORITE';
				break;

				case 'sorting':
					$asc = stripos($order, "desc") === false;
					$arSqlOrder = array_merge($arSqlOrder, self::getSortingOrderBy($asc));
					$needle = "SORTING";
				break;

				default:
					if (substr($by, 0, 3) === 'uf_')
					{
						if ($s = $obUserFieldsSql->GetOrder($by))
							$arSqlOrder[$by] = " ".$s." ".$order." ";
					}
					else
						CTaskAssert::logWarning('[0x9a92cf7d] invalid sort by field requested: ' . $by);
				break;
			}

			if (
				($needle !== null)
				&& ( ! in_array($needle, $arSelect) )
			)
			{
				$arSelect[] = $needle;
			}
		}

		$arSqlSelect = array();
		foreach ($arSelect as $field)
		{
			$field = strtoupper($field);
			if (array_key_exists($field, $arFields))
				$arSqlSelect[$field] = $arFields[$field]." AS ".$field;
		}

		if (!sizeof($arSqlSelect))
		{
			$arSqlSelect = "T.ID AS ID";
		}

		$disableOptimization = (is_array($arParams) && $arParams['DISABLE_OPTIMIZATION'] === true);
		$disableAccessOptimization = (is_array($arParams) && $arParams['DISABLE_ACCESS_OPTIMIZATION'] === true);
		$useAccessAsJoin = !$disableAccessOptimization;

		// First level logic MUST be 'AND', because of backward compatibility
		// and some requests for checking rights, attached at first level of filter.
		// Situtation when there is OR-logic at first level cannot be resolved
		// in general case.
		// So if there is OR-logic, it is FATAL error caused by programmer.
		// But, if you want to use OR-logic at the first level of filter, you
		// can do this by putting all your filter conditions to the ::SUBFILTER-xxx,
		// except CHECK_PERMISSIONS, SUBORDINATE_TASKS (if you don't know exactly, 
		// what are consequences of this fields in OR-logic of subfilters).
		if (isset($arFilter['::LOGIC']))
			CTaskAssert::assert($arFilter['::LOGIC'] === 'AND');

		// try to make some trashy optimizations
		// (later you can remove the following block without getting any logic broken)
		$distinct = '';
		$additionalJoins = '';

		if(!$disableOptimization && $DB->type == 'MYSQL')
		{
			$optimized = static::tryOptimizeFilter($arFilter);
			$arFilter = $optimized['FILTER'];
			$additionalJoins = implode("\n\n", $optimized['JOINS']);
			if(count($optimized['JOINS']))
			{
				$distinct = 'distinct';
			}
		}

		$arParams['ENABLE_LEGACY_ACCESS'] = $disableAccessOptimization; // manual legacy access switch
		$arSqlSearch = CTasks::GetFilter($arFilter, '', $arParams);

		if ( ! $bGetZombie )
			$arSqlSearch[] = " T.ZOMBIE = 'N' ";

		$r = $obUserFieldsSql->GetFilter();
		if (strlen($r) > 0)
		{
			$arSqlSearch[] = "(".$r.")";
		}

		$strSql = "
			SELECT ".$distinct."
				".implode(",\n", $arSqlSelect)."
				".$obUserFieldsSql->GetSelect();

		$keysFiltered = CTasks::GetFilteredKeys($arFilter);

		$bNeedJoinFavoritesTable = in_array('FAVORITE', $arSelect, true) || array_key_exists('FAVORITE', $arOrder) || in_array('FAVORITE', $keysFiltered, true);
		$bNeedJoinForumsTable = in_array('COMMENTS_COUNT', $arSelect, true) || in_array('FORUM_ID', $arSelect, true);
		$bNeedJoinSortingTable = in_array('SORTING', $arSelect, true) || array_key_exists('SORTING', $arOrder) || in_array('SORTING', $keysFiltered, true);
		$sortingGroupId = is_array($arFilter) && isset($arFilter["GROUP_ID"]) ? intval($arFilter["GROUP_ID"]) : false;

		$accessSql = '';
		if($useAccessAsJoin && static::needAccessRestriction($arFilter, $arParams))
		{
			$arParams['APPLY_FILTER'] = static::makePossibleForwardedFilter($arFilter);
			$accessSql = static::appendJoinRights($accessSql, $arParams);
		}

		$viewedBy = $userID;
		if(array_key_exists('VIEWED_BY', $arFilter))
		{
			if(intval($arFilter['VIEWED_BY']))
			{
				$viewedBy = intval($arFilter['VIEWED_BY']);
			}
		}

		$strFrom = "
			FROM
				b_tasks T

			".$accessSql."
			".$additionalJoins."

			INNER JOIN b_user CU ON CU.ID = T.CREATED_BY 
			INNER JOIN b_user RU ON RU.ID = T.RESPONSIBLE_ID 
			LEFT JOIN b_tasks_viewed TV ON TV.TASK_ID = T.ID 
				AND TV.USER_ID = " . $viewedBy . " "

			// related joins
			. ($bNeedJoinSortingTable ? "
				LEFT JOIN ".SortingTable::getTableName()." SRT ON SRT.TASK_ID = T.ID and ".($sortingGroupId !== false ? "SRT.GROUP_ID = ".$sortingGroupId : "SRT.USER_ID = ".$userID)
				: "")

			. ($bNeedJoinFavoritesTable ? "
				LEFT JOIN ".FavoriteTable::getTableName()." FVT ON FVT.TASK_ID = T.ID and FVT.USER_ID = '".$userID/*always int, no sqli*/."'
				" : "")
			. ($bNeedJoinForumsTable ? "
				LEFT JOIN b_forum_topic FT ON FT.ID = T.FORUM_TOPIC_ID
				" : "")

			// UF_* join
			. $obUserFieldsSql->GetJoin("T.ID") . " "

			// filter
			. (sizeof($arSqlSearch) ? " WHERE ".implode(" AND ", $arSqlSearch) : "") . " ";

		$strSql .= $strFrom;

		$strSqlOrder = "";
		DelDuplicateSort($arSqlOrder);
		for ($i = 0, $arSqlOrderCnt = count($arSqlOrder); $i < $arSqlOrderCnt; $i++)
		{
			if ($i == 0)
				$strSqlOrder = " ORDER BY ";
			else
				$strSqlOrder .= ",";

			$strSqlOrder .= $arSqlOrder[$i];
		}

		$strSql .= $strSqlOrder;

		if (($nPageTop !== false) && is_numeric($nPageTop))
		{
			$strSql = $DB->TopSql($strSql, intval($nPageTop));
		}

		if (is_array($arParams) && array_key_exists("NAV_PARAMS", $arParams) && is_array($arParams["NAV_PARAMS"]))
		{
			$nTopCount = intval($arParams['NAV_PARAMS']['nTopCount']);
			if($nTopCount > 0)
			{
				$strSql = $DB->TopSql($strSql, $nTopCount);
				$res = $DB->Query($strSql, $bIgnoreErrors, "File: " . __FILE__ . "<br>Line: " . __LINE__);

				if ($res === false)
					throw new TasksException('', TasksException::TE_SQL_ERROR);

				$res->SetUserFields($USER_FIELD_MANAGER->GetUserFields("TASKS_TASK"));
			}
			else
			{
				$res_cnt = $DB->Query("SELECT COUNT(T.ID) as C " . $strFrom);
				$res_cnt = $res_cnt->Fetch();
				$totalTasksCount = (int) $res_cnt["C"];	// unknown by default

				// Sync counters in case of mistiming
//				CTaskCountersProcessorHomeostasis::onTaskGetList($arFilter, $totalTasksCount);

				$res = new CDBResult();
				$res->SetUserFields($USER_FIELD_MANAGER->GetUserFields("TASKS_TASK"));
				$rc = $res->NavQuery($strSql, $totalTasksCount, $arParams["NAV_PARAMS"], $bIgnoreErrors);

				if ($bIgnoreErrors && ($rc === false))
					throw new TasksException('', TasksException::TE_SQL_ERROR);
			}
		}
		else
		{
			$res = $DB->Query($strSql, $bIgnoreErrors, "File: " . __FILE__ . "<br>Line: " . __LINE__);

			if ($res === false)
				throw new TasksException('', TasksException::TE_SQL_ERROR);

			$res->SetUserFields($USER_FIELD_MANAGER->GetUserFields("TASKS_TASK"));
		}

		return $res;
	}

	private static function makePossibleForwardedMemberFilter($filter)
	{
		$result = array();

		if(is_array($filter) && !empty($filter))
		{
			// cannot forward filer with LOGIC OR or LOGIC NOT
			if(array_key_exists('LOGIC', $filter) && $filter['LOGIC'] != 'AND')
			{
				return $result;
			}
			if(array_key_exists('::LOGIC', $filter) && $filter['::LOGIC'] != 'AND')
			{
				return $result;
			}

			/** @see \CTasks::GetSqlByFilter() */
			if(array_key_exists('AUDITOR', $filter)) // we have equality to AUDITOR, not negation
			{
				$result[] = array(
					'=TYPE' => 'U',
					'=USER_ID' => $filter['AUDITOR'],
				);
			}
			elseif(array_key_exists('ACCOMPLICE', $filter)) // we have equality to ACCOMPLICE, not negation
			{
				$result[] = array(
					'=TYPE' => 'A',
					'=USER_ID' => $filter['ACCOMPLICE'],
				);
			}
		}

		return $result;
	}

	private static function makePossibleForwardedFilter($filter)
	{
		$result = array();

		$allowedFields = array(
			'ID' => true, // number_wo_nulls
			'TITLE' => true, // string
			'STATUS_CHANGED_BY' => true, // number
			'SITE_ID' => true, // string_equal

			'PRIORITY' => true, // number_wo_nulls
			'RESPONSIBLE_ID' => true, // number_wo_nulls
			'TIME_ESTIMATE' => true, // number_wo_nulls
			'CREATED_BY' => true, // number_wo_nulls
			'GUID' => true, // string
			'XML_ID' => true, // string_equal
			'MARK' => true, // string_equal
			'ALLOW_CHANGE_DEADLINE' => true, // string_equal
			'ALLOW_TIME_TRACKING' => true, // string_equal
			'ADD_IN_REPORT' => true, // string_equal
			'GROUP_ID' => true, // number
			'PARENT_ID' => true, // number
			'FORUM_TOPIC_ID' => true, // number
			'ZOMBIE' => true, // string_equal
			'MATCH_WORK_TIME' => true, // string_equal

			//dates
			/*
			'DATE_START' => true,
			'DEADLINE' => true,
			'START_DATE_PLAN' => true,
			'END_DATE_PLAN' => true,
			'CREATED_DATE' => true,
			'STATUS_CHANGED_DATE' => true,
			 */
		);

		$stringEqual = array(
			'SITE_ID' => true, // string_equal
			'XML_ID' => true, // string_equal
			'MARK' => true, // string_equal
			'ALLOW_CHANGE_DEADLINE' => true, // string_equal
			'ALLOW_TIME_TRACKING' => true, // string_equal
			'ADD_IN_REPORT' => true, // string_equal
			'ZOMBIE' => true, // string_equal
			'MATCH_WORK_TIME' => true, // string_equal
		);

		if(is_array($filter) && !empty($filter))
		{
			// cannot forward filer with LOGIC OR or LOGIC NOT
			if(array_key_exists('LOGIC', $filter) && $filter['LOGIC'] != 'AND')
			{
				return $result;
			}
			if(array_key_exists('::LOGIC', $filter) && $filter['::LOGIC'] != 'AND')
			{
				return $result;
			}

			$filter = \Bitrix\Tasks\Internals\DataBase\Helper\Common::parseFilter($filter);
			foreach($filter as $k => $condition)
			{
				$field = $condition['FIELD'];

				if(!array_key_exists($field, $allowedFields))
				{
					continue;
				}

				// convert like into strict check
				if(array_key_exists($field, $stringEqual))
				{
					// '' => '='
					if($condition['OPERATION'] == 'E')
					{
						$condition['OPERATION'] = 'I';
						unset($condition['ORIG_KEY']);
					}
					// '!' => '!='
					if($condition['OPERATION'] == 'N')
					{
						$condition['OPERATION'] = 'NI';
						unset($condition['ORIG_KEY']);
					}
				}

				// actually, allow only "equal" and "not equal"
				$op = $condition['OPERATION'];
				if($op != 'E' && $op != 'I' && $op != 'N' && $op != 'NI')
				{
					continue;
				}

				$result[] = $condition;
			}

			$result = \Bitrix\Tasks\Internals\DataBase\Helper\Common::makeFilter($result);
		}

		return $result;
	}

	private static function needAccessRestriction(array $arFilter, $arParams)
	{
		if (is_array($arParams) && array_key_exists('USER_ID', $arParams) && ($arParams['USER_ID'] > 0))
			$userID = (int) $arParams['USER_ID'];
		else
			$userID = User::getId();

		return
			!User::isSuper($userID)
			&&
			$arFilter["CHECK_PERMISSIONS"] != "N" // and not setted flag "skip permissions check"
			&&
			$arFilter["SUBORDINATE_TASKS"] != "Y"; // and not rights via subordination
	}

	function GetRootSubquery($arFilter=array(), $bGetZombie = false, $aliasPrefix = '')
	{
		$userID = User::getId();

		$arSqlSearch = array("(PT.ID = ".$aliasPrefix."T.PARENT_ID)");

		if ( ! $bGetZombie )
			$arSqlSearch[] = " (PT.ZOMBIE = 'N') ";

		if ($arFilter["SAME_GROUP_PARENT"] == "Y")
		{
			$arSqlSearch[] = "(PT.GROUP_ID = ".$aliasPrefix."T.GROUP_ID 
				OR (PT.GROUP_ID IS NULL AND ".$aliasPrefix."T.GROUP_ID IS NULL)
				OR (PT.GROUP_ID IS NULL AND ".$aliasPrefix."T.GROUP_ID = 0)
				OR (PT.GROUP_ID = 0 AND ".$aliasPrefix."T.GROUP_ID IS NULL)
			)";
		}
		unset($arFilter["ONLY_ROOT_TASKS"], $arFilter["SAME_GROUP_PARENT"]);

		$arSqlSearch = array_merge($arSqlSearch, CTasks::GetFilter($arFilter, "P"));
		$keysFiltered = CTasks::GetFilteredKeys($arFilter);

		$bNeedJoinFavoritesTable = in_array('FAVORITE', $keysFiltered, true);

		$strSql = "
			SELECT
				'x'
			FROM
				b_tasks PT
			LEFT JOIN
				b_tasks_viewed PTV ON PTV.TASK_ID = PT.ID AND PTV.USER_ID = ".$userID."

			". ($bNeedJoinFavoritesTable ? "
				LEFT JOIN ".FavoriteTable::getTableName()." PFVT ON PFVT.TASK_ID = PT.ID and PFVT.USER_ID = '".$userID/*always int, no sqli*/."'
				" : "")."

			WHERE
				".implode(" AND ", $arSqlSearch)."
		";

		//echo $strSql;

		return $strSql;
	}


	/**
	 * @param array $arFilter
	 * @param array $arParams
	 * @param array $arGroupBy
	 * @return bool|CDBResult
	 */
	public static function GetCount($arFilter=array(), $arParams = array(), $arGroupBy = array())
	{
		/**
		 * @global CDatabase $DB
		 */
		global $DB;

		$bIgnoreDbErrors = false;
		$bSkipUserFields = false;
		$bSkipExtraTables = false;
		$bSkipJoinTblViewed = false;
		$bNeedJoinMembersTable = false;

		if (isset($arParams['bIgnoreDbErrors']))
			$bIgnoreDbErrors = (bool) $arParams['bIgnoreDbErrors'];
		
		if (isset($arParams['bSkipUserFields']))
			$bSkipUserFields = (bool) $arParams['bSkipUserFields'];

		if (isset($arParams['bSkipExtraTables']))
			$bSkipExtraTables = (bool) $arParams['bSkipExtraTables'];

		if (isset($arParams['bSkipJoinTblViewed']))
			$bSkipJoinTblViewed = (bool) $arParams['bSkipJoinTblViewed'];

		if (isset($arParams['bNeedJoinMembersTable']))
			$bNeedJoinMembersTable = (bool) $arParams['bNeedJoinMembersTable'];

		$disableOptimization = (is_array($arParams) && $arParams['DISABLE_OPTIMIZATION'] === true);
		$disableAccessOptimization = (is_array($arParams) && $arParams['DISABLE_ACCESS_OPTIMIZATION'] === true);

		// in some cases, we can replace filter conditions
		$canUseOptimization = !$disableOptimization && !$bNeedJoinMembersTable;

		if ( ! $bSkipUserFields )
		{
			$obUserFieldsSql = new CUserTypeSQL;
			$obUserFieldsSql->SetEntity("TASKS_TASK", "T.ID");
			$obUserFieldsSql->SetFilter($arFilter);
		}

		if (!is_array($arFilter))
		{
			CTaskAssert::logError('[0x053f6639] expected array in $arFilter');
			$arFilter = array();
		}

		if (isset($arParams['USER_ID']))
			$userID = (int) $arParams['USER_ID'];
		else
			$userID = User::getId();

		static $arFields = array(
			'GROUP_ID'       => 'T.GROUP_ID',
			'CREATED_BY'     => 'T.CREATED_BY',
			'RESPONSIBLE_ID' => 'T.RESPONSIBLE_ID',
			'ACCOMPLICE'     => 'TM.USER_ID',
			'AUDITOR'        => 'TM.USER_ID'
		);

		$strGroupBy = ' ';
		$strSelect  = ' ';

		// ignore unknown fields
		$arGroupBy = array_intersect($arGroupBy, array_keys($arFields));

		if (is_array($arGroupBy) && ! empty($arGroupBy))
		{
			$arGroupByFields = array();
			foreach ($arGroupBy as $fieldName)
			{
				$strSelect = ', ' . $arFields[$fieldName] . ' AS ' . $fieldName;

				if (($fieldName === 'ACCOMPLICE') || ($fieldName === 'AUDITOR'))
					$bNeedJoinMembersTable = true;

				$arGroupByFields[] = $arFields[$fieldName];
			}

			$strGroupBy = ' GROUP BY ' . implode(', ', $arGroupByFields);
		}

		$sourceFilter = $arFilter;

		// try to make some trashy optimizations
		// (later you can remove the following block without getting any logic broken)
		$additionalJoins = '';
		if($canUseOptimization)
		{
			$optimized = static::tryOptimizeFilter($arFilter);
			$arFilter = $optimized['FILTER'];
			$additionalJoins = implode("\n\n", $optimized['JOINS']);
		}

		if (isset($arParams['bUseRightsCheck']))
		{
			$arFilter['CHECK_PERMISSIONS'] = ((bool) $arParams['bUseRightsCheck']) ? 'Y' : 'N';
		}

		$fParams = array(
			'bMembersTableJoined' => $bNeedJoinMembersTable,
			'USER_ID' => $userID,
		);
		$fParams['ENABLE_LEGACY_ACCESS'] = $disableAccessOptimization; // manual legacy access switch

		$arSqlSearch = CTasks::GetFilter(
			$arFilter,
			'',			// $sAliasPrefix
			$fParams
		);
		$arSqlSearch[] = " T.ZOMBIE = 'N' ";

		$ufJoin = ' ';
		if ( ! $bSkipUserFields )
		{
			$r = $obUserFieldsSql->GetFilter();
			if (strlen($r) > 0)
			{
				$arSqlSearch[] = "(".$r.")";
			}

			$ufJoin .= $obUserFieldsSql->GetJoin("T.ID");
		}

		$strSql = "
			SELECT
				COUNT(".($canUseOptimization ? 'distinct ' : '')."T.ID) AS CNT " . $strSelect . "
			FROM ";

		if ($bNeedJoinMembersTable)
			$strSql .= "b_tasks_member TM \n INNER JOIN b_tasks T ON T.ID = TM.TASK_ID ";
		else
			$strSql .= "b_tasks T ";

		if ( ! $bSkipExtraTables )
		{
			$strSql .= " INNER JOIN b_user CU ON CU.ID = T.CREATED_BY
				INNER JOIN b_user RU ON RU.ID = T.RESPONSIBLE_ID ";
		}

		if ( ! $bSkipJoinTblViewed )
		{
			$viewedBy = $userID;
			if(array_key_exists('VIEWED_BY', $arFilter))
			{
				if(intval($arFilter['VIEWED_BY']))
				{
					$viewedBy = intval($arFilter['VIEWED_BY']);
				}
			}

			$strSql .= "\n LEFT JOIN
				b_tasks_viewed TV ON TV.TASK_ID = T.ID AND TV.USER_ID = " . $viewedBy;
		}

		$useAccessAsJoin = !$disableAccessOptimization;

		// put access check into the join
		if($useAccessAsJoin && static::needAccessRestriction($arFilter, $fParams))
		{
			$fParams['APPLY_MEMBER_FILTER'] = static::makePossibleForwardedMemberFilter($sourceFilter);
			$fParams['APPLY_FILTER'] = static::makePossibleForwardedFilter($sourceFilter);

			$strSql = static::appendJoinRights($strSql, $fParams);
		}

		$strSql .= $additionalJoins;

		$strSql .= $ufJoin . " ";
		if ( ! empty($arSqlSearch) )
			$strSql .= "WHERE " . implode(" AND ", $arSqlSearch) . " ";

		$strSql .= $strGroupBy;

		//_print_r($strSql);_print_r('####################################################');

		$res = $DB->Query($strSql, $bIgnoreDbErrors, "File: ".__FILE__."<br>Line: ".__LINE__);

		return $res;
	}

	private static function appendJoinRights($sql, $arParams)
	{
		$arParams['THIS_TABLE_ALIAS'] = 'T';
		$arParams['PUT_SELECT_INTO_JOIN'] = true; // this means that $access['type'] will be always "join" or "none"
		$arParams['USE_STRICT_USER_ID'] = false;
		$access = \Bitrix\Tasks\Internals\RunTime\Task::getAccessCheckSql($arParams);

		if($access['sql'] != '')
		{
			$sql .= "\n\n/*access BEGIN*/\n\n inner join (".$access['sql'].") TASKS_ACCESS on T.ID = TASKS_ACCESS.TASK_ID\n\n/*access END*/\n\n";
		}

		return $sql;
	}

	private static function tryOptimizeFilter(array $arFilter)
	{
		$additionalJoins = array();

		// get rid of ::SUBFILTER-ROOT if can
		if(array_key_exists('::SUBFILTER-ROOT', $arFilter) && count($arFilter) == 1)
		{
			if($arFilter['::LOGIC'] != 'OR')
			{
				// we have only one element in the root, and logic is not "OR". then we could remove subfilter-root
				$arFilter = $arFilter['::SUBFILTER-ROOT'];
			}
		}

		// we can optimize only if there is no "or-logic"
		if($arFilter['::LOGIC'] != 'OR' && $arFilter['LOGIC'] != 'OR')
		{
			if(array_key_exists('MEMBER', $arFilter))
			{
				$member = intval($arFilter['MEMBER']);
				unset($arFilter['MEMBER']);

				$additionalJoins[] = "
				inner join b_tasks_member TM_SPEC on TM_SPEC.TASK_ID = T.ID and TM_SPEC.USER_ID = '".$member."'
			";
			}
			elseif(array_key_exists('DOER', $arFilter))
			{
				$member = intval($arFilter['DOER']);
				unset($arFilter['DOER']);

				$additionalJoins[] = "
				inner join b_tasks_member TM_SPEC on TM_SPEC.TASK_ID = T.ID and TM_SPEC.USER_ID = '".$member."' and TM_SPEC.TYPE in ('R', 'A')
			";
			}
			elseif(array_key_exists('AUDITOR', $arFilter)) // we have equality to AUDITOR, not negation
			{
				if(!is_array($arFilter['AUDITOR'])) // we have single value, not array which will cause "in ()" instead of =
				{
					$auditor = intval($arFilter['AUDITOR']);
					unset($arFilter['AUDITOR']);

					$additionalJoins[] = "
					inner join b_tasks_member TM_SPEC on TM_SPEC.TASK_ID = T.ID and TM_SPEC.USER_ID = '".$auditor."' and TM_SPEC.TYPE = 'U'
				";
				}
			}
			elseif(array_key_exists('ACCOMPLICE', $arFilter)) // we have equality to ACCOMPLICE, not negation
			{
				if(!is_array($arFilter['ACCOMPLICE'])) // we have single value, not array which will cause "in ()" instead of =
				{
					$auditor = intval($arFilter['ACCOMPLICE']);
					unset($arFilter['ACCOMPLICE']);

					$additionalJoins[] = "
					inner join b_tasks_member TM_SPEC on TM_SPEC.TASK_ID = T.ID and TM_SPEC.USER_ID = '".$auditor."' and TM_SPEC.TYPE = 'A'
				";
				}
			}
		}

		return array(
			'FILTER' => $arFilter,
			'JOINS' => $additionalJoins,
		);
	}

	public static function getUsersViewedTask($taskId)
	{
		global $DB;

		$taskId = (int) $taskId;

		$res = $DB->query(
			"SELECT USER_ID 
			FROM b_tasks_viewed
			WHERE TASK_ID = " . $taskId,
			true	// ignore DB errors
		);

		if ($res === false)
			throw new TasksException ('', TasksException::TE_SQL_ERROR);

		$arUsers = array();

		while ($ar = $res->fetch())
			$arUsers[] = (int) $ar['USER_ID'];

		return ($arUsers);
	}


	public static function GetCountInt($arFilter=array(), $arParams = array())
	{
		$count = 0;

		$rsCount = CTasks::GetCount($arFilter, $arParams);
		if ($arCount = $rsCount->Fetch())
		{
			$count = intval($arCount["CNT"]);
		}

		return $count;
	}


	public static function GetChildrenCount($arFilter, $arParentIDs)
	{
		global $DB;

		$obUserFieldsSql = new CUserTypeSQL;
		$obUserFieldsSql->SetEntity("TASKS_TASK", "T.ID");
		$obUserFieldsSql->SetFilter($arFilter);

		if (!is_array($arFilter))
			$arFilter = array();

		if (!$arParentIDs)
			return false;

		$arFilter["PARENT_ID"] = $arParentIDs;

		$userID = User::getId();

		unset($arFilter["ONLY_ROOT_TASKS"]);
		$arSqlSearch = CTasks::GetFilter($arFilter);
		$arSqlSearch[] = " T.ZOMBIE = 'N' ";

		$r = $obUserFieldsSql->GetFilter();
		if (strlen($r) > 0)
		{
			$arSqlSearch[] = "(".$r.")";
		}

		$keysFiltered = CTasks::GetFilteredKeys($arFilter);

		$bNeedJoinFavoritesTable = in_array('FAVORITE', $keysFiltered, true);

		$strSql = "
			SELECT
				T.PARENT_ID AS PARENT_ID,
				COUNT(T.ID) AS CNT
			FROM
				b_tasks T
			INNER JOIN b_user CU ON CU.ID = T.CREATED_BY
			INNER JOIN b_user RU ON RU.ID = T.RESPONSIBLE_ID
			
			". ($bNeedJoinFavoritesTable ? "
				LEFT JOIN ".FavoriteTable::getTableName()." FVT ON FVT.TASK_ID = T.ID and FVT.USER_ID = '".$userID/*always int, no sqli*/."'
				" : "")."
			
			LEFT JOIN
				b_tasks_viewed TV ON TV.TASK_ID = T.ID AND TV.USER_ID = ".$userID."
			".$obUserFieldsSql->GetJoin("T.ID")."
			".(sizeof($arSqlSearch) ? "WHERE ".implode(" AND ", $arSqlSearch) : "")."
			GROUP BY
				T.PARENT_ID
		";

		$res = $DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);

		return $res;
	}

	/**
	 * 
	 * @access private
	 */
	public static function GetOriginatorsByFilter($arFilter, $loggedInUserId)
	{
		return static::GetFieldGrouppedByFilter('CREATED_BY', $arFilter, $loggedInUserId);
	}

	/**
	 * 
	 * @access private
	 */
	public static function GetResponsiblesByFilter($arFilter, $loggedInUserId)
	{
		return static::GetFieldGrouppedByFilter('RESPONSIBLE_ID', $arFilter, $loggedInUserId);
	}

	private static function GetFieldGrouppedByFilter($column, $arFilter, $loggedInUserId)
	{
		CTaskAssert::assert($loggedInUserId && is_array($arFilter));

		$arSqlSearch = CTasks::GetFilter($arFilter, '', array('USER_ID' => $loggedInUserId));
		$arSqlSearch[] = " T.ZOMBIE = 'N' ";

		$keysFiltered = CTasks::GetFilteredKeys($arFilter);

		$bNeedJoinFavoritesTable = in_array('FAVORITE', $keysFiltered, true);

		$sql = "SELECT T.".$column." AS USER_ID, COUNT(T.ID) AS TASKS_CNT 
			FROM b_tasks T 
			LEFT JOIN b_tasks_viewed TV ON TV.TASK_ID = T.ID AND TV.USER_ID = " . $loggedInUserId . "

			". ($bNeedJoinFavoritesTable ? "
				LEFT JOIN ".FavoriteTable::getTableName()." FVT ON FVT.TASK_ID = T.ID and FVT.USER_ID = '".$loggedInUserId/*always int, no sqli*/."'
				" : "")."

			WHERE " . implode('AND', $arSqlSearch)
			. " GROUP BY T.".$column;

		return $GLOBALS['DB']->query($sql);
	}

	public static function GetSubordinateSql($sAliasPrefix="", $arParams = array(), $behaviour = array())
	{
		$arDepsIDs = \Bitrix\Tasks\Integration\Intranet\Department::getSubordinateIds($arParams['USER_ID'], true);

		if (sizeof($arDepsIDs))
		{
			$rsDepartmentField = CUserTypeEntity::GetList(array(), array("ENTITY_ID" => "USER", "FIELD_NAME" => "UF_DEPARTMENT"));
			if ($arDepartmentField = $rsDepartmentField->Fetch())
			{
				return CTasks::GetDeparmentSql($arDepsIDs, $sAliasPrefix, $arParams, $behaviour);
			}
		}

		return false;
	}


	function GetDeparmentSql($arDepsIDs, $sAliasPrefix="", $arParams = array(), $behaviour = array())
	{
		global $DBType;

		if (!is_array($arDepsIDs))
		{
			$arDepsIDs = array(intval($arDepsIDs));
		}

		if(!is_array($behaviour))
		{
			$behaviour = array();
		}
		if(!isset($behaviour['ALIAS']))
		{
			$behaviour['ALIAS'] = $sAliasPrefix;
		}
		if(!isset($arParams['FIELDS']))
		{
			$arParams['FIELDS'] = array();
		}

		$a = $sAliasPrefix;
		$b = $behaviour;
		$f =& $arParams['FIELDS'];

		//static::placeFieldSql('CREATED_BY', 	$b, $f)

		$rsDepartmentField = CUserTypeEntity::GetList(array(), array("ENTITY_ID" => "USER", "FIELD_NAME" => "UF_DEPARTMENT"));
		$cntOfDepartments = count($arDepsIDs);
		if ($cntOfDepartments && $arDepartmentField = $rsDepartmentField->Fetch())
		{
			if (
				($DBType === 'oracle')
				&& ($valuesLimit = 1000)
				&& ($cntOfDepartments > $valuesLimit)
			)
			{
				$arConstraints = array();
				$sliceIndex = 0;
				while ($sliceIndex < $cntOfDepartments)
				{
					$arConstraints[] = $sAliasPrefix . 'BUF1.VALUE_INT IN (' 
						. implode(',', array_slice($arDepsIDs, $sliceIndex, $valuesLimit))
						. ')';

					$sliceIndex += $valuesLimit;
				}

				$strConstraint = '(' . implode(' OR ', $arConstraints) . ')';
			}
			else
				$strConstraint = $sAliasPrefix . "BUF1.VALUE_INT IN (" . implode(",", $arDepsIDs) . ")";

			// EXISTS!
			$strSql = "
				SELECT
					'x'
				FROM
					b_utm_user ".$sAliasPrefix."BUF1
				WHERE
					".$sAliasPrefix."BUF1.FIELD_ID = ".$arDepartmentField["ID"]."
				AND
					(" . $sAliasPrefix . "BUF1.VALUE_ID = " . static::placeFieldSql('RESPONSIBLE_ID', $b, $f)."
						OR " . $sAliasPrefix . "BUF1.VALUE_ID = " . static::placeFieldSql('CREATED_BY', $b, $f) . "
						OR EXISTS(
							SELECT 'x' 
							FROM b_tasks_member ".$sAliasPrefix."DSTM 
							WHERE ".$sAliasPrefix."DSTM.TASK_ID = ".static::placeFieldSql('ID', $b, $f)."
								AND ".$sAliasPrefix."DSTM.USER_ID = " . $sAliasPrefix . "BUF1.VALUE_ID
						)
					)
				AND
					" . $strConstraint . "
			";

			return $strSql;
		}

		return false;
	}


	/**
	 * Use CTaskItem->update() instead (with key 'ACCOMPLICES')
	 *
	 * @deprecated
	 */
	function AddAccomplices($ID, $arAccompleces = array())
	{
		if ($arAccompleces)
		{
			$arAccompleces = array_unique($arAccompleces);
			foreach ($arAccompleces as $accomplice)
			{
				$arMember = array(
					"TASK_ID" => $ID,
					"USER_ID" => $accomplice,
					"TYPE" => "A"
				);
				$member = new CTaskMembers();
				$member->Add($arMember);
			}
		}
	}


	/**
	 * Use CTaskItem->update() instead (with key 'AUDITORS')
	 *
	 * @deprecated
	 */
	function AddAuditors($ID, $arAuditors = array())
	{
		if ($arAuditors)
		{
			$arAuditors = array_unique($arAuditors);
			foreach ($arAuditors as $auditor)
			{
				$arMember = array(
					"TASK_ID" => $ID,
					"USER_ID" => $auditor,
					"TYPE" => "U"
				);
				$member = new CTaskMembers();
				$member->Add($arMember);
			}
		}
	}


	function AddFiles($ID, $arFiles = array(), $arParams = array())
	{
		$arFilesIds = array();

		$userId = null;

		$bCheckRightsOnFiles = false;

		if (is_array($arParams))
		{
			if (isset($arParams['USER_ID']) && ($arParams['USER_ID'] > 0))
				$userId = (int) $arParams['USER_ID'];

			if (isset($arParams['CHECK_RIGHTS_ON_FILES']))
				$bCheckRightsOnFiles = $arParams['CHECK_RIGHTS_ON_FILES'];
		}

		if ($userId === null)
		{
			$userId = User::getId();
			if(!$userId)
			{
				$userId = User::getAdminId();
			}
		}

		if ($arFiles)
		{
			foreach ($arFiles as $file)
				$arFilesIds[] = (int) $file;

			if (count($arFilesIds))
			{
				CTaskFiles::AddMultiple(
					$ID,
					$arFilesIds,
					array(
						'USER_ID'               => $userId,
						'CHECK_RIGHTS_ON_FILES' => $bCheckRightsOnFiles
					)
				);
			}
		}
	}


	function AddTags($ID, $USER_ID, $arTags = array(), $effectiveUserId = null)
	{
		// delete previous
		$oTag = new CTaskTags();
		$oTag->DeleteByTaskID($ID);

		if ($arTags)
		{
			if (!is_array($arTags))
			{
				$arTags = explode(",", $arTags);
			}
			$arTags = array_unique(array_map("trim", $arTags));

			foreach ($arTags as $tag)
			{
				$arTag = array(
					"TASK_ID" => $ID,
					"USER_ID" => $USER_ID,
					"NAME" => $tag
				);
				$oTag = new CTaskTags();
				$oTag->Add($arTag, $effectiveUserId);
			}
		}
	}


	function AddPrevious($ID, $arPrevious = array())
	{
		$oDependsOn = new CTaskDependence();
		$oDependsOn->DeleteByTaskID($ID);

		if ($arPrevious)
		{
			$arPrevious = array_unique(array_map('intval', $arPrevious));

			foreach ($arPrevious as $dependsOn)
			{
				$arDependsOn = array(
					"TASK_ID" => $ID,
					"DEPENDS_ON_ID" => $dependsOn
				);
				$oDependsOn = new CTaskDependence();
				$oDependsOn->Add($arDependsOn);
			}
		}
	}


	function Index($arTask, $tags)
	{
		$arTask['SE_TAG'] = $tags;
		\Bitrix\Tasks\Integration\Search\Task::index($arTask);
	}


	function OnSearchReindex($NS=array(), $oCallback=NULL, $callback_method="")
	{
		$arResult = array();
		$arOrder  = array('ID' => 'ASC');
		$arFilter = array();

		if (isset($NS['MODULE']) && ($NS['MODULE'] === 'tasks') 
			&& isset($NS['ID']) && ($NS['ID'] > 0)
		)
		{
			$arFilter['>ID'] = (int) $NS['ID'];
		}
		else
			$arFilter['>ID'] = 0;


		$rsTasks = CTasks::GetList($arOrder, $arFilter);
		while ($arTask = $rsTasks->Fetch())
		{
			$rsTags = CTaskTags::GetList(array(), array("TASK_ID" => $arTask["ID"]));
			$arTags = array();
			while ($arTag = $rsTags->Fetch())
			{
				$arTags[] = $arTag["NAME"];
			}

			$arTask["ACCOMPLICES"] = $arTask["AUDITORS"] = array();
			$rsMembers = CTaskMembers::GetList(array(), array("TASK_ID" => $arTask["ID"]));
			while ($arMember = $rsMembers->Fetch())
			{
				if ($arMember["TYPE"] == "A")
				{
					$arTask["ACCOMPLICES"][] = $arMember["USER_ID"];
				}
				elseif ($arMember["TYPE"] == "U")
				{
					$arTask["AUDITORS"][] = $arMember["USER_ID"];
				}
			}

			// todo: get path form socnet
			if ($arTask["GROUP_ID"] > 0)
			{
				$path = str_replace("#group_id#", $arTask["GROUP_ID"], COption::GetOptionString("tasks", "paths_task_group_entry", "/workgroups/group/#group_id#/tasks/task/view/#task_id#/", $arTask["SITE_ID"]));
			}
			else
			{
				$path = str_replace("#user_id#", $arTask["RESPONSIBLE_ID"], COption::GetOptionString("tasks", "paths_task_user_entry", "/company/personal/user/#user_id#/tasks/task/view/#task_id#/", $arTask["SITE_ID"]));
			}
			$path = str_replace("#task_id#", $arTask["ID"], $path);

			$arPermissions = CTasks::__GetSearchPermissions($arTask);
			$Result = array(
				"ID" => $arTask["ID"],
				"LAST_MODIFIED" => $arTask["CHANGED_DATE"] ? $arTask["CHANGED_DATE"] : $arTask["CREATED_DATE"],
				"TITLE" => $arTask["TITLE"],
				"BODY" => strip_tags($arTask["DESCRIPTION"]) ? strip_tags($arTask["DESCRIPTION"]) : $arTask["TITLE"],
				"TAGS" => implode(",", $arTags),
				"URL" => $path,
				"SITE_ID" => $arTask["SITE_ID"],
				"PERMISSIONS" => $arPermissions,
			);

			if ($oCallback)
			{
				$index_res = call_user_func(array($oCallback, $callback_method), $Result);
				if(!$index_res)
					return $Result["ID"];
			}
			else
				$arResult[] = $Result;

			CTasks::UpdateForumTopicIndex($arTask["FORUM_TOPIC_ID"], "U", $arTask["RESPONSIBLE_ID"], "tasks", "view_all", $path, $arPermissions, $arTask["SITE_ID"]);
		}

		if ($oCallback)
			return false;

		return $arResult;
	}


	function UpdateForumTopicIndex($topic_id, $entity_type, $entity_id, $feature, $operation, $path, $arPermissions, $siteID)
	{
		global $DB;

		if(!CModule::IncludeModule("forum"))
			return;

		$topic_id = intval($topic_id);

		$rsForumTopic = $DB->Query("SELECT FORUM_ID FROM b_forum_topic WHERE ID = ".$topic_id);
		$arForumTopic = $rsForumTopic->Fetch();
		if(!$arForumTopic)
			return;

		CSearch::ChangePermission("forum", $arPermissions, false, $arForumTopic["FORUM_ID"], $topic_id);

		$rsForumMessages = $DB->Query("
			SELECT ID
			FROM b_forum_message
			WHERE TOPIC_ID = ".$topic_id."
		");
		while($arMessage = $rsForumMessages->Fetch())
		{
			CSearch::ChangeSite("forum", array($siteID => $path), $arMessage["ID"]);
		}

		$arParams = array(
			"feature_id" => "S".$entity_type."_".$entity_id."_".$feature."_".$operation,
			"socnet_user" => $entity_id,
		);

		CSearch::ChangeIndex("forum", array("PARAMS" => $arParams), false, $arForumTopic["FORUM_ID"], $topic_id);
	}


	public static function __GetSearchPermissions($arTask)
	{
		$arPermissions = array();

		// check task members
		if (!isset($arTask['ACCOMPLICES']) || !isset($arTask['AUDITORS']))
		{
			if (!isset($arTask['ACCOMPLICES']))
				$arTask['ACCOMPLICES'] = array();
			if (!isset($arTask['AUDITORS']))
				$arTask['AUDITORS'] = array();
			$rsMembers = CTaskMembers::GetList(array(), array("TASK_ID" => $arTask["ID"]));
			while ($arMember = $rsMembers->Fetch())
			{
				if ($arMember["TYPE"] == "A")
					$arTask["ACCOMPLICES"][] = $arMember["USER_ID"];
				elseif ($arMember["TYPE"] == "U")
					$arTask["AUDITORS"][] = $arMember["USER_ID"];
			}
		}

		// group id is set, then take permissions from socialnetwork settings
		if ($arTask["GROUP_ID"] > 0 && CModule::IncludeModule("socialnetwork"))
		{
			$prefix = "SG".$arTask["GROUP_ID"]."_";
			$letter = CSocNetFeaturesPerms::GetOperationPerm(SONET_ENTITY_GROUP, $arTask["GROUP_ID"], "tasks", "view_all");
			switch($letter)
			{
				case "N"://All
					$arPermissions[] = 'G2';
					break;
				case "L"://Authorized
					$arPermissions[] = 'AU';
					break;
				case "K"://Group members includes moderators and admins
					$arPermissions[] = $prefix.'K';
				case "E"://Moderators includes admins
					$arPermissions[] = $prefix.'E';
				case "A"://Admins
					$arPermissions[] = $prefix.'A';
					break;
			}
		}

		// if neither "all users" nor "authorized user" enabled, turn permissions on at least for task members
		if (!in_array("G2", $arPermissions) && !in_array("AU", $arPermissions))
		{
			if (!$arTask["ACCOMPLICES"])
				$arTask["ACCOMPLICES"] = array();

			if (!$arTask["AUDITORS"])
				$arTask["AUDITORS"] = array();

			$arParticipants = array_unique(array_merge(array($arTask["CREATED_BY"], $arTask["RESPONSIBLE_ID"]), $arTask["ACCOMPLICES"], $arTask["AUDITORS"]));
			foreach($arParticipants as $userId)
				$arPermissions[] = "U".$userId;

			$arDepartments = array();

			$arSubUsers = array_unique(array($arTask['RESPONSIBLE_ID'], $arTask['CREATED_BY']));

			foreach ($arSubUsers as $subUserId)
			{
				$arUserDepartments = CTasks::GetUserDepartments($subUserId);

				if (is_array($arUserDepartments) && count($arUserDepartments))
					$arDepartments = array_merge($arDepartments, $arUserDepartments);
			}

			$arDepartments = array_unique($arDepartments);
			$arManagersTmp = CTasks::GetDepartmentManagers($arDepartments);

			if (is_array($arManagersTmp))
			{
				$arManagers = array_keys($arManagersTmp);

				// Remove $arSubUsers from $arManagers
				$arManagers = array_diff($arManagers, $arSubUsers);

				foreach($arManagers as $userId)
				{
					if (!in_array("U".$userId, $arPermissions))
						$arPermissions[] = "U".$userId;
				}
			}
		}

		// adimins always allowed to view search result
		$arPermissions[] = 'G1';

		return $arPermissions;
	}

	/**
	 * Agent handler for repeating tasks.
	 * Create new task based on given template.
	 * 
	 * @param integer $templateId - id of task template
	 * @param integer $flipFlop unused
	 * @param mixed[] $debugHere
	 * 
	 * @return string empty string.
	 * @deprecated
	 */
	public static function RepeatTaskByTemplateId ($templateId, $flipFlop = 1, array &$debugHere = array())
	{
		return Replicator\Task\FromTemplate::repeatTask(
			$templateId,
			array(
				// todo: get rid of use of CTasks one day...
				'AGENT_NAME_TEMPLATE' => 'CTasks::RepeatTaskByTemplateId(#ID#);',
				'RESULT' => &$debugHere,
			)
		);
	}


	/**
	 * @deprecated
	 *
	 * This function is deprecated and strongly discouraged to be used.
	 * But it will not be removed, because some agents can be still active for
	 * using this function in future for at least one year.
	 * Current date is: 06 Oct 2012, Sat. Code written, but updater not built.
	 *
	 * @param $TASK_ID
	 * @param string $time
	 * @return string originally always returns an empty string
	 */
	function RepeatTask($TASK_ID, /** @noinspection PhpUnusedParameterInspection */ $time="")
	{
		$rsTemplate = CTaskTemplates::GetList(
			array(), 
			array('TASK_ID' => (int) $TASK_ID)
		);

		if ( ! ($arTemplate = $rsTemplate->Fetch()) )
			return ('');

		// Redirect call to new function
		if (isset($arTemplate['ID']) && ($arTemplate['ID'] > 0))
			self::RepeatTaskByTemplateId( (int) $arTemplate['ID'] );

		return ('');
	}

	/**
	 * @param $arParams
	 * @param bool $template
	 * @param integer $agentTime Time in server timezone
	 * @return bool|string
	 */
	public static function getNextTime($arParams, $template = false, $agentTime = false)
	{
		if(!is_array($arParams))
		{
			return false;
		}

		$templateData = false;
		if(is_array($template))
		{
			$templateData = $template;
		}
		elseif($template = intval($template))
		{
			$item = \CTaskTemplates::getList(array(), array('ID' => $template), array(), array(), array('CREATED_BY', 'REPLICATE_PARAMS', 'TPARAM_REPLICATION_COUNT'))->fetch();
			if($item)
			{
				$templateData = $item;
			}
		}

		if(!$templateData)
		{
			$templateData = array();
		}
		$templateData['REPLICATE_PARAMS'] = $arParams;

		$result = Replicator\Task\FromTemplate::getNextTime($templateData, $agentTime);
		$rData = $result->getData();

		return $rData['TIME'] == '' ? false : $rData['TIME'];
	}

	public static function CanGivenUserDelete($userId, $taskCreatedBy, $taskGroupId, /** @noinspection PhpUnusedParameterInspection */ $site_id = SITE_ID)
	{
		$userId = (int) $userId;
		$taskGroupId = (int) $taskGroupId;

		$site_id = null;	// not used, left in function declaration for backward compatibility

		if ($userId <= 0)
			throw new TasksException();

		if (
			CTasksTools::IsAdmin($userId)
			|| CTasksTools::IsPortalB24Admin($userId)
			|| ($userId == $taskCreatedBy)
		)
		{
			return (true);
		}
		elseif (($taskGroupId > 0) && CModule::IncludeModule('socialnetwork'))
		{
			return (boolean) CSocNetFeaturesPerms::CanPerformOperation($userId, SONET_ENTITY_GROUP, $taskGroupId, "tasks", "delete_tasks");
		}

		return false;
	}


	public static function CanCurrentUserDelete($task, $site_id = SITE_ID)
	{
		if (!$userID = User::getId()) // wtf?
		{
			return false;
		}

		return (self::CanGivenUserDelete($userID, $task['CREATED_BY'], $task['GROUP_ID'], $site_id));
	}


	public static function CanGivenUserEdit($userId, $taskCreatedBy, $taskGroupId, /** @noinspection PhpUnusedParameterInspection */ $site_id = SITE_ID)
	{
		$userId = (int) $userId;
		$taskGroupId = (int) $taskGroupId;

		$site_id = null;	// not used, left in function declaration for backward compatibility    /** @noinspection PhpUnusedParameterInspection */

		if ($userId <= 0)
			throw new TasksException();

		if (
			CTasksTools::IsAdmin($userId)
			|| CTasksTools::IsPortalB24Admin($userId)
			|| ($userId == $taskCreatedBy)
		)
		{
			return (true);
		}
		elseif (($taskGroupId > 0) && CModule::IncludeModule('socialnetwork'))
		{
			return (boolean) CSocNetFeaturesPerms::CanPerformOperation($userId, SONET_ENTITY_GROUP, $taskGroupId, "tasks", "edit_tasks");
		}

		return false;
	}


	public static function CanCurrentUserEdit($task, $site_id = SITE_ID)
	{
		if (!$userID = User::getId())
		{
			return false;
		}

		return (self::CanGivenUserEdit($userID, $task['CREATED_BY'], $task['GROUP_ID'], $site_id));
	}


	public static function UpdateViewed($TASK_ID, $USER_ID)
	{
		self::__updateViewed($TASK_ID, $USER_ID);
	}

	public static function __updateViewed($TASK_ID, $USER_ID, $onTaskAdd = false)
	{
		$USER_ID = (int) $USER_ID;
		$TASK_ID = (int) $TASK_ID;

		$list = \Bitrix\Tasks\Internals\Task\ViewedTable::getList(array(
			"select" => array("TASK_ID", "USER_ID"),
			"filter" => array(
				"=TASK_ID" => $TASK_ID,
				"=USER_ID" => $USER_ID,
			),
		));
		if ($item = $list->fetch())
		{
			\Bitrix\Tasks\Internals\Task\ViewedTable::update($item, array(
				"VIEWED_DATE" => new \Bitrix\Main\Type\DateTime(),
			));
		}
		else
		{
			\Bitrix\Tasks\Internals\Task\ViewedTable::add(array(
				"TASK_ID" => $TASK_ID,
				"USER_ID" => $USER_ID,
				"VIEWED_DATE" => new \Bitrix\Main\Type\DateTime(),
			));
		}

		CTaskCountersProcessor::onAfterTaskViewedFirstTime($TASK_ID, $USER_ID, $onTaskAdd);
	}

	function GetUpdatesCount($arViewed)
	{
		global $DB;
		if ($userID = User::getId())
		{
			$arSqlSearch = array();
			$arUpdatesCount = array();
			foreach($arViewed as $key=>$val)
			{
				$arSqlSearch[] = "(CREATED_DATE > " . $DB->CharToDateFunction($val) . " AND TASK_ID = " . (int) $key . ")";
				$arUpdatesCount[$key] = 0;
			}

			if ( ! empty($arSqlSearch) )
			{
				$strSql = "
					SELECT
						TL.TASK_ID AS TASK_ID,
						COUNT(TL.TASK_ID) AS CNT
					FROM
						b_tasks_log TL
					WHERE
						USER_ID != " . $userID . "
						AND (
						".implode(" OR ", $arSqlSearch)."
						)
					GROUP BY
						TL.TASK_ID
				";

				$rsUpdatesCount = $DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);
				while($arUpdate = $rsUpdatesCount->Fetch())
				{
					$arUpdatesCount[$arUpdate["TASK_ID"]] = $arUpdate["CNT"];
				}

				return $arUpdatesCount;
			}
		}

		return false;
	}


	function GetFilesCount($arTasksIDs)
	{
		global $DB;

		$arFilesCount = array();

		$arTasksIDs = array_filter($arTasksIDs);

		if (sizeof($arTasksIDs))
		{
			$strSql = "
				SELECT
					TF.TASK_ID,
					COUNT(TF.FILE_ID) AS CNT
				FROM
					b_tasks_file TF
				WHERE
					TF.TASK_ID IN (".implode(",", $arTasksIDs).")
			";
			$rsFilesCount = $DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);
			while($arFile = $rsFilesCount->Fetch())
			{
				$arFilesCount[$arFile["TASK_ID"]] = $arFile["CNT"];
			}
		}

		return $arFilesCount;
	}


	function CanCurrentUserViewTopic($topicID)
	{
		$isSocNetModuleIncluded = CModule::IncludeModule("socialnetwork");

		if (($topicID = intval($topicID)) && User::getId())
		{
			if (User::isSuper())
			{
				return true;
			}

			$rsTask = $res = CTasks::GetList(array(), array("FORUM_TOPIC_ID" => $topicID));
			if ($arTask = $rsTask->Fetch())
			{
				if ( ((int)$arTask['GROUP_ID']) > 0 )
				{
					if (in_array(CSocNetFeaturesPerms::GetOperationPerm(SONET_ENTITY_GROUP, $arTask["GROUP_ID"], "tasks", "view_all"), array("G2", "AU")))
						return true;
					elseif (
						$isSocNetModuleIncluded
						&& (false !== CSocNetFeaturesPerms::CurrentUserCanPerformOperation(SONET_ENTITY_GROUP, $arTask['GROUP_ID'], 'tasks', 'view_all'))
					)
					{
						return (true);
					}
				}

				$arTask["ACCOMPLICES"] = $arTask["AUDITORS"] = array();
				$rsMembers = CTaskMembers::GetList(array(), array("TASK_ID" => $arTask["ID"]));
				while ($arMember = $rsMembers->Fetch())
				{
					if ($arMember["TYPE"] == "A")
					{
						$arTask["ACCOMPLICES"][] = $arMember["USER_ID"];
					}
					elseif ($arMember["TYPE"] == "U")
					{
						$arTask["AUDITORS"][] = $arMember["USER_ID"];
					}
				}

				if (in_array(User::getId(), array_unique(array_merge(array($arTask["CREATED_BY"], $arTask["RESPONSIBLE_ID"]), $arTask["ACCOMPLICES"], $arTask["AUDITORS"]))))
					return true;


				$dbRes = CUser::GetList($by='ID', $order='ASC', array('ID' => $arTask["RESPONSIBLE_ID"]), array('SELECT' => array('UF_DEPARTMENT')));

				if (($arRes = $dbRes->Fetch()) && is_array($arRes['UF_DEPARTMENT']) && count($arRes['UF_DEPARTMENT']) > 0)
					if (in_array(User::getId(), array_keys(CTasks::GetDepartmentManagers($arRes['UF_DEPARTMENT'], $arTask["RESPONSIBLE_ID"]))))
						return true;
			}
		}

		return false;
	}

	public static function getParentOfTask($taskId)
	{
		$taskId = intval($taskId);
		if(!$taskId)
		{
			return false;
		}

		global $DB;

		$item = $DB->query("select PARENT_ID from b_tasks where ID = '".$taskId."'")->fetch();

		return intval($item['PARENT_ID']) ? intval($item['PARENT_ID']) : false;
	}

	public static function GetUserDepartments($USER_ID)
	{
		static $cache = array();
		$USER_ID = (int) $USER_ID;

		if (!isset($cache[$USER_ID]))
		{
			$dbRes = CUser::GetList($by='ID', $order='ASC', array('ID' => $USER_ID), array('SELECT' => array('UF_DEPARTMENT')));

			if ($arRes = $dbRes->Fetch())
				$cache[$USER_ID] = $arRes['UF_DEPARTMENT'];
			else
				$cache[$USER_ID] = false;
		}

		return $cache[$USER_ID];
	}


	public static function onBeforeSocNetGroupDelete($inGroupId)
	{
		global $DB, $APPLICATION;

		$bCanDelete = false;	// prohibit group removing by default

		$groupId = (int) $inGroupId;

		$strSql =
			"SELECT ID AS TASK_ID
			FROM b_tasks 
			WHERE GROUP_ID = $groupId
				AND ZOMBIE = 'N'
			";
		
		$result = $DB->Query($strSql, false, 'File: ' . __FILE__ . '<br>Line: ' . __LINE__);
		if ($result === false)
		{
			$APPLICATION->ThrowException('EA_SQL_ERROR_OCCURED');
			return (false);
		}

		$arResult = $result->Fetch();

		// permit group deletion only when there is no tasks
		if ($arResult === false)
			$bCanDelete = true;
		else
			$APPLICATION->ThrowException(GetMessage('TASKS_ERR_GROUP_IN_USE'));

		return ($bCanDelete);
	}


	public static function OnBeforeUserDelete($inUserID)
	{
		global $DB, $APPLICATION;

		$userID = (int) $inUserID;
		if ( ! ($userID > 0) )
		{
			$APPLICATION->ThrowException(GetMessage('TASKS_BAD_USER_ID'));
			return (false);
		}

		// check for tasks
		$strSql =
			"SELECT ID AS TASK_ID
			FROM b_tasks 
			WHERE
				(
					CREATED_BY = $userID 
					OR RESPONSIBLE_ID = $userID
				)
				AND ZOMBIE = 'N'
			
			UNION
			
			SELECT TASK_ID 
			FROM b_tasks_member 
			WHERE USER_ID = $userID";
		
		$result = $DB->Query($strSql, false, 'File: ' . __FILE__ . '<br>Line: ' . __LINE__);
		if ($result === false)
		{
			$APPLICATION->ThrowException('EA_SQL_ERROR_OCCURED');
			return (false);
		}

		$tasks = array();
		while($item = $result->fetch())
		{
			$tasks[$item['TASK_ID']] = true;
		}

		$templates = array();
		$res = \Bitrix\Tasks\TemplateTable::getList(array('filter' => array(
			'LOGIC' => 'OR',
			array('=CREATED_BY' => $userID),
			array('=RESPONSIBLE_ID' => $userID)
		), 'select' => array('ID')));
		while($item = $res->fetch())
		{
			$templates[$item['ID']] = true;
		}

		$errorMessages = array();

		if(!empty($tasks))
		{
			$tasks = array_keys($tasks);
			$tail = '';
			$count = count($tasks);
			if($count > 10)
			{
				$tasks = array_slice($tasks, 0, 10);
				$tail = GetMessage('TASKS_ERR_USER_IN_USE_TAIL', array('#N#' => $count - 10));
			}

			$errorMessages[] = GetMessage('TASKS_ERR_USER_IN_USE_TASKS', array('#IDS#' => implode(', ', $tasks))).$tail;
		}

		if(!empty($templates))
		{
			$templates = array_keys($templates);
			$tail = '';
			$count = count($templates);
			if($count > 10)
			{
				$templates = array_slice($templates, 0, 10);
				$tail = GetMessage('TASKS_ERR_USER_IN_USE_TAIL', array('#N#' => $count - 10));
			}

			$errorMessages[] = GetMessage('TASKS_ERR_USER_IN_USE_TEMPLATES', array('#IDS#' => implode(', ', $templates))).$tail;
		}

		$errorMessages = implode(', ', $errorMessages);

		if((string) $errorMessages != '')
			$APPLICATION->ThrowException(GetMessage('TASKS_ERR_USER_IN_USE_TASKS_PREFIX', array('#ENTITIES#' => $errorMessages)));

		return (empty($tasks) && empty($templates));
	}

	// $value comes in units of $type, we must translate to seconds
	private static function convertDurationToSeconds($value, $type)
	{
		if($type == self::TIME_UNIT_TYPE_HOUR)
		{
			// hours to seconds
			return intval($value) * 3600;
		}
		elseif($type == self::TIME_UNIT_TYPE_DAY || (string) $type == ''/*days by default, see install/db*/)
		{
			// days to seconds
			return intval($value) * 86400;
		}

		return $value;
	}

	// $value comes in seconds, we must translate to units of $type
	public static function convertDurationFromSeconds($value, $type)
	{
		if($type == self::TIME_UNIT_TYPE_HOUR)
		{
			// hours to seconds
			return round(intval($value) / 3600, 0);
		}
		elseif($type == self::TIME_UNIT_TYPE_DAY || (string) $type == ''/*days by default, see install/db*/)
		{
			// days to seconds
			return round(intval($value) / 86400, 0);
		}

		return $value;
	}

	public static function OnUserDelete($USER_ID)
	{
		global $CACHE_MANAGER, $DB;
		$USER_ID = intval($USER_ID);
		$strSql = "
			SELECT RESPONSIBLE_ID AS USER_ID FROM b_tasks WHERE CREATED_BY = ".$USER_ID." AND CREATED_BY != RESPONSIBLE_ID
			UNION
			SELECT CREATED_BY AS USER_ID FROM b_tasks WHERE RESPONSIBLE_ID = ".$USER_ID." AND CREATED_BY != RESPONSIBLE_ID
			UNION
			SELECT USER_ID FROM b_tasks_member WHERE TASK_ID IN (SELECT TASK_ID FROM b_tasks_member WHERE USER_ID = ".$USER_ID.")
		";
		$result = $DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);
		while($arResult = $result->Fetch())
		{
			$CACHE_MANAGER->ClearByTag("tasks_user_".$arResult["USER_ID"]);
		}
	}


	public static function EmitPullWithTagPrefix($arRecipients, $tagPrefix, $cmd, $arParams)
	{
		if ( ! is_array($arRecipients) )
			throw new TasksException('EA_PARAMS', TasksException::TE_WRONG_ARGUMENTS);

		$arRecipients = array_unique($arRecipients);

		if ( ! CModule::IncludeModule('pull') )
			return;

		/*
		$arEventData = array(
			'module_id' => 'tasks',
			'command'   => 'notify',
			'params'    => CIMNotify::GetFormatNotify(
				array(
					'ID' => -3
				)
			),
		);
		*/

		$bWasFatalError = false;

		foreach ($arRecipients as $userId)
		{
			$userId = (int) $userId;

			if ($userId < 1)
			{
				$bWasFatalError = true;
				continue;	// skip invalid items
			}

			//CPullStack::AddByUser($userId, $arEventData);
			CPullWatch::AddToStack(
				$tagPrefix . $userId, 
				array(
					'module_id'  => 'tasks',
					'command'    => $cmd,
					'params'     => $arParams
				)
			);
		}

		if ($bWasFatalError)
			throw new TasksException('EA_PARAMS', TasksException::TE_WRONG_ARGUMENTS);
	}


	public static function EmitPullWithTag($arRecipients, $tag, $cmd, $arParams)
	{
		if ( ! is_array($arRecipients) )
			throw new TasksException('EA_PARAMS', TasksException::TE_WRONG_ARGUMENTS);

		$arRecipients = array_unique($arRecipients);

		if ( ! CModule::IncludeModule('pull') )
			return;

		$bWasFatalError = false;

		foreach ($arRecipients as $userId)
		{
			$userId = (int) $userId;

			if ($userId < 1)
			{
				$bWasFatalError = true;
				continue;	// skip invalid items
			}

			//CPullStack::AddByUser($userId, $arEventData);
			CPullWatch::AddToStack(
				$tag, 
				array(
					'module_id'  => 'tasks',
					'command'    => $cmd,
					'params'     => $arParams
				)
			);
		}

		if ($bWasFatalError)
			throw new TasksException('EA_PARAMS', TasksException::TE_WRONG_ARGUMENTS);
	}


	/**
	 * Get list of IDs groups, which contains tasks where given user is member
	 *
	 * @param integer $userId
	 * @throws TasksException
	 * @return array
	 */
	public static function GetGroupsWithTasksForUser($userId)
	{
		global $DB;

		$userId = (int) $userId;

		// EXISTS!
		$rc = $DB->Query(
			"SELECT GROUP_ID 
			FROM b_tasks T
			WHERE (
				T.CREATED_BY = $userId 
				OR T.RESPONSIBLE_ID = $userId 
				OR EXISTS(
					SELECT 'x' 
					FROM b_tasks_member TM 
					WHERE TM.TASK_ID = T.ID 
						AND TM.USER_ID = $userId
					)
				) 
				AND T.ZOMBIE = 'N' 
				AND GROUP_ID IS NOT NULL 
				AND GROUP_ID != 0
			GROUP BY GROUP_ID
			"
		);

		if ( ! $rc )
			throw new TasksException();

		$arGroups = array();

		while ($ar = $rc->Fetch())
			$arGroups[] = (int) $ar['GROUP_ID'];

		return (array_unique($arGroups));
	}


	/**
	 * This is experimental code, don't rely on it.
	 * It can be removed or changed in future without any notifications.
	 * 
	 * Use CTaskItem::getAllowedTaskActions() and CTaskItem::getAllowedTaskActionsAsStrings() instead.
	 * 
	 * @deprecated
	 */
	public static function GetAllowedActions($arTask, $userId = null)
	{
		$arAllowedActions = array();

		if ($userId === null)
		{
			$curUserId = (int) User::getId();
		}
		else
			$curUserId = (int) $userId;

		// we cannot use cached object here (CTaskItem::getInstanceFromPool($arTask['ID'], $curUserId);) 
		// because of backward compatibility (CTasks::Update() don't mark cache as dirty in pooled CTaskItem objects)
		$oTask = new CTaskItem($arTask['ID'], $curUserId);

		if ($oTask->isUserRole(CTaskItem::ROLE_RESPONSIBLE))
		{
			if ($arTask['REAL_STATUS'] == CTasks::STATE_NEW)
			{
				$arAllowedActions[] = array(
					'public_name' => 'accept',
					'system_name' => 'accept',
					'id'          => CTaskItem::ACTION_ACCEPT
				);

				$arAllowedActions[] = array(
					'public_name' => 'decline',
					'system_name' => 'decline',
					'id'          => CTaskItem::ACTION_DECLINE
				);
			}
		}

		if ($oTask->isActionAllowed(CTaskItem::ACTION_COMPLETE))
		{
			$arAllowedActions[] = array(
				'public_name' => 'close',
				'system_name' => 'close',
				'id'          => CTaskItem::ACTION_COMPLETE
			);
		}

		if ($oTask->isActionAllowed(CTaskItem::ACTION_START))
		{
			$arAllowedActions[] = array(
				'public_name' => 'start',
				'system_name' => 'start',
				'id'          => CTaskItem::ACTION_START
			);
		}

		if ($oTask->isActionAllowed(CTaskItem::ACTION_DELEGATE))
		{
			$arAllowedActions[] = array(
				'public_name' => 'delegate',
				'system_name' => 'delegate',
				'id'          => CTaskItem::ACTION_DELEGATE
			);
		}

		if ($oTask->isActionAllowed(CTaskItem::ACTION_APPROVE))
		{
			$arAllowedActions[] = array(
				'public_name' => 'approve',
				'system_name' => 'close',
				'id'          => CTaskItem::ACTION_APPROVE
			);
		}

		if ($oTask->isActionAllowed(CTaskItem::ACTION_DISAPPROVE))
		{
			$arAllowedActions[] = array(
				'public_name' => 'redo',
				'system_name' => 'accept',
				'id'          => CTaskItem::ACTION_DISAPPROVE
			);
		}

		if ($oTask->isActionAllowed(CTaskItem::ACTION_REMOVE))
		{
			$arAllowedActions[] = array(
				'public_name' => 'remove',
				'system_name' => 'remove',
				'id'          => CTaskItem::ACTION_REMOVE
			);
		}

		if ($oTask->isActionAllowed(CTaskItem::ACTION_EDIT))
		{
			$arAllowedActions[] = array(
				'public_name' => 'edit',
				'system_name' => 'edit',
				'id'          => CTaskItem::ACTION_EDIT
			);
		}

		if ($oTask->isActionAllowed(CTaskItem::ACTION_DEFER))
		{
			$arAllowedActions[] = array(
				'public_name' => 'pause',
				'system_name' => 'defer',
				'id'          => CTaskItem::ACTION_DEFER
			);
		}

		if ($oTask->isActionAllowed(CTaskItem::ACTION_START))
		{
			$arAllowedActions[] = array(
				'public_name' => 'renew',
				'system_name' => 'start',
				'id'          => CTaskItem::ACTION_START
			);
		}
		elseif ($oTask->isActionAllowed(CTaskItem::ACTION_RENEW))
		{
			$arAllowedActions[] = array(
				'public_name' => 'renew',
				'system_name' => 'accept',
				'id'          => CTaskItem::ACTION_RENEW
			);
		}

		if ($oTask->isActionAllowed(CTaskItem::ACTION_ADD_FAVORITE))
		{
			$arAllowedActions[] = array(
				'public_name' => 'add_favorite',
				'system_name' => 'add_favorite',
				'id'          => CTaskItem::ACTION_ADD_FAVORITE
			);
		}
		if ($oTask->isActionAllowed(CTaskItem::ACTION_DELETE_FAVORITE))
		{
			$arAllowedActions[] = array(
				'public_name' => 'delete_favorite',
				'system_name' => 'delete_favorite',
				'id'          => CTaskItem::ACTION_DELETE_FAVORITE
			);
		}

		return ($arAllowedActions);
	}


	/**
	 * Convert every given string in array from BB-code to HTML
	 *
	 * @param array $arStringsInBbcode
	 *
	 * @throws TasksException
	 * @return array of strings converted to HTML, keys maintaned
	 */
	public static function convertBbcode2Html($arStringsInBbcode)
	{
		if ( ! is_array($arStringsInBbcode) )
			throw new TasksException();

		static $delimiter = '--------This is unique BB-code strings delimiter at high confidence level (CL)--------';
		
		$stringsCount = count($arStringsInBbcode);
		$arStringsKeys = array_keys($arStringsInBbcode);

		$concatenatedStrings = implode($delimiter, $arStringsInBbcode);

		// While not unique identifier, try to
		$i = -150;
		while (count(explode($delimiter, $concatenatedStrings)) !== $stringsCount)
		{
			// prevent an infinite loop
			if ( ! ($i++) )
				throw new TasksException();

			$delimiter = '--------' . sha1(uniqid()) . '--------';
			$concatenatedStrings = implode($delimiter, $arStringsInBbcode);
		}

		$oParser = new CTextParser();

		$arHtmlStringsWoKeys = explode(
			$delimiter, 
			str_replace(
				"\t",
				' &nbsp; &nbsp;',
				$oParser->convertText($concatenatedStrings)
			)
		);

		$arHtmlStrings = array();

		// Do job in compatibility mode, if count of resulted strings not match source
		if (count($arHtmlStringsWoKeys) !== $stringsCount)
		{
			foreach ($arStringsInBbcode as $key => $str)
			{
				$oParser = new CTextParser();
				$arHtmlStrings[$key] = str_replace(
					"\t",
					' &nbsp; &nbsp;',
					$oParser->convertText($str)
				);
				unset($oParser);
			}
		}
		else
		{
			// Maintain original array keys
			$i = 0;
			foreach ($arStringsKeys as $key)
				$arHtmlStrings[$key] = $arHtmlStringsWoKeys[$i++];
		}

		return ($arHtmlStrings);
	}

	public static function getTaskSubTree($taskId)
	{
		$taskId = intval($taskId);
		if(!$taskId)
		{
			return array();
		}

		$queue = array($taskId);
		$met = array();
		$limit = 1000;
		$result = array();

		$i = 0;
		while(true)
		{
			if($i > $limit)
			{
				break;
			}

			$next = array_shift($queue);
			if(isset($met[$next]))
			{
				break;
			}
			if(!intval($next))
			{
				break;
			}

			$subTasks = self::getSubTaskIdsForTask($next);
			foreach($subTasks as $sTId)
			{
				$result[] = $sTId;
				$queue[] = $sTId;
			}

			$met[$next] = true;
			$i++;
		}

		return $result;
	}

	private static function getSubTaskIdsForTask($taskId)
	{
		global $DB;

		$taskId = intval($taskId);

		$result = array();
		$res = $DB->query("select ID from b_tasks where ZOMBIE != 'Y' and ".($taskId ? "PARENT_ID = '".$taskId."'" : "PARENT_ID is null or PARENT_ID = '0'"));
		while($item = $res->fetch())
		{
			if(intval($item['ID']))
			{
				$result[] = $item['ID'];
			}
		}

		return array_unique($result);
	}

	public static function runRestMethod($executiveUserId, $methodName, $args, $navigation)
	{
		CTaskAssert::assert($methodName === 'getlist');

		// Force & limit NAV_PARAMS (in 4th argument)
		while (count($args) < 4)
			$args[] = array();		// All params in CTasks::GetList() by default are empty arrays

		$arParams = & $args[3];

		if ($navigation['iNumPage'] > 1)
		{
			$arParams['NAV_PARAMS'] = array(
				'nPageSize' => CTaskRestService::TASKS_LIMIT_PAGE_SIZE,
				'iNumPage'  => (int) $navigation['iNumPage']
			);
		}
		else if (isset($arParams['NAV_PARAMS']))
		{
			if (isset($arParams['NAV_PARAMS']['nPageTop']))
				$arParams['NAV_PARAMS']['nPageTop'] = min(CTaskRestService::TASKS_LIMIT_TOP_COUNT, (int) $arParams['NAV_PARAMS']['nPageTop']);

			if (isset($arParams['NAV_PARAMS']['nPageSize']))
				$arParams['NAV_PARAMS']['nPageSize'] = min(CTaskRestService::TASKS_LIMIT_PAGE_SIZE, (int) $arParams['NAV_PARAMS']['nPageSize']);

			if (
				( ! isset($arParams['NAV_PARAMS']['nPageTop']) )
				&& ( ! isset($arParams['NAV_PARAMS']['nPageSize']) )
			)
			{
				$arParams['NAV_PARAMS'] = array(
					'nPageSize' => CTaskRestService::TASKS_LIMIT_PAGE_SIZE,
					'iNumPage'  => 1
				);
			}
		}
		else
		{
			$arParams['NAV_PARAMS'] = array(
				'nPageSize' => CTaskRestService::TASKS_LIMIT_PAGE_SIZE,
				'iNumPage'  => 1
			);
		}

		// Check and parse params
		$argsParsed = CTaskRestService::_parseRestParams('ctasks', $methodName, $args);

		$arParams['USER_ID'] = $executiveUserId;

		// TODO: remove this hack (needs for select tasks with GROUP_ID === NULL or 0)
		if (isset($argsParsed[1]))
		{
			$arFilter = $argsParsed[1];
			foreach ($arFilter as $key => $value)
			{
				if (($key === 'GROUP_ID') && ($value == 0))
				{
					$argsParsed[1]['META:GROUP_ID_IS_NULL_OR_ZERO'] = 1;
					unset($argsParsed[1][$key]);
					break;
				}
			}

			if (
				isset($argsParsed[1]['ID'])
				&& is_array($argsParsed[1]['ID'])
				&& empty($argsParsed[1]['ID'])
			)
			{
				$argsParsed[1]['ID'] = -1;
			}
		}

		$rsTasks = call_user_func_array(array('self', 'getlist'), $argsParsed);

		$arTasks = array();
		while ($arTask = $rsTasks->fetch())
			$arTasks[] = $arTask;

		return (array($arTasks, $rsTasks));
	}

	public static function getPublicFieldMap()
	{
		// READ, WRITE, SORT, FILTER, DATE
		return array(
			'TITLE' => 						array(1, 1, 1, 1, 0),
			'DESCRIPTION' => 				array(1, 1, 0, 0, 0),
			'DEADLINE' => 					array(1, 1, 1, 1, 1),
			'START_DATE_PLAN' => 			array(1, 1, 1, 1, 1),
			'END_DATE_PLAN' => 				array(1, 1, 1, 1, 1),
			'PRIORITY' => 					array(1, 1, 1, 1, 0),
			'ACCOMPLICES' => 				array(1, 1, 0, 0, 0),
			'AUDITORS' => 					array(1, 1, 0, 0, 0),
			'TAGS' => 						array(1, 1, 0, 0, 0),
			'ALLOW_CHANGE_DEADLINE' => 		array(1, 1, 1, 0, 0),
			'TASK_CONTROL' => 				array(1, 1, 0, 0, 0),
			'PARENT_ID' => 					array(1, 1, 0, 1, 0),
			'DEPENDS_ON' => 				array(1, 1, 0, 1, 0),
			'GROUP_ID' => 					array(1, 1, 1, 1, 0),
			'RESPONSIBLE_ID' => 			array(1, 1, 1, 1, 0),
			'TIME_ESTIMATE' => 				array(1, 1, 1, 1, 0),
			'ID' => 						array(1, 0, 1, 1, 0),
			'CREATED_BY' => 				array(1, 1, 1, 1, 0),
			'DESCRIPTION_IN_BBCODE' => 		array(1, 0, 0, 0, 0),
			'DECLINE_REASON' => 			array(1, 1, 0, 0, 0),
			'REAL_STATUS' => 				array(1, 0, 0, 1, 0),
			'STATUS' => 					array(1, 1, 1, 1, 0),
			'RESPONSIBLE_NAME' => 			array(1, 0, 0, 0, 0),
			'RESPONSIBLE_LAST_NAME' => 		array(1, 0, 0, 0, 0),
			'RESPONSIBLE_SECOND_NAME' => 	array(1, 0, 0, 0, 0),
			'DATE_START' => 				array(1, 0, 1, 1, 1),
			'DURATION_FACT' => 				array(1, 0, 0, 0, 0),
			'DURATION_PLAN' => 				array(1, 1, 0, 0, 0),
			'DURATION_TYPE' => 				array(1, 1, 0, 0, 0),
			'CREATED_BY_NAME' => 			array(1, 0, 0, 0, 0),
			'CREATED_BY_LAST_NAME' => 		array(1, 0, 0, 0, 0),
			'CREATED_BY_SECOND_NAME' => 	array(1, 0, 0, 0, 0),
			'CREATED_DATE' => 				array(1, 0, 1, 1, 1),
			'CHANGED_BY' => 				array(1, 0, 0, 1, 0),
			'CHANGED_DATE' => 				array(1, 0, 1, 1, 1),
			'STATUS_CHANGED_BY' => 			array(1, 0, 0, 1, 0),
			'STATUS_CHANGED_DATE' => 		array(1, 0, 0, 0, 1),
			'CLOSED_BY' =>					array(1, 0, 0, 0, 0),
			'CLOSED_DATE' => 				array(1, 0, 1, 1, 1),
			'GUID' => 						array(1, 0, 0, 1, 0),
			'MARK' => 						array(1, 1, 1, 1, 0),
			'VIEWED_DATE' => 				array(1, 0, 0, 0, 1),
			'TIME_SPENT_IN_LOGS' => 		array(1, 0, 0, 0, 0),
			'FAVORITE' => 					array(1, 0, 1, 1, 0),
			'ALLOW_TIME_TRACKING' => 		array(1, 1, 1, 1, 0),
			'MATCH_WORK_TIME' => 			array(1, 1, 1, 1, 0),
			'ADD_IN_REPORT' => 				array(1, 1, 0, 1, 0),
			'FORUM_ID' => 					array(1, 0, 0, 0, 0),
			'FORUM_TOPIC_ID' => 			array(1, 0, 0, 1, 0),
			'COMMENTS_COUNT' => 			array(1, 0, 0, 0, 0),
			'SITE_ID' => 					array(1, 1, 0, 1, 0),
			'SUBORDINATE' => 				array(1, 0, 0, 0, 0),
			'FORKED_BY_TEMPLATE_ID' => 		array(1, 0, 0, 0, 0),
			'MULTITASK' => 					array(1, 0, 0, 0, 0),
			'ACCOMPLICE' => 				array(0, 0, 0, 1, 0),
			'AUDITOR' => 					array(0, 0, 0, 1, 0),
			'DOER' => 						array(0, 0, 0, 1, 0),
			'MEMBER' => 					array(0, 0, 0, 1, 0),
			'TAG' => 						array(0, 0, 0, 1, 0),
			'ONLY_ROOT_TASKS' => 			array(0, 0, 0, 1, 0),
		);
	}

	public static function getManifest()
	{
		static $fieldMap;

		if($fieldMap == null)
		{
			$fieldMap = static::getPublicFieldMap();
		}

		static $fieldManifest;

		if($fieldManifest === null)
		{
			foreach($fieldMap as $field => $permissions)
			{
				if($permissions[0]) // read
				{
					$fieldManifest['READ'][] = $field;
				}

				if($permissions[1]) // write
				{
					$fieldManifest['WRITE'][] = $field;
				}

				if($permissions[2]) // sort
				{
					$fieldManifest['SORT'][] = $field;
				}

				if($permissions[3]) // filter
				{
					$fieldManifest['FILTER'][] = $field;
				}

				if($permissions[4]) // filter
				{
					$fieldManifest['DATE'][] = $field;
				}
			}
		}

		return(array(
			'Manifest version' => '2.1',
			'Warning' => 'don\'t rely on format of this manifest, it can be changed without any notification',
			'REST: shortname alias to class'    => 'items',
			'REST: writable task data fields'   =>  $fieldManifest['WRITE'],
			'REST: readable task data fields'   =>  $fieldManifest['READ'],
			'REST: sortable task data fields'   =>  $fieldManifest['SORT'],
			'REST: filterable task data fields' =>  $fieldManifest['FILTER'],
			'REST: date fields' =>  $fieldManifest['DATE'],
			'REST: available methods' => array(
				'getlist' => array(
					'mandatoryParamsCount' => 0,
					'params' => array(
						array(
							'description' => 'arOrder',
							'type'        => 'array'
						),
						array(
							'description' => 'arFilter',
							'type'        => 'array',
							'allowedKeys' =>  $fieldManifest['FILTER'],
							'allowedKeyPrefixes' => array(
								'=', '!=', '%', '!%', '?', '><', 
								'!><', '>=', '>', '<', '<=', '!'
							)
						),
						array(
							'description'   => 'arSelect',
							'type'          => 'array',
							'allowedValues' => $fieldManifest['READ']
						),
						array(
							'description' => 'arParams',
							'type'        => 'array',
							'allowedKeys' =>  array('NAV_PARAMS', 'bGetZombie')
						)
					),
					'allowedKeysInReturnValue' => $fieldManifest['READ'],
					'collectionInReturnValue'  => true
				)
			)
		));
	}

	private static function getSortingOrderBy($asc = true)
	{
		$order = array();
		$direction = $asc ? "ASC" : "DESC";

		$connection = Application::getConnection();
		if ($connection instanceof MysqlCommonConnection)
		{
			$order[] = " ISNULL(SORTING) ".$direction." ";
			$order[] = " SORTING ".$direction." ";
		}
		elseif ($connection instanceof MssqlConnection)
		{
			$order[] = "CASE WHEN SRT.SORT IS".($asc ? "" : " NOT")." NULL THEN 1 ELSE 0 END";
			$order[] = " SRT.SORT ".$direction." ";
		}
		elseif ($connection instanceof OracleConnection)
		{
			$order[] = " SORTING ".$direction." ".($asc ? "NULLS LAST " : "NULLS FIRST " );
		}

		return $order;
	}

	private static function getOrderSql($by, $order, $default_order, $nullable = true)
	{
		global $DBType;

		static $dbtype = null;

		if ($dbtype === null)
			$dbtype = strtolower($DBType);

		switch ($dbtype)
		{
			case 'mysql':
				return (self::getOrderSql_mysql($by, $order, $default_order, $nullable = true));
			break;

			case 'mssql':
				return (self::getOrderSql_mssql($by, $order, $default_order, $nullable = true));
			break;

			case 'oracle':
				return (self::getOrderSql_oracle($by, $order, $default_order, $nullable = true));
			break;

			default:
				CTaskAssert::log('unknown DB type: ' . $dbtype, CTaskAssert::ELL_ERROR);
				return ' ';
			break;
		}
	}


	private static function getOrderSql_mysql($by, $order, $default_order, $nullable = true)
	{
		$o = self::parseOrder($by, $order, $default_order, $nullable);
		//$o[0] - bNullsFirst
		//$o[1] - asc|desc
		if($o[0])
		{
			if($o[1] == "asc")
				return $by." asc";
			else
				return "length(".$by.")>0 asc, ".$by." desc";
		}
		else
		{
			if($o[1] == "asc")
				return "length(".$by.")>0 desc, ".$by." asc";
			else
				return $by." desc";
		}
	}


	private static function getOrderSql_mssql($by, $order, $default_order, $nullable = true)
	{
		static $temp_by = 0;
		$o = self::parseOrder($by, $order, $default_order, $nullable);
		//$o[0] - bNullsFirst
		//$o[1] - asc|desc
		if($o[0])
		{
			if($o[1] == "asc")
				return $by." asc";//
			else
				return array(
					"case when len(".$by.") > 0 then 1 else 0 end",
					"_IS_NULL_".$temp_by,
					"_IS_NULL_".($temp_by++)." asc, ".$by." desc",
				);
		}
		else
		{
			if($o[1] == "asc")
				return array(
					"case when len(".$by.") > 0 then 1 else 0 end",
					"_IS_NULL_".$temp_by,
					"_IS_NULL_".($temp_by++)." desc, ".$by." asc",
				);
			else
				return $by." desc";//
		}
	}


	private static function getOrderSql_oracle($by, $order, $default_order, $nullable = true)
	{
		$o = self::parseOrder($by, $order, $default_order, $nullable);
		//$o[0] - bNullsFirst
		//$o[1] - asc|desc
		if($o[0])
		{
			if($o[1] == "asc")
			{
				if($nullable)
					return $by." asc nulls first";
				else
					return $by." asc";
			}
			else
			{
				return $by." desc";
			}
		}
		else
		{
			if($o[1] == "asc")
			{
				return $by." asc";
			}
			else
			{
				if($nullable)
					return $by." desc nulls last";
				else
				return $by." desc";
			}
		}
	}


	private static function parseOrder($by, $order, $default_order, $nullable = true)
	{
		static $arOrder = array(
			"nulls,asc"  => array(true,  "asc" ),
			"asc,nulls"  => array(false, "asc" ),
			"nulls,desc" => array(true,  "desc"),
			"desc,nulls" => array(false, "desc"),
			"asc"        => array(true,  "asc" ),
			"desc"       => array(false, "desc"),
		);
		$order = strtolower(trim($order));
		if(array_key_exists($order, $arOrder))
			$o = $arOrder[$order];
		elseif(array_key_exists($default_order, $arOrder))
			$o = $arOrder[$default_order];
		else
			$o = $arOrder["desc,nulls"];

		//There is no need to "reverse" nulls order when
		//column can not contain nulls
		if(!$nullable)
		{
			if($o[1] == "asc")
				$o[0] = true;
			else
				$o[0] = false;
		}

		return $o;
	}
}
