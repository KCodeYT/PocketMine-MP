<?php

/*

           -
         /   \
      /         \
   /   PocketMine  \
/          MP         \
|\     @shoghicp     /|
|.   \           /   .|
| ..     \   /     .. |
|    ..    |    ..    |
|       .. | ..       |
\          |          /
   \       |       /
      \    |    /
         \ | /

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Lesser General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.


*/

class PocketMinecraftServer{
	public $tCnt;
	public $version, $invisible, $api, $tickMeasure, $preparedSQL, $seed, $gamemode, $name, $maxClients, $clients, $eidCnt, $custom, $description, $motd, $timePerSecond, $spawn, $entities, $mapDir, $mapName, $map, $levelData, $tileEntities;
	private $serverip, $database, $interface, $evCnt, $handCnt, $events, $eventsID, $handlers, $serverType, $lastTick;
	
	private function load(){
		$this->version = new VersionString();
		@cli_set_process_title("PocketMine-MP ".MAJOR_VERSION);
		if($this->version->isDev()){
			console("[INFO] \x1b[31;1mThis is a Development version");
		}
		console("[INFO] Starting \x1b[36;1m".CURRENT_MINECRAFT_VERSION."\x1b[0m #".CURRENT_PROTOCOL." Minecraft PE Server at ".$this->serverip.":".$this->port);
		if($this->port < 19132 or $this->port > 19135){ //Mojang =(
			console("[WARNING] You've selected a not-standard port. Normal port range is from 19132 to 19135 included");
		}
		$this->serverID = $this->serverID === false ? Utils::readLong(Utils::getRandomBytes(8, false)):$this->serverID;
		$this->seed = $this->seed === false ? Utils::readInt(Utils::getRandomBytes(4, false)):$this->seed;
		$this->startDatabase();
		$this->api = false;
		$this->tCnt = 1;
		$this->mapDir = false;
		$this->mapName = false;
		$this->events = array();
		$this->eventsID = array();
		$this->handlers = array();
		$this->map = false;
		$this->invisible = false;
		$this->levelData = false;
		$this->difficulty = 1;
		$this->tileEntities = array();
		$this->entities = array();
		$this->custom = array();
		$this->evCnt = 1;
		$this->handCnt = 1;
		$this->eidCnt = 1;
		$this->maxClients = 20;
		$this->schedule = array();
		$this->scheduleCnt = 1;
		$this->description = "";
		$this->whitelist = false;
		$this->clients = array();
		$this->spawn = array("x" => 128.5,"y" => 100,"z" =>  128.5);
		$this->time = 0;
		$this->timePerSecond = 20;
		$this->tickMeasure = array_fill(0, 40, 0);
		$this->setType("normal");
		$this->interface = new MinecraftInterface("255.255.255.255", $this->port, true, false);
		$this->reloadConfig();
		$this->stop = false;
	}

	function __construct($name, $gamemode = CREATIVE, $seed = false, $port = 19132, $serverID = false, $serverip = "0.0.0.0"){
		$this->port = (int) $port; //19132 - 19135
		$this->doTick = true;
		$this->gamemode = (int) $gamemode;
		$this->name = $name;
		$this->motd = "Welcome to ".$name;
		$this->serverID = $serverID;
		$this->seed = $seed;
		$this->serverip = $serverip;
		$this->load();
	}

	public function getTPS(){
		$v = array_values($this->tickMeasure);
		$tps = 40 / ($v[39] - $v[0]);
		return round($tps, 4);
	}
	
	public function titleTick(){
		if(ENABLE_ANSI === true){
			echo "\x1b]0;PocketMine-MP ".MAJOR_VERSION." | Online ". count($this->clients)."/".$this->maxClients." | RAM ".round((memory_get_usage() / 1024) / 1024, 2)."MB | TPS ".$this->getTPS()."\x07";
		}
	}

