<?
$MESS["PULL_TAB_SETTINGS"] = "Einstellungen";
$MESS["PULL_TAB_TITLE_SETTINGS"] = "Moduleinstellungen";
$MESS["PULL_OPTIONS_PATH_TO_LISTENER"] = "Pfad zum Nachrichten-Beobachter (HTTP)";
$MESS["PULL_OPTIONS_PATH_TO_LISTENER_SECURE"] = "Pfad zum Nachrichten-Beobachter (HTTPS)";
$MESS["PULL_OPTIONS_PATH_TO_MOBILE_LISTENER"] = "Pfad zum Lesen von Nachrichten in der Mobilen App (HTTP)";
$MESS["PULL_OPTIONS_PATH_TO_MOBILE_LISTENER_SECURE"] = "Pfad zum Lesen von Nachrichten in der Mobilen App (HTTPS)";
$MESS["PULL_OPTIONS_PATH_TO_WEBSOCKET"] = "Pfad zum Nachrichten-Beobachter via WebSocket (HTTP)";
$MESS["PULL_OPTIONS_PATH_TO_WEBSOCKET_SECURE"] = "Pfad zum Nachrichten-Beobachter via WebSocket (HTTPS)";
$MESS["PULL_OPTIONS_PATH_TO_PUBLISH"] = "Pfad zum Nachrichtensender";
$MESS["PULL_OPTIONS_PUSH"] = "PUSH-Benachrichtigungen an mobile Geräte senden";
$MESS["PULL_OPTIONS_WEBSOCKET"] = "WebSocket aktivieren";
$MESS["PULL_OPTIONS_NGINX"] = "nginx-push-stream-module ist installiert";
$MESS["PULL_OPTIONS_NGINX_CONFIRM"] = "Achtung: Sie müssen das Modul nginx-push-stream-module installieren, bevor Sie diese Option nutzen werden.";
$MESS["PULL_OPTIONS_WS_CONFIRM"] = "Achtung: Sie müssen sicherstellen, dass das Modul nginx-push-stream-module WebSocket unterstützt, bevor Sie die Option nutzen werden.";
$MESS["PULL_OPTIONS_NGINX_DOC"] = "Näheres über Installation und Nutzung von nginx-push-stream-module finden Sie hier:";
$MESS["PULL_OPTIONS_NGINX_DOC_LINK"] = "Online-Hilfe";
$MESS["PULL_OPTIONS_STATUS"] = "Modulstatus";
$MESS["PULL_OPTIONS_STATUS_Y"] = "Aktiv";
$MESS["PULL_OPTIONS_STATUS_N"] = "Nicht aktiv";
$MESS["PULL_OPTIONS_USE"] = "Module genutzt";
$MESS["PULL_OPTIONS_SITES"] = "Das Modul nicht auf der Website nutzen";
$MESS["PULL_OPTIONS_PATH_TO_LISTENER_DESC"] = "Es wird empfohlen, einen Standardport für HTTP oder HTTPS zu benutzen.<br>Benutzen Sie 8893 (HTTP) oder 8894 (HTTPS) nur für die Version 0.3.4 des Moduls nginx-push-stream-module.";
$MESS["PULL_OPTIONS_PATH_TO_MOBILE_LISTENER_DESC"] = "Für mobile Anwendungen benutzen Sie immer nicht-Standardports (z.B.: 8893 für HTTP, oder 8894 für HTTPS), weil nicht alle mobile Geräte Long Pooling auf einem Standardport unterstützen.";
$MESS["PULL_OPTIONS_WEBSOCKET_DESC"] = "Diese Konfiguration ist für alle modernen Browser. Für ältere Versionen wird das Long Pooling verwendet.";
$MESS["PULL_OPTIONS_NGINX_VERSION"] = "Server Software";
$MESS["PULL_OPTIONS_NGINX_VERSION_034"] = "Bitrix Virtual Appliance 4.2 - 4.3 (nginx-push-stream-module 0.3.4)";
$MESS["PULL_OPTIONS_NGINX_VERSION_040"] = "Bitrix Virtual Appliance ab Version 4.4 (nginx-push-stream-module 0.4.0)";
$MESS["PULL_OPTIONS_NGINX_VERSION_034_DESC"] = "nginx-push-stream-module 0.4.0 wird ausdrücklich empfohlen, bitte installieren Sie das so schnell wie möglich.<br>Wenn Sie nginx-push-stream-module 0.3.4 benutzen, werden die WebSocket sowie der Befehlsversand nicht verfügbar sein.";
$MESS["PULL_OPTIONS_NGINX_BUFFER"] = "Maximale Anzahl der Befehle zum Versenden, wenn die Verbindung zum Server hergestellt wird.";
$MESS["PULL_OPTIONS_NGINX_BUFFERS_DESC"] = "Diese Option hängt vom Parameter \"large_client_header_buffers\" des nginx's-Servers ab. Der Standardwert ist <b>8k</b>.";
$MESS["PULL_OPTIONS_PATH_TO_LISTENER_MODERN_DESC"] = "Beachten Sie, dass moderne Browser die Verbindung zum Remote Push-Server sogar mit einer anderen Domain herstellen können (CORS-Anfragen).";
$MESS["PULL_OPTIONS_HEAD_PUB"] = "URL zum Versenden der Befehle";
$MESS["PULL_OPTIONS_HEAD_SUB_MODERN"] = "URL zum Lesen der Befehle für moderne Browser";
$MESS["PULL_OPTIONS_HEAD_SUB"] = "URL zum Lesen der Befehle für veraltete Browser";
$MESS["PULL_OPTIONS_HEAD_SUB_MOB"] = "URL zum Lesen der Befehle für mobile Browser";
$MESS["PULL_OPTIONS_HEAD_SUB_WS"] = "URL zum Lesen der Befehle für Browser mit WebSocket";
$MESS["PULL_OPTIONS_HEAD_BLOCK"] = "Websites-Ausnahmen";
$MESS["PULL_OPTIONS_GUEST"] = "Modul für anonyme Nutzer aktivieren";
$MESS["PULL_OPTIONS_GUEST_DESC"] = "Nutzerinformationen werden vom Modul Web-Statistik gesammelt und bereitgestellt";
?>