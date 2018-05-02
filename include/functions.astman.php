<?php

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