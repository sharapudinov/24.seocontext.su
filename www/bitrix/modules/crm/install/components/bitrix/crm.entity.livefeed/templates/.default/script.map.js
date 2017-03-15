{"version":3,"file":"script.min.js","sources":["script.js"],"names":["BX","CrmEntityLiveFeed","this","_settings","_id","_prefix","_menuContainer","_addMessageButton","_addCallButton","_addMeetingButton","_addEmailButton","_activityEditor","_eventEditor","_enableTaskProcessing","_activities","prototype","initialize","id","settings","getSetting","_resolveElement","bind","delegate","_onAddMessageButtonClick","_onAddCallButtonClick","_onAddMeetingButtonClick","_onAddEmailButtonClick","_addTaskButton","_onAddTaskButtonClick","eventEditorId","type","isNotEmptyString","CrmSonetEventEditor","items","activityEditorId","CrmActivityEditor","addActivityChangeHandler","_onActivityChange","namespace","Crm","Activity","Planner","Manager","setCallback","form","window","ReloadActiveTab","location","reload","name","defaultVal","hasOwnProperty","setSetting","val","setActivityCompleted","activityId","completed","elementId","e","showEditor","showEdit","TYPE_ID","CrmActivityType","call","OWNER_TYPE","OWNER_ID","addCall","meeting","addMeeting","addEmail","addTask","sender","action","origin","typeId","parseInt","undefined","task","create","self"],"mappings":"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"}