	public function loadEvents(){
		if(ENABLE_ANSI === true){
			$this->action(1500000, '$this->titleTick();');
		}
		$this->action(500000, '$this->time += (int) ($this->timePerSecond / 2);$this->api->dhandle("server.time", $this->time);');
		$this->action(5000000, 'if($this->difficulty < 2){$this->api->dhandle("server.regeneration", 1);}');
		$this->action(1000000 * 15, 'if($this->getTPS() < 15){console("[WARNING] Can\'t keep up! Is the server overloaded?");}');
		$this->action(1000000 * 60 * 10, '$this->custom = array();');
	}

	public function startDatabase(){
		$this->preparedSQL = new stdClass();
		$this->database = new SQLite3(":memory:");
		//$this->query("PRAGMA journal_mode = OFF;");
		//$this->query("PRAGMA encoding = \"UTF-8\";");
		//$this->query("PRAGMA secure_delete = OFF;");
		$this->query("CREATE TABLE players (clientID INTEGER PRIMARY KEY, EID NUMERIC, ip TEXT, port NUMERIC, name TEXT UNIQUE COLLATE NOCASE);");
		$this->query("CREATE TABLE entities (EID INTEGER PRIMARY KEY, type NUMERIC, class NUMERIC, name TEXT, x NUMERIC, y NUMERIC, z NUMERIC, yaw NUMERIC, pitch NUMERIC, health NUMERIC);");
		$this->query("CREATE TABLE tileentities (ID INTEGER PRIMARY KEY, class TEXT, x NUMERIC, y NUMERIC, z NUMERIC, spawnable NUMERIC);");
		$this->query("CREATE TABLE actions (ID INTEGER PRIMARY KEY, interval NUMERIC, last NUMERIC, code TEXT, repeat NUMERIC);");
		$this->query("CREATE TABLE handlers (ID INTEGER PRIMARY KEY, name TEXT, priority NUMERIC);");
		//$this->query("PRAGMA synchronous = OFF;");
		$this->preparedSQL->selectHandlers = $this->database->prepare("SELECT DISTINCT ID FROM handlers WHERE name = :name ORDER BY priority DESC;");
		$this->preparedSQL->selectActions = $this->database->prepare("SELECT ID,code,repeat FROM actions WHERE last <= (:time - interval);");
		$this->preparedSQL->updateActions = $this->database->prepare("UPDATE actions SET last = :time WHERE last <= (:time - interval);");
	}

	public function query($sql, $fetch = false){
		console("[INTERNAL] [SQL] ".$sql, true, true, 3);
		$result = $this->database->query($sql) or console("[ERROR] [SQL Error] ".$this->database->lastErrorMsg().". Query: ".$sql, true, true, 0);
		if($fetch === true and ($result !== false and $result !== true)){
			$result = $result->fetchArray(SQLITE3_ASSOC);
		}
		return $result;
	}

	public function reloadConfig(){

	}

	public function debugInfo($console = false){
		$info = array();
		$info["tps"] = $this->getTPS();
		$info["memory_usage"] = round((memory_get_usage() / 1024) / 1024, 2)."MB";
		$info["memory_peak_usage"] = round((memory_get_peak_usage() / 1024) / 1024, 2)."MB";
		$info["entities"] = $this->query("SELECT count(EID) as count FROM entities;", true);
		$info["entities"] = $info["entities"]["count"];
		$info["events"] = count($this->eventsID);
		$info["handlers"] = $this->query("SELECT count(ID) as count FROM handlers;", true);
		$info["handlers"] = $info["handlers"]["count"];
		$info["actions"] = $this->query("SELECT count(ID) as count FROM actions;", true);
		$info["actions"] = $info["actions"]["count"];
		$info["garbage"] = gc_collect_cycles();
		$this->handle("server.debug", $info);
		if($console === true){
			console("[DEBUG] TPS: ".$info["tps"].", Memory usage: ".$info["memory_usage"]." (Peak ".$info["memory_peak_usage"]."), Entities: ".$info["entities"].", Events: ".$info["events"].", Handlers: ".$info["handlers"].", Actions: ".$info["actions"].", Garbage: ".$info["garbage"], true, true, 2);
		}
		return $info;
	}

