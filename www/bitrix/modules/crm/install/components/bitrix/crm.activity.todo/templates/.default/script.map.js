{"version":3,"file":"script.min.js","sources":["script.js"],"names":["BX","CrmActivityTodo","settings","this","_ccontainer","ccontainer","_citem","citem","_clink","clink","_ccheck","ccheck","_cbuttoncancel","cbuttoncancel","_ccheckprefix","ccheckprefix","_ajaxPath","ajax_path","_ajaxPlannerPath","ajax_planner_path","message","_dialogId","_popup","_activityId","activityLink","findChild","class","i","length","bind","delegate","_clickTitleHandler","activityCheck","_clickCheckHandler","prototype","_getParent","proxy","findParent","_showPopup","title","events","PopupWindow","window","body","offsetLeft","lightShadow","closeIcon","titleBar","content","create","html","draggable","closeByEsc","contentColor","overlay","backgroundColor","opacity","setContent","setTitleBar","show","_loadActivity","_this","ajax","post","sessid","bitrix_sessid","ajax_action","activity_id","data","adjustPosition","additionalSwitcher","_getNodeByRole","additionalFields","fieldCompleted","toggleClass","checked","disabled","fireEvent","setButtons","PopupWindowButtonLink","text","className","click","popupWindow","close","container","name","querySelector","e","proxy_context","view","onAfterPopupShow","PreventDefault","context","parent","loadJSON","action","id","ownerid","ownertypeid","completed","error","alert","onCustomEvent","addClass","_self"],"mappings":"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"}