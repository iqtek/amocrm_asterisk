<?php

function get_ssh_file($path) {
	set_include_path('include/phpseclib');
	include('include/phpseclib/Net/SFTP.php');
	$url = parse_url($path);
	if (strlen($url['port'])) {
		$sftp = new Net_SFTP($url['host'], $url['port']);
	} else {
		$sftp = new Net_SFTP($url['host']);
	}
	if (!$sftp->login($url['user'], $url['pass'])) {
		return false;
	}
	$local_file = '/tmp/'.pathinfo($url['path'], PATHINFO_BASENAME);
	var_dump($local_file);
	$sftp->get($url['path'], $local_file);
	return $local_file;
}

function get_ssh_cmd($path, $cmd) {
	set_include_path('include/phpseclib');
	include('include/phpseclib/Net/SSH2.php');
	$url = parse_url($path);
	if (strlen($url['port'])) {
		$ssh = new Net_SSH2($url['host'], $url['port']);
	} else {
		$ssh = new Net_SSH2($url['host']);
	}
	if (!$ssh->login($url['user'], $url['pass'])) {
		return false;
	}
	return $ssh->exec($cmd);
}

function get_http_file($path, $filename = false) {
	$url = parse_url($path);
	if (strlen($url['user']) && strlen($url['pass'])) {
		$context = stream_context_create(array(
			'http' => array(
			'header'  => "Authorization: Basic " . base64_encode("{$url['user']}:{$url['pass']}")
			)
		));
		$file = file_get_contents($path, false, $context);
	} else {
		$file = file_get_contents($path);
	}
	if ($file === false) {
		return false;
	}
	if ($filename === false) {
		$local_file = '/tmp/'.pathinfo($url['path'], PATHINFO_BASENAME);
	} else {
		$local_file = '/tmp/'.pathinfo($filename, PATHINFO_BASENAME);
	}
	file_put_contents($local_file, $file);
	return $local_file;
}


function get_ftp_file($path) {
	$file = file_get_contents($path);
	if ($file === false) {
		return false;
	}
	$url = parse_url($path);
	$local_file = '/tmp/'.pathinfo($url['path'], PATHINFO_BASENAME);
	file_put_contents($local_file, $file);
	return $local_file;
}


/* Encode gsm to mp3 */
function encode_gsm2mp3($file) {
	$wav_file = '/tmp/'.pathinfo($file, PATHINFO_FILENAME).".wav";
	$mp3_file = '/tmp/'.pathinfo($file, PATHINFO_FILENAME).".mp3";
	$cmd = "sox -V3 -t gsm {$file} -e signed-integer {$wav_file} 2>&1";
	exec($cmd);
	if (!file_exists($wav_file)) {
		return false;
	}
	$cmd = "lame {$wav_file} {$mp3_file} 2>&1";
	exec($cmd);
	unlink($wav_file);
	return $mp3_file;
}


/* Encode wav to mp3 */
function encode_wav2mp3($file) {
	$mp3_file = '/tmp/'.pathinfo($file, PATHINFO_FILENAME).".mp3";
	$cmd = "lame '{$file}' '{$mp3_file}' 2>&1";
	exec($cmd);
	return $mp3_file;
}

function return_file($file, $remove = false) {
	if (file_exists($file)) {
	    header('Content-Description: File Transfer');
	    header('Content-Type: application/octet-stream');
	    header('Content-Disposition: attachment; filename='.basename($file));
	    header('Expires: 0');
	    header('Cache-Control: must-revalidate');
	    header('Pragma: public');
	    header('Content-Length: ' . filesize($file));
	    readfile($file);
	    if ($remove === true) {
		unlink($file);
	    }
	}
	exit();
}