	public function close($reason = "stop"){
		if($this->stop !== true){
			if(is_int($reason)){
				$reason = "signal stop";
			}
			if(($this->api instanceof ServerAPI) === true){
				if(($this->api->chat instanceof ChatAPI) === true){
					$this->api->chat->broadcast("Stopping server...");
				}
			}
			$this->save(true);
			$this->stop = true;
			$this->trigger("server.close", $reason);
			$this->interface->close();
		}
	}

	public function setType($type = "normal"){
		switch(trim(strtolower($type))){
			case "normal":
			case "demo":
				$this->serverType = "MCCPP;Demo;";
				break;
			case "minecon":
				$this->serverType = "MCCPP;MINECON;";
				break;
		}

	}

	public function addHandler($event,callable $callable, $priority = 5){
		if(!is_callable($callable)){
			return false;
		}elseif(isset(Deprecation::$events[$event])){
			$sub = "";
			if(Deprecation::$events[$event] !== false){
				$sub = " Substitute \"".Deprecation::$events[$event]."\" found.";
			}
			console("[ERROR] Event \"$event\" has been deprecated.$sub [Adding handle to ".(is_array($callable) ? get_class($callable[0])."::".$callable[1]:$callable)."]");
		}
		$priority = (int) $priority;
		$hnid = $this->handCnt++;
		$this->handlers[$hnid] = $callable;
		$this->query("INSERT INTO handlers (ID, name, priority) VALUES (".$hnid.", '".str_replace("'", "\\'", $event)."', ".$priority.");");
		console("[INTERNAL] New handler ".(is_array($callable) ? get_class($callable[0])."::".$callable[1]:$callable)." to special event ".$event." (ID ".$hnid.")", true, true, 3);
		return $hnid;
	}

	public function handle($event, &$data){
		$this->preparedSQL->selectHandlers->reset();
		$this->preparedSQL->selectHandlers->clear();
		$this->preparedSQL->selectHandlers->bindValue(":name", $event, SQLITE3_TEXT);
		$handlers = $this->preparedSQL->selectHandlers->execute();
		$result = null;
		if($handlers !== false and $handlers !== true){
			$call = array();
			while(($hn = $handlers->fetchArray(SQLITE3_ASSOC)) !== false){
				$call[(int) $hn["ID"]] = true;
			}
			$handlers->finalize();
			foreach($call as $hnid => $boolean){
				if($result !== false and $result !== true){
					$called[$hnid] = true;
					$handler = $this->handlers[$hnid];
					if(is_array($handler)){
						$method = $handler[1];
						$result = $handler[0]->$method($data, $event);
					}else{
						$result = $handler($data, $event);
					}
				}else{
					break;
				}
			}
		}elseif(isset(Deprecation::$events[$event])){
			$sub = "";
			if(Deprecation::$events[$event] !== false){
				$sub = " Substitute \"".Deprecation::$events[$event]."\" found.";
			}
			console("[ERROR] Event \"$event\" has been deprecated.$sub [Handler]");
		}

		if($result !== false){
			$this->trigger($event, $data);
		}
		return $result;
	}

	public function eventHandler($data, $event){
		switch($event){

		}
	}

	public function loadMap(){
		if($this->mapName !== false and trim($this->mapName) !== ""){
			$this->levelData = unserialize(file_get_contents($this->mapDir."level.dat"));
			if($this->levelData === false){
				console("[ERROR] Invalid world data for \"".$this->mapDir."\. Please import the world correctly");
				$this->close("invalid world data");
			}
			$this->time = (int) $this->levelData["Time"];
			$this->seed = (int) $this->levelData["RandomSeed"];
			if(isset($this->levelData["SpawnX"])){
				$this->spawn = array("x" => $this->levelData["SpawnX"], "y" => $this->levelData["SpawnY"], "z" => $this->levelData["SpawnZ"]);
			}else{
				$this->levelData["SpawnX"] = $this->spawn["x"];
				$this->levelData["SpawnY"] = $this->spawn["y"];
				$this->levelData["SpawnZ"] = $this->spawn["z"];
			}
			$this->levelData["Time"] = $this->time;
			console("[INFO] Preparing level \"".$this->levelData["LevelName"]."\"");
			$this->map = new ChunkParser();
			if(!$this->map->loadFile($this->mapDir."chunks.dat")){
				console("[ERROR] Couldn't load the map \"\x1b[32m".$this->levelData["LevelName"]."\x1b[0m\"!", true, true, 0);
				$this->map = false;
			}else{
				$this->map->loadMap();
			}
		}
	}

	public function getGamemode(){
		switch($this->gamemode){
			case SURVIVAL:
				return "survival";
			case CREATIVE:
				return "creative";
			case ADVENTURE:
				return "adventure";
		}
	}

	public function loadEntities(){
		if($this->map !== false){
			$entities = unserialize(file_get_contents($this->mapDir."entities.dat"));
			if($entities === false or !is_array($entities)){
				console("[ERROR] Invalid world data for \"".$this->mapDir."\. Please import the world correctly");
				$this->close("invalid world data");
			}
			foreach($entities as $entity){
				if(!isset($entity["id"])){
					break;
				}
				if(isset($this->api) and $this->api !== false){
					if($entity["id"] === 64){ //Item Drop
						$e = $this->api->entity->add(ENTITY_ITEM, $entity["Item"]["id"], array(
							"meta" => $entity["Item"]["Damage"],
							"stack" => $entity["Item"]["Count"],
							"x" => $entity["Pos"][0],
							"y" => $entity["Pos"][1],
							"z" => $entity["Pos"][2],
							"yaw" => $entity["Rotation"][0],
							"pitch" => $entity["Rotation"][1],
						));
					}elseif($entity["id"] === OBJECT_PAINTING){ //Painting
						$e = $this->api->entity->add(ENTITY_OBJECT, $entity["id"], $entity);
						$e->setPosition($entity["Pos"][0], $entity["Pos"][1], $entity["Pos"][2], $entity["Rotation"][0], $entity["Rotation"][1]);
						$e->setHealth($entity["Health"]);
					}else{
						$e = $this->api->entity->add(ENTITY_MOB, $entity["id"], $entity);
						$e->setPosition($entity["Pos"][0], $entity["Pos"][1], $entity["Pos"][2], $entity["Rotation"][0], $entity["Rotation"][1]);
						$e->setHealth($entity["Health"]);
					}
				}
			}
			$tiles = unserialize(file_get_contents($this->mapDir."tileEntities.dat"));
			foreach($tiles as $tile){
				if(!isset($tile["id"])){
					break;
				}
				$t = $this->api->tileentity->add($tile["id"], $tile["x"], $tile["y"], $tile["z"], $tile);
			}
			$this->action(1000000 * 60 * 25, '$this->api->chat->broadcast("Forcing save...");$this->save();');
		}
	}

