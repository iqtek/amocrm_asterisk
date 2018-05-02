<?php
/*
	amoCRM to  asterisk integration.
	QSOFT LLC,  All rights reserved.
	mailto:      support@amocrm.com.
	Date:   10.04.2012   rev: 102703
	Cannot be redistributed  without
	     a written permission.
                         _____ _____  __  __
                        / ____|  __ \|  \/  |
   __ _ _ __ ___   ___ | |    | |__) | \  / |
  / _` | '_ ` _ \ / _ \| |    |  _  /| |\/| |
 | (_| | | | | | | (_) | |____| | \ \| |  | |_
  \__,_|_| |_| |_|\___/ \_____|_|  \_\_|  |_(_)



 */
ini_set('display_errors',0);
define('AC_HOST','localhost');
define('AC_PORT',8088);
define('AC_PREFIX','/asterisk/');
define('AC_TLS',false);
define('AC_DB_CS','mysql:host=localhost;port=3306;dbname=asteriskcdrdb');
define('AC_DB_UNAME','freepbx');
define('AC_DB_UPASS','fpbx');
define('AC_TIMEOUT',0.75);
define('AC_RECORD_PATH','https://pbx.v.qsoft.ru/monitor/%Y/%m/%d/#');
define('AC_TIME_DELTA',4); // hours. Ex. GMT+4 = 4


$db_cs=AC_DB_CS;
$db_u=!strlen(AC_DB_UNAME)?NULL:AC_DB_UNAME;
$db_p=!strlen(AC_DB_UPASS)?NULL:AC_DB_UPASS;
date_default_timezone_set('UTC');


if (AC_PORT<1) die('Please, configure settings first!'); // die if not
if (defined('AC_RECORD_PATH') AND !empty($_GET['GETFILE'])){
	//get file. Do not check auth. (uniqueid is rather unique)
	$p=AC_RECORD_PATH;
	if (empty($p)) die('Error while getting file from asterisk');
	try {
		$dbh = new PDO($db_cs, $db_u, $db_p);
		$sth = $dbh->prepare('SELECT calldate,recordingfile FROM cdr WHERE uniqueid= :uid');
		$sth->bindValue(':uid',strval($_GET['GETFILE']));
		$sth->execute();
		$r = $sth->fetch(PDO::FETCH_ASSOC);
		if ($r===false OR empty($r['recordingfile'])) die('Error while getting file from asterisk');
		$date=strtotime($r['calldate']);
		$replace=array();
		$replace['#']=$r['recordingfile'];
		$dates=array('d','m','Y','y');
		foreach ($dates as $d) $replace['%'.$d]=date($d,$date); // not a good idea!
		$p=str_replace(array_keys($replace),array_values($replace),$p);
		if (empty($_GET['noredirect'])) header('Location: '.$p);
		die($p);
	} catch (PDOException $e) {
		die('Error while getting file from asterisk');
	}
}


// filter parameters from _GET
foreach (array('login','secret','action') as $k){
	if (empty($_GET['_'.$k])) die('NO_PARAMS');
	$$k=strval($_GET['_'.$k]);
}
// trying to check accacess
$loginArr=array(
	'Action'=>'Login',
	'username'=>$login,
	'secret'=>$secret,
//	'Events'=>'off',
);
$resp=asterisk_req($loginArr,true);
// problems? exiting
if ($resp[0]['response']!=='Success') answer(array('status'=>'error','data'=>$resp[0]));

