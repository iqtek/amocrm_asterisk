<?php

function get_cdr($dbh, $date_from, $date_to, $minsec = 5, $blacklist = array()) {
	$stmt = 'SELECT calldate, src,dst,duration,billsec,uniqueid,recordingfile,dstchannel,dcontext FROM cdr WHERE disposition=\'ANSWERED\' AND billsec>=:minsec AND addtime>:from AND addtime<:to';
	$sth = $dbh->prepare($stmt);
	$sth->bindValue(':from', date('Y-m-d H:i:s',$date_from) );
	$sth->bindValue(':to', date('Y-m-d H:i:s',$date_to));
	$sth->bindValue(':minsec', $minsec, PDO::PARAM_INT);
	$sth->execute();
	$r = $sth->fetchAll(PDO::FETCH_ASSOC);

	foreach ($r as $k=>$v) {
		$r[$k]['calldate'] = date('Y-m-d H:i:s',strtotime($v['calldate'])-AC_TIME_DELTA*3600);
		if ($v['dcontext'] == 'ext-group') {
			if (preg_match("/SIP\/(\d+)\-/", $v['dstchannel'], $m)) {
				$r[$k]['dst'] = $m[1];
			}
		}
		if ($v['recordingfile'] == '') {
//			unset($r[$k]['uniqueid']);
		}
		if (in_array($v['dst'], $blacklist) || in_array($v['src'], $blacklist)) { /* Do not upload calls with particular number */
			unset($r[$k]);
			continue;
		}
		if (strlen($v['dst']) <=4 && strlen($v['src']) <= 4) { /* Do not upload internal calls */
			unset($r[$k]);
			continue;
		}
		unset($r[$k]['dstchannel']);
		unset($r[$k]['dcontext']);
	}
	return array_values($r);
}

function phone_number_filter($phone, $area_code, $format = 'e.164') {
	if ($phone[0] == ' ') { /* Восстанавливаем + в начале номера, если он был передан не как url-encoded сущность */
		$phone = '+'.substr($phone, 1);
	}
	$phone = preg_replace('/[а-яА-Яa-zA-Z]/u', '#', (string) $phone);
	$phone = preg_replace('/[^\+#0-9]/u', '', (string) $phone);
	$phone = preg_replace('/#{2,}/','#', (string) $phone);

	$phone_national = true;
	$phone_ext = false;
	if ($phone[0] == '#') { /* Пропускаем возможные буквы в начале номера */
		$phone = substr($phone, 1);
	}
	if (substr_count($phone, '#') > 1) {
		return false; /* incorrect input */
	} else if (substr_count($phone, '#') == 1) {
		list($phone, $phone_ext) = explode('#', $phone);
	}
	if (strpos($phone, '+') != 0) {
		return false; /* incorrect input */
	}


	if ($format == 'national-by') {
		if (substr($phone, 0, 2) == '80') {
			return $phone;
		}
		if (substr($phone, 0, 3) == '810') {
			return $phone;
		}
	}

	// Начинается с + - международный формат
	if (strpos($phone, '+') === 0) {
		if ($phone[1] == '7') { /* Russia */
			$phone_filtered = substr($phone, 2, 10);
			if (strlen($phone) > 12) {
				$phone_ext = substr($phone, 12);
			}
		}
		else {
			$phone_filtered = substr($phone, 1);
			$phone_national = false;
		}
	} else {
		if (strlen($phone) == 10 - strlen($area_code)) { /* локальный городской номер */
			$phone_filtered = $area_code.$phone;
		} else if (strlen($phone) == 10) { /* россия и казахстан без кода */
			$phone_filtered = $phone;
		} else if (strlen($phone) == 11 && $phone[0] == '8') { /* россия и казахстан с кодом */
			$phone_filtered = substr($phone, 1);
		} else if (strlen($phone) == 11 && $phone[0] == '7') { /* россия и казахстан с кодом в e.164 */
			$phone_filtered = substr($phone, 1);
		} else if (strlen($phone) > 11 && substr($phone, 0, 3) == '810') {
			$phone_filtered = substr($phone, 3);
			$phone_national = false;
		} else if (strlen($phone) > 11 && $phone[0] == '8') { /* россия и казахстан с кодом и доп.номером */
			$phone_filtered = substr($phone, 1, 10);
		} else if (strlen($phone) >= 11) {
			$phone_filtered = $phone;
			$phone_national = false;
		} else {
			return false;
		}
	}
	switch ($format) {
		case 'national':
			if ($phone_national) {
				$phone_filtered = '8'.$phone_filtered;
			} else {
				$phone_filtered = '810'.$phone_filtered;
			}
			break;
		case 'national-by':
			if (substr($phone_filtered, 0, 3) == '375') {
				$phone_filtered = '80'.substr($phone_filtered, 3);
			} else {
				$phone_filtered = '810'.$phone_filtered;
			}
			break;
		case 'e.164':
			if ($phone_national) {
				$phone_filtered = '+7'.$phone_filtered;
			} else {
				$phone_filtered = '+'.$phone_filtered;
			}
			break;
		default:
			return false;
	}
	return $phone_filtered;

}