	public function save($final = false){
		if($this->mapName !== false){
			$this->levelData["Time"] = $this->time;
			file_put_contents($this->mapDir."level.dat", serialize($this->levelData));
			$this->map->saveMap($final);
			$this->trigger("server.save", $final);
			if(count($this->entities) > 0){
				$entities = array();
				foreach($this->entities as $entity){
					if($entity->class === ENTITY_MOB){
						$entities[] = array(
							"id" => $entity->type,
							"Color" => @$entity->data["Color"],
							"Sheared" => @$entity->data["Sheared"],
							"Health" => $entity->health,
							"Pos" => array(
								0 => $entity->x,
								1 => $entity->y,
								2 => $entity->z,
							),
							"Rotation" => array(
								0 => $entity->yaw,
								1 => $entity->pitch,
							),
						);
					}elseif($entity->class === ENTITY_OBJECT){
						$entities[] = array(
							"id" => $entity->type,
							"TileX" => $entity->x,
							"TileX" => $entity->y,
							"TileX" => $entity->z,
							"Health" => $entity->health,
							"Motive" => $entity->data["Motive"],
							"Pos" => array(
								0 => $entity->x,
								1 => $entity->y,
								2 => $entity->z,
							),
							"Rotation" => array(
								0 => $entity->yaw,
								1 => $entity->pitch,
							),
						);
					}elseif($entity->class === ENTITY_ITEM){
						$entities[] = array(
							"id" => 64,
							"Item" => array(
								"id" => $entity->type,
								"Damage" => $entity->meta,
								"Count" => $entity->stack,
							),
							"Health" => $entity->health,
							"Pos" => array(
								0 => $entity->x,
								1 => $entity->y,
								2 => $entity->z,
							),
							"Rotation" => array(
								0 => 0,
								1 => 0,
							),
						);
					}
				}
				file_put_contents($this->mapDir."entities.dat", serialize($entities));
			}
			if(count($this->tileEntities) > 0){
				$tiles = array();
				foreach($this->tileEntities as $tile){
					$tiles[] = $tile->data;
				}
				file_put_contents($this->mapDir."tileEntities.dat", serialize($tiles));
			}
		}
	}

	public function init(){
		if($this->mapName !== false and $this->map === false){
			$this->loadMap();
			$this->loadEntities();
		}
		$this->loadEvents();
		declare(ticks=40);
		register_tick_function(array($this, "tick"));
		register_shutdown_function(array($this, "dumpError"));
		register_shutdown_function(array($this, "close"));
		if(function_exists("pcntl_signal")){
			pcntl_signal(SIGTERM, array($this, "close"));
			pcntl_signal(SIGINT, array($this, "close"));
			pcntl_signal(SIGHUP, array($this, "close"));
		}
		console("[INFO] Default game type: ".strtoupper($this->getGamemode()));
		$this->trigger("server.start", microtime(true));
		console('[INFO] Done ('.round(microtime(true) - START_TIME, 3).'s)! For help, type "help" or "?"');
		$this->process();
	}

	public function dumpError(){
		if($this->stop === true){
			return;
		}
		console("[ERROR] An Unrecovereable has ocurred and the server has Crashed. Creating an Error Dump");
		$dump = "# PocketMine-MP Error Dump ".date("D M j H:i:s T Y")."\r\n";
		$er = error_get_last();
		$dump .= "Error: ".var_export($er, true)."\r\n\r\n";
		$dump .= "Code: \r\n";
		$file = file($er["file"], FILE_IGNORE_NEW_LINES);
		for($l = max(0, $er["line"] - 10); $l < $er["line"] + 10; ++$l){
			$dump .= "[".($l + 1)."] ".$file[$l]."\r\n";
		}
		$dump .= "\r\n\r\n";
		$version = new VersionString();
		$dump .= "PM Version: ".$version." #".$version->getNumber()." [Protocol ".CURRENT_PROTOCOL."]\r\n";
		$dump .= "uname -a: ".php_uname("a")."\r\n";
		$dump .= "PHP Version: " .phpversion()."\r\n";
		$dump .= "Zend version: ".zend_version()."\r\n";
		$dump .= "OS : " .PHP_OS.", ".Utils::getOS()."\r\n";
		$dump .= "Debug Info: ".var_export($this->debugInfo(false), true)."\r\n\r\n\r\n";
		global $arguments;
		$dump .= "Parameters: ".var_export($arguments, true)."\r\n\r\n\r\n";
		$dump .= "server.properties: ".var_export($this->api->getProperties(), true)."\r\n\r\n\r\n";
		if($this->api->plugin instanceof PluginAPI){
			$dump .= "Loaded plugins: ".var_export($this->api->plugin->getList(), true)."\r\n\r\n\r\n";
		}
		$dump .= "Loaded Modules: ".var_export(get_loaded_extensions(), true)."\r\n\r\n";
		$name = "error_dump_".time();
		logg($dump, $name, true, 0, true);
		console("[ERROR] Please submit the \"logs/{$name}.log\" file to the Bug Reporting page. Give as much info as you can.", true, true, 0);
	}