//auth OK. Lets perform actions
if ($action==='status'){ // list channels status
	$params=array( 'action'=>'status');
	$resp=asterisk_req($params);
	// report error of any
	if ($resp[0]['response']!=='Success') answer(array('status'=>'error','data'=>$resp[0]));
	// first an last chunks are useless
	unset($resp[end(array_keys($resp))],$resp[0]);
	// renumber keys for JSON
	$resp=array_values($resp);
	// report OK
	answer(array('status'=>'ok','action'=>$action,'data'=>$resp));

}elseif ($action==='call'){ // originate a call
	$params=array(
		'action'=>'Originate',
		'channel'=>'SIP/'.intval($_GET['from']),
		'Exten'=>strval($_GET['to']),
		'Context'=>'from-internal',
		'priority'=>'2',
		'Callerid'=>'"'.strval($_GET['as']).'" <'.intval($_GET['from']).'>',
		'Async'=>'Yes',
		// Not Implemented:
		//'Callernumber'=>'150',
		//'CallerIDName'=>'155',
	);
	$resp=asterisk_req($params,true);
	if ($resp[0]['response']!=='Success') answer(array('status'=>'error','data'=>$resp[0]));
	answer(array('status'=>'ok','action'=>$action,'data'=>$resp[0]));

} elseif ($action==='test_cdr'){ // test if DB connection params are OK.
	if (!class_exists('PDO')) answer(array('status'=>'error','data'=>'PDO_NOT_INSTALLED')); // we use PDO for accessing mySQL pgSQL sqlite within same algorythm
	try {
		$dbh = new PDO($db_cs, $db_u, $db_p);
	} catch (PDOException $e) {
		answer(array('status'=>'error','data'=>$e->getMessage()));
	}
	answer(array('status'=>'ok','data'=>'connection ok'));
} elseif ($action==='cdr'){ // fetch call history
	try {
		$dbh = new PDO($db_cs, $db_u, $db_p);

		foreach (array('date_from','date_to') as $k){
			$v=doubleval( (!empty($_GET[$k]))?intval($_GET[$k]):0 );
			if ($v<0) $v=time()-$v;
			$$k=$v;
		}
		if ($date_from<time()-10*24*3600) $date_from=time()-7*24*3600; //retr. not more than 10d before
		$date_from=($date_from?$date_from+AC_TIME_DELTA*3600:0); //default 01-01-1970
		$date_to  =($date_to  ?$date_to  +AC_TIME_DELTA*3600:time()+AC_TIME_DELTA*3600);//default now()
		$sth = $dbh->prepare('SELECT calldate, src,dst,duration,billsec,uniqueid,recordingfile FROM cdr WHERE disposition=\'ANSWERED\' AND billsec>=:minsec AND calldate> :from AND calldate< :to');
		// BETWEEN is illegal on some bcknds
		header("X-REAL_DATE:" . gmdate('Y-m-d H:i:s',$date_from).'@'. gmdate('Y-m-d H:i:s',$date_to));
		$sth->bindValue(':from', date('Y-m-d H:i:s',$date_from) );
		$sth->bindValue(':to',	 date('Y-m-d H:i:s',$date_to));
		$sth->bindValue(':minsec',!empty($_GET['minsec'])?$_GET['minsec']:5,PDO::PARAM_INT);
		$sth->execute();
		//$sth->debugDumpParams(); 	var_dump($sth->errorInfo());
		$r = $sth->fetchAll(PDO::FETCH_ASSOC);
		foreach ($r as $k=>$v) $r[$k]['calldate']=date('Y-m-d H:i:s',strtotime($v['calldate'])-AC_TIME_DELTA*3600);
		answer(array('status'=>'ok','data'=>$r),true);
	} catch (PDOException $e) {
		answer(array('status'=>'error','data'=>$e->getMessage()),true);
	}
} elseif ($action==='pop'){// fill test data. Maybe you will need it. Just comment line below.
	die();
	$dbh = new PDO($db_cs, $db_u, $db_p);
	for ($i=0;$i<(int)$_GET['n'];$i++){
		$array=array(
			date('Y-m-d H:i:s',time()-rand(100,7*24*3600)),
			'Auto <150>', 150,'791612345678','n/a','n/a','n/a','n/a','n/a',999, rand(7,999), 'ANSWERED',3,'',uniqid(),'','',''
		);
		$str=array();
		foreach ($array as  $v) $str[]="'{$v}'";
		$str=implode(', ',$str);
		$dbh->query("INSERT INTO cdr VALUES ({$str});");
	}
}

/** MakeRequest to asterisk interfacees
 * @param $params -- array of req. params
 * @return array -- response
 */
function asterisk_req($params,$quick=false){
	// lets decide if use AJAM or AMI
	return !defined('AC_PREFIX')?ami_req($params,$quick):ajam_req($params);
}

/**
 * Shudown function. Gently close the socket
 */
function asterisk_socket_shutdown(){ami_req(NULL);}

/*** Make request with AMI
 * @param $params -- array of req. params
 * @param bool $quick -- if we need more than action result
 * @return array result of req
 */
