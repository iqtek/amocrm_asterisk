<?php

include dirname(__FILE__)."/config/config.php";
include dirname(__FILE__)."/include/functions.cdr.php";
include dirname(__FILE__)."/include/functions.astman.php";
include dirname(__FILE__)."/include/functions.file.php";


$db_cs = AC_DB_CS;
$db_u = !strlen(AC_DB_UNAME)?NULL:AC_DB_UNAME;
$db_p = !strlen(AC_DB_UPASS)?NULL:AC_DB_UPASS;
date_default_timezone_set('UTC');

if (AC_PORT<1) { 
	die('Please, configure settings first!');
}

if (defined('AC_RECORD_PATH') AND !empty($_GET['GETFILE'])){
	$p=AC_RECORD_PATH;
	if (empty($p)) {
		die('Error while getting file from asterisk');
	}
	try {

		$dbh = new PDO($db_cs, $db_u, $db_p);
		$sth = $dbh->prepare('SELECT calldate,recordingfile FROM cdr WHERE uniqueid= :uid LIMIT 1');
		$sth->bindValue(':uid',strval($_GET['GETFILE']));
		$sth->execute();
		$r = $sth->fetch(PDO::FETCH_ASSOC);
		if (AC_INTEGRATION_TYPE == 'yeastar') { /* Yeastar use old MySQL auth protocol and do no supply always recording name in CDR event*/
//			$db_month = date('Ym', strtotime($r['calldate']));
			$arr = explode(".", $_GET['GETFILE']);
			$db_month = date('Ym', $arr[0]);

			$sql_user = AC_YEASTAR_MYSQL_USER;
			$sql_pass = AC_YEASTAR_MYSQL_SECRET;
			/* For S-series */
//			$ssh_cmd = "echo \"SELECT monitorpath FROM asteriskcdr.cdr_{$db_month} WHERE uniqueid='{$_GET['GETFILE']}' LIMIT 1\"|mysql -s -u {$sql_user} -p{$sql_pass} -h 127.0.0.1";
			/* For U-series */
			$ssh_cmd = "echo \"SELECT recordpath FROM cdr.cdr_{$db_month} WHERE uniqueid='{$_GET['GETFILE']}' LIMIT 1\"|mysql -s -u {$sql_user} -p{$sql_pass} -h 127.0.0.1";
			$r['recordingfile'] = trim(get_ssh_cmd(AC_YEASTAR_SSH, $ssh_cmd));
		}
		if ($r===false OR empty($r['recordingfile'])) {
			header("HTTP/1.0 404 Not Found");
			die();
		}
		if (AC_INTEGRATION_TYPE == 'yeastar') {
			$r['recordingfile'] = str_replace('.wav_alaw', '.wav', $r['recordingfile']);
			/* For U-series */
			$r['recordingfile'] = str_replace('/tmp/media/harddisk1', '/ftp_media/harddisk', $r['recordingfile']);
		}

		$date = strtotime($r['calldate']);
		$replace = array('#' => $r['recordingfile']);
		foreach (array('d','m','Y','y') as $d) {
			$replace['%'.$d] = date($d, $date);
		}
		if (AC_INTEGRATION_TYPE == 'grandstream') {
			$r['recordingfile'] = str_replace('.wav@', '.wav', $r['recordingfile']);
		}
		$p=str_replace(array_keys($replace), array_values($replace), $p);
	} catch (PDOException $e) {
		header("HTTP/1.0 Internal Server Error");
		die();
	}

	$ext = pathinfo($r['recordingfile'], PATHINFO_EXTENSION);
	$encode = true;
	$redirect = false;
	$url = parse_url($p);

	if ($_GET['noredirect'] == 'Y') {
		$url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . "{$_SERVER['HTTP_HOST']}/{$_SERVER['REQUEST_URI']}";
		if ($ext != 'mp3') {
			die(str_replace('noredirect=Y', '', $url));
		}
		if ($url['scheme'] != 'http' && $url['scheme'] != 'https') {
			die(str_replace('noredirect=Y', '', $url));
		}
	}

	/* Retrieve file to server */
	switch ($url['scheme']) {
		case 'file':
			copy($url['path'],'/tmp/'.$r['recordingfile']);
			$tmp_file = '/tmp/'.$r['recordingfile'];
			break;
		case 'http':
		case 'https':
			$redirect = true;
			if ($ext != 'mp3') { /* retrieve file to encode */
				$tmp_file = get_http_file($p, $r['recordingfile']);
			}
			break;
		case 'ssh':
		case 'sftp':
			$tmp_file = get_ssh_file($p);
			break;
		case 'ftp':
			$tmp_file = get_ftp_file($p);
			break;
		default:
			die('Unable to detect URL type');
	}
	if (!$redirect && ($tmp_file === false)) {
		die('Unable to retrieve file');
	}
	/* Encode files to mp3 format */
	switch ($ext) {
		case 'mp3':
			$encode_file = $tmp_file;
			$encode = false;
			break;
		case 'gsm':
			$encode_file = encode_gsm2mp3($tmp_file);
			break;
		case 'wav':
		case 'WAV':			
			$encode_file = encode_wav2mp3($tmp_file);
			break;
		default:
			die('Unable to find proper encoder');
	}
	if (file_exists($tmp_file)) {
		unlink($tmp_file);
	}
	if ($redirect && !$encode) {
		if ($_GET['noredirect'] == 'Y') {
			die($p);
		}
		header('Location: '.$p);
		exit();
	} else {
		return_file($encode_file, true);
	}
}