	public function tick(){
		$time = microtime(true);
		if($this->lastTick <= ($time - 0.05)){
			array_shift($this->tickMeasure);
			$this->tickMeasure[] = $this->lastTick = $time;
			$this->tickerFunction($time);
			$this->trigger("server.tick", $time);
		}
	}

	public function clientID($ip, $port){
		return md5($ip . $port, true) ^ sha1($port . $ip, true);
	}

	public function packetHandler($packet){
		$data =& $packet["data"];
		$CID = $this->clientID($packet["ip"], $packet["port"]);
		if(isset($this->clients[$CID])){
			$this->clients[$CID]->handle($packet["pid"], $data);
		}else{
			if($this->handle("server.noauthpacket", $packet) === false){
				return;
			}
			switch($packet["pid"]){
				case 0x02:
					if($this->invisible === true){
						$this->send(0x1c, array(
							$data[0],
							$this->serverID,
							RAKNET_MAGIC,
							$this->serverType,
						), false, $packet["ip"], $packet["port"]);
						break;
					}
					if(!isset($this->custom["times_".$CID])){
						$this->custom["times_".$CID] = 0;
					}
					$ln = 15;
					if($this->description == "" or substr($this->description, -1) != " "){						
						$this->description .= " ";
					}
					$txt = substr($this->description, $this->custom["times_".$CID], $ln);
					$txt .= substr($this->description, 0, $ln - strlen($txt));
					$this->send(0x1c, array(
						$data[0],
						$this->serverID,
						RAKNET_MAGIC,
						$this->serverType. $this->name . " [".($this->gamemode === CREATIVE ? "C":($this->gamemode === ADVENTURE ? "A":"S")).($this->whitelist !== false ? "W":"")." ".count($this->clients)."/".$this->maxClients."] ".$txt,
					), false, $packet["ip"], $packet["port"]);
					$this->custom["times_".$CID] = ($this->custom["times_".$CID] + 1) % strlen($this->description);
					break;
				case 0x05:
					$version = $data[1];
					$size = strlen($data[2]);
					if($version !== CURRENT_STRUCTURE){
						console("[DEBUG] Incorrect structure #$version from ".$packet["ip"].":".$packet["port"], true, true, 2);
						$this->send(0x1a, array(
							CURRENT_STRUCTURE,
							RAKNET_MAGIC,
							$this->serverID,
						), false, $packet["ip"], $packet["port"]);
					}else{
						$this->send(0x06, array(
							RAKNET_MAGIC,
							$this->serverID,
							0,
							strlen($packet["raw"]),
						), false, $packet["ip"], $packet["port"]);
					}
					break;
				case 0x07:
					if($this->invisible === true){
						break;
					}
					$port = $data[2];
					$MTU = $data[3];
					$clientID = $data[4];
					$this->clients[$CID] = new Player($clientID, $packet["ip"], $packet["port"], $MTU); //New Session!
					$this->clients[$CID]->handle(0x07, $data);
					break;
			}
		}
	}

	public function send($pid, $data = array(), $raw = false, $dest = false, $port = false){
		$this->interface->writePacket($pid, $data, $raw, $dest, $port);
	}

	public function process(){
		while($this->stop === false){
			$packet = $this->interface->readPacket();
			if($packet !== false){
				$this->packetHandler($packet);
			}else{
				usleep(1);
			}
		}
	}

	public function trigger($event, $data = ""){
		if(isset($this->events[$event])){
			foreach($this->events[$event] as $evid => $ev){
				if(!is_callable($ev)){
					$this->deleteEvent($evid);
					continue;
				}
				if(is_array($ev)){
					$method = $ev[1];
					$ev[0]->$method($data, $event);
				}else{
					$ev($data, $event);
				}
			}
		}elseif(isset(Deprecation::$events[$event])){
			$sub = "";
			if(Deprecation::$events[$event] !== false){
				$sub = " Substitute \"".Deprecation::$events[$event]."\" found.";
			}
			console("[ERROR] Event \"$event\" has been deprecated.$sub [Trigger]");
		}
	}

	public function schedule($ticks,callable $callback, $data = array(), $repeat = false, $eventName = "server.schedule"){
		if(!is_callable($callback)){
			return false;
		}
		$add = "";
		$chcnt = $this->scheduleCnt++;
		if($repeat === false){
			$add = '$this->schedule['.$chcnt.']=null;unset($this->schedule['.$chcnt.']);';
		}
		$this->schedule[$chcnt] = array($callback, $data, $eventName);
		$this->action(50000 * $ticks, '$schedule=$this->schedule['.$chcnt.'];'.$add.'if(!is_callable($schedule[0])){$this->schedule['.$chcnt.']=null;unset($this->schedule['.$chcnt.']);return false;}return call_user_func($schedule[0],$schedule[1],$schedule[2]);', (bool) $repeat);
		return $chcnt;
	}

	public function action($microseconds, $code, $repeat = true){
		$this->query("INSERT INTO actions (interval, last, code, repeat) VALUES(".($microseconds / 1000000).", ".microtime(true).", '".base64_encode($code)."', ".($repeat === true ? 1:0).");");
	}

	public function tickerFunction($time){
		//actions that repeat every x time will go here
		$this->preparedSQL->selectActions->reset();
		$this->preparedSQL->selectActions->clear();
		$this->preparedSQL->selectActions->bindValue(":time", $time, SQLITE3_FLOAT);
		$actions = $this->preparedSQL->selectActions->execute();

		if($actions === false or $actions === true){
			return;
		}
		while(($action = $actions->fetchArray(SQLITE3_ASSOC)) !== false){
			$return = eval(base64_decode($action["code"]));
			if($action["repeat"] === 0 or $return === false){
				$this->query("DELETE FROM actions WHERE ID = ".$action["ID"].";");
			}
		}
		$actions->finalize();
		$this->preparedSQL->updateActions->reset();
		$this->preparedSQL->updateActions->clear();
		$this->preparedSQL->updateActions->bindValue(":time", $time, SQLITE3_FLOAT);
		$this->preparedSQL->updateActions->execute();
	}

	public function event($event,callable $func){
		if(!is_callable($func)){
			return false;
		}elseif(isset(Deprecation::$events[$event])){
			$sub = "";
			if(Deprecation::$events[$event] !== false){
				$sub = " Substitute \"".Deprecation::$events[$event]."\" found.";
			}
			console("[ERROR] Event \"$event\" has been deprecated.$sub [Attach to ".(is_array($func) ? get_class($func[0])."::".$func[1]:$func)."]");
		}
		$evid = $this->evCnt++;
		if(!isset($this->events[$event])){
			$this->events[$event] = array();
		}
		$this->events[$event][$evid] = $func;
		$this->eventsID[$evid] = $event;
		console("[INTERNAL] Attached ".(is_array($func) ? get_class($func[0])."::".$func[1]:$func)." to event ".$event." (ID ".$evid.")", true, true, 3);
		return $evid;
	}

	public function deleteEvent($id){
		$id = (int) $id;
		if(isset($this->eventsID[$id])){
			$ev = $this->eventsID[$id];
			$this->eventsID[$id] = null;
			unset($this->eventsID[$id]);
			$this->events[$ev][$id] = null;
			unset($this->events[$ev][$id]);
			if(count($this->events[$ev]) === 0){
				unset($this->events[$ev]);
			}
		}
	}

}