function ami_req($params,$quick=false){
	static $connection;
	if ($params===NULL and $connection!==NULL) {
		// close connection
		fclose($connection);
		return;
	}
	if ($connection===NULL){
		$en=$es='';
		$connection = fsockopen(AC_HOST, AC_PORT, $en, $es, 3);
		// trying to connect. Return an error on fail
		if ($connection) register_shutdown_function('asterisk_socket_shutdown');
		else {$connection=NULL; return array(0=>array('response'=>'error','message'=>'socket_err:'.$en.'/'.$es));}
	}
	// building req.
	$str=array();
	foreach($params as $k=>$v) $str[]="{$k}: {$v}";
	$str[]='';
	$str=implode("\r\n",$str);
	// writing
	fwrite($connection,$str."\r\n");
	// Setting stream timeout
	$seconds=ceil(AC_TIMEOUT);
	$ms=round((AC_TIMEOUT-$seconds)*1000000);
	stream_set_timeout($connection,$seconds,$ms);
	// reading respomse and parsing it
	$str= ami_read($connection,$quick);
	$r=rawman_parse($str);
	//var_dump($r,$str);
	return $r;
}
/*** Reads data from coinnection
 * @param $connection -- active connection
 * @param bool $quick -- should we wait for timeout or return an answer after getting command status
 * @return string RAW response
 */
function ami_read($connection,$quick=false){
	$str='';
	do {
		$line = fgets($connection, 4096);
		$str .= $line;
		$info = stream_get_meta_data($connection);
		if ($quick and $line== "\r\n") break;
	}while ($info['timed_out'] == false );
	return $str;
}

/*** Echo`s data
 * @param $array answer data
 * @param bool $no_callback shold we output as JSON or use callback function
 */
function answer($array,$no_callback=false){
	header('Content-type: text/javascript;');
	if (!$no_callback)  echo "asterisk_cb(".json_encode($array).');';
	else echo json_encode($array);
	die();
}

/** Parse RAW response
 * @param $lines RAW response
 * @return array parsed response
 */
function rawman_parse($lines){
	$lines=explode("\n",$lines);
	$messages=array();
	$message=array();

	foreach ($lines as $l){
		$l=trim($l);
		if (empty($l) and count($message)>0){ $messages[]= $message;  $message=array(); continue;}
		if (empty($l))  continue;
		if (strpos($l,':')===false)  continue;
		list($k,$v)=explode(':',$l);
		$k=strtolower(trim($k));
		$v=trim($v);
		if (!isset( $message[$k]))  $message[$k]=$v;
		elseif (!is_array( $message[$k]))  $message[$k]=array( $message[$k],$v);
		else  $message[$k][]=$v;
	}
	if (count($message)>0) $messages[]= $message;
	return $messages;
}


/** Make request via AJAM
 * @param $params req. params
 * @return array parsed resp.
 */
function ajam_req($params){
	static $cookie;
	// EveryRequest Ajam sends back a cookir, needed for auth handling
	if ($cookie===NULL) $cookie='';
	// make req. and store cookie
	list($body,$cookie)= rq(AC_PREFIX.'rawman?'.http_build_query($params),$cookie);
	// parse an answer
	return rawman_parse($body);
}

/** make http req. to uri with cookie, parse resp and fetch a new cookie
 * @param $url
 * @param string $cookie
 * @return array  ($body,$newcookie)
 */
function rq($url,$cookie=''){
	// get RAW data
	$r=_rq($url,$cookie);
	// divide in 2 parts
	list($headersRaw,$body)=explode("\r\n\r\n",$r,2);
	// parse headers
	$headersRaw=explode("\r\n",$headersRaw);
	$headers=array();
	foreach ($headersRaw as $h){
		if (strpos($h,':')===false) continue;
		list($hname,$hv)=explode(":",$h,2);
		$headers[strtolower(trim($hname))]=trim($hv);
	}
	// fetch cookie
	if (!empty($headers['set-cookie'])){
		$listcookies=explode(';',$headers['set-cookie']);
		foreach ($listcookies as $c){
			list($k,$v)=explode('=',trim($c),2);
			if ($k=='mansession_id') $cookie=$v;
		}
	}

	return array($body,$cookie);
}

/**  mare a request to URI and return RAW resp or false on fail
 * @param $url
 * @param $cookie
 * @return bool|string
 */
function _rq($url,$cookie){
	$errno=$errstr="";
	$fp = fsockopen(AC_HOST, AC_PORT, $errno, $errstr, 3);
	if (!$fp) return false;
	$out = "GET {$url} HTTP/1.1\r\n";
	$out .= "Host: ".AC_HOST."\r\n";
	if (!empty($cookie)) $out.="Cookie: mansession_id={$cookie}\r\n";
	$out .= "Connection: Close\r\n\r\n";
	fwrite($fp, $out);
	$r='';
	while (!feof($fp)) $r.=fgets($fp);
	fclose($fp);
	return $r;
}