// filter parameters from _GET
foreach (array('login','secret','action') as $k){
	if (empty($_GET['_'.$k])) { 
		die('NO_PARAMS');
	}
	$$k=strval($_GET['_'.$k]);
}
// trying to check accacess
$loginArr=array(
	'Action'=>'Login',
	'username'=>$login,
	'secret'=>$secret,
	'Events'=>'off',
);
$resp=asterisk_req($loginArr,true);
// problems? exiting

if ($resp[0]['response']!=='Success') {
	answer(array('status'=>'error','data'=>$resp[0]));
}

//auth OK. Lets perform actions
if ($action==='status'){ // list channels status
	$params=array( 'action'=>'status');
	$resp=asterisk_req($params);
	if ($resp[0]['response']!=='Success') {
		answer(array('status'=>'error','data'=>$resp[0]));
	}
	unset($resp[end(array_keys($resp))],$resp[0]);
	$resp=array_values($resp);
	foreach($resp as $k => $v) {
		/* Reduce traffic amount and send only calls, initiating popup */
		if ($v['state'] != 'Ringing' && $v['channelstatedesc'] != 'Ringing') {
			unset($resp[$k]);
			continue;
		}
		if (AC_INTEGRATION_TYPE == 'yeastar') {
			try { /* S-series */
				$dbh = new PDO($db_cs, $db_u, $db_p);
				$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				$stmt = "SELECT * FROM `dial_log` WHERE `dstuid`=:uid LIMIT 1";
				$sth = $dbh->prepare($stmt);
				$sth->bindValue(':uid', $v['uniqueid'] );
				$sth->execute();
				$r = $sth->fetchAll(PDO::FETCH_ASSOC);
				$resp[$k]['connectedlinenum'] = $r[0]['callerid'];
			} catch (PDOException $e) {
				answer(array('status'=>'error','data'=>$e->getMessage()),true);
			}
			if (isset($resp[$k]['effectiveconnectedlinenum'])) {
				$resp[$k]['connectedlinenum'] = $resp[$k]['effectiveconnectedlinenum']; /* U-series */
			}			
		}
		/* If connectedlinenum is not defined - js error occure in browser */
		if (!isset($resp[$k]['connectedlinenum'])) {
			$resp[$k]['connectedlinenum'] = $v['calleridnum'];
		}
	}
	answer(array('status'=>'ok','action'=>$action,'data'=>array_values($resp)));

}elseif ($action==='call'){ // originate a call
	/* Filter 'to' value */
	$to = phone_number_filter($_GET['to'], AC_INTEGRATION_AREACODE, AC_INTEGRATION_DIALPLAN);
	if ($to === false) {
		answer(array('status'=>'error','data'=>'Not valid phone number'));
	}
	$context = AC_INTEGRATION_OUTBOUND_CONTEXT;
	$from = AC_INTEGRATION_SIPCHANNEL.'/'.intval($_GET['from']);
	if (AC_INTEGRATION_TYPE == 'freepbx') {
		/* TODO: Get device from settings */
		// /DEVICE/101/dial                                  : SIP/101
		// /AMPUSER/101/device                               : 101
		$from = AC_INTEGRATION_SIPCHANNEL.'/'.intval($_GET['from']);
		if (AC_FREEPBX_FOLLOWME) {
			$from = "Local/".intval($_GET['from'])."@from-internal/n";
		}
	}
	else if (AC_INTEGRATION_TYPE == 'yeastar') {
		$context = 'DLPN_DialPlan'.intval($_GET['from']);
		$from = AC_INTEGRATION_SIPCHANNEL.'/'.intval($_GET['from']);
	}
	else if (AC_INTEGRATION_TYPE == 'grandstream') {
		$context = 'from-internal';
		$from = 'Local/'.intval($_GET['from']).'@from-internal';
	}

	/* Get device for 'from' */
	$params=array(
		'action'=>'Originate',
		'channel'=> $from,
		'Exten'=> $to,
		'Context'=> $context,
		'priority'=>'1',
		'Callerid'=>'"'.strval($_GET['as']).'" <'.intval($_GET['from']).'>',
		'Async'=>'Yes'
	);
//var_dump($params);
	$resp=asterisk_req($params,true);
	if ($resp[0]['response']!=='Success') {
		answer(array('status'=>'error','data'=>$resp[0]));
	} 
	answer(array('status'=>'ok','action'=>$action,'data'=>$resp[0]));
} elseif ($action==='cdr'){ // fetch call history
	try {
		/* Get date and time */
		foreach (array('date_from','date_to') as $k){
			$v=doubleval( (!empty($_GET[$k]))?intval($_GET[$k]):0 );
			if ($v<0) {
				$v=time()-$v;
			}
			$$k=$v;
		}
		if ($date_from < time()-10*24*3600) {
			$date_from = time()-7*24*3600; //retr. not more than 10d before
		}
		$date_from=($date_from?$date_from+AC_TIME_DELTA*3600:0); //default 01-01-1970
		$date_to  =($date_to  ?$date_to  +AC_TIME_DELTA*3600:time()+AC_TIME_DELTA*3600);//default now()

		if (AC_INTEGRATION_TYPE == 'grandstream') {
			// https://192.168.254.200:8088/cdrapi?caller=Anonymous&callee=2@,34@,@5&startTime=2013-07-01T00:00:00-06:00&endTime=2013-07-31T23:59:59-06:00
			// https://192.168.254.200:8088/cdrapi?format=json&caller=5300&minDur=8&maxDur=60
			$date_from_f = gmdate('Y-m-d\TH:i:s',$date_from);
			$date_to_f = gmdate('Y-m-d\TH:i:s',$date_to);
			$url = AC_GRANDSTREAM_API."/cdrapi?format=json&minDur=5&startTime={$date_from_f}&endTime={$date_to_f}";
			$context = stream_context_create(array(
				'http' => array(
				'header'  => "Authorization: Basic " . base64_encode(AC_GRANDSTREAM_API_USER.":".AC_GRANDSTREAM_API_SECRET)
				)
			));
			$data = json_decode(file_get_contents($url, false, $context), true);
			$dbh = new PDO($db_cs, $db_u, $db_p);
			$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$cdr_crm = array(); // CDR for send into CRM
			$cdr_final = array(); // CDR for local storage and later access to call recordings
			$return_keys = array('calldate', 'src', 'dst', 'duration', 'billsec', 'uniqueid', 'recordingfile');
			foreach ($data['cdr_root'] as $cdr) {
				if (isset($cdr['main_cdr'])) {
					foreach ($cdr as $subcdr) {
						if (isset($subcdr['recordfiles']) && strlen($subcdr['recordfiles'])) {
							$cdr_crm[] = $subcdr;
							break;
						}
					}
					continue;
				}
				if (!strlen($cdr['uniqueid'])) {
					continue;
				}
				if (strlen($cdr['src'])<=4 && strlen($cdr['dst']) <=4) {
					continue;
				}
				$cdr_crm[] = $cdr;
			}
			$cdr_return = array();
			foreach ($cdr_crm as $i => $cdr) {
				$cdr_crm[$i]['calldate'] = $cdr['start'];
				$cdr_crm[$i]['recordingfile'] = $cdr['recordfiles'];

				$cdr_return[] = array_intersect_key($cdr_crm[$i], array_flip($return_keys));
				if (!strlen($cdr['recordfiles'])) {
					continue;
				}
				$sth = $dbh->prepare('INSERT IGNORE INTO cdr SET calldate=:calldate, uniqueid= :uid, src=:src, dst=:dst, recordingfile=:rec, 
					duration=:duration, billsec=:billsec, channel=:channel, dcontext=:dcontext, dstchannel=:dstchannel, disposition=:disp, 
					lastapp=:lastapp, lastdata=:lastdata');
				$sth->bindValue(':calldate', $cdr['start']);
				$sth->bindValue(':uid', $cdr['uniqueid']);
				$sth->bindValue(':lastapp', $cdr['lastapp']);
				$sth->bindValue(':lastdata', $cdr['lastdata']);
				$sth->bindValue(':disp', $cdr['disposition']);
				$sth->bindValue(':duration', $cdr['duration']);
				$sth->bindValue(':billsec', $cdr['billsec']);
				$sth->bindValue(':dcontext', $cdr['dcontext']);
				$sth->bindValue(':dstchannel', $cdr['dstchannel']);
				$sth->bindValue(':channel', $cdr['channel']);
				$sth->bindValue(':src', $cdr['src']);
				$sth->bindValue(':dst', $cdr['dst']);
				$sth->bindValue(':rec', $cdr['recordfiles']);
				$sth->execute();
			}
			header("X-REAL_DATE:" . gmdate('Y-m-d H:i:s',$date_from).'@'. gmdate('Y-m-d H:i:s',$date_to));
			answer(array('status'=>'ok','data' => $cdr_return), true);
			exit();
		}
		$dbh = new PDO($db_cs, $db_u, $db_p);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$r = get_cdr($dbh, $date_from, $date_to);
		foreach ($r as $id => $record) {
			if (AC_INTEGRATION_TYPE == 'yeastar') {
				if (preg_match("/\d+\((\d+)\)/", $record['dst'], $m)) {
					$r[$id]['dst'] = $m[1];
				}
				if (preg_match("/\d+\(from (\d+)\)/", $record['dst'], $m)) { /* S-series */
					$r[$id]['dst'] = $m[1];
				}				
			}
		}
		header("X-REAL_DATE:" . gmdate('Y-m-d H:i:s',$date_from).'@'. gmdate('Y-m-d H:i:s',$date_to));
		answer(array('status'=>'ok','data'=>$r),true);
	} catch (PDOException $e) {
		answer(array('status'=>'error','data'=>$e->getMessage()),true);
	}
}
