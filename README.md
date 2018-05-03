Данная интеграция основана на штатной интеграции amocrm и asterisk.

# Файлы

Содержимое архива расположить в /opt/iqtek/amocrm

Приложения и файлы настроек:
./amocrm.php			# Интеграций с Amocrm по HTTP

./config
  config.php.sample		# Настройки интеграции

./contrib
  extensions_amocrm.conf	# Контексты Asterisk для реализации дополнительных функций
  manager_amocrm.conf		# Пример настройки AMI пользователя

./README.md			# Этот файл

# Amocrm HTTP

Файл отдает по HTTPS ответы на запросы amocrm, необходимые для работы плагина amocrm:
  * Запрос списка каналов (с IP адреса пользователя)
  * Запрос на создание вызова (с IP amocrm)
  * Запрос детализации вызовов (с IP amocrm)
  * Запрос записи разговора (с IP пользователя)

## Требования

  * Роутер с поддержкой Hairpin NAT (перенаправление пакетов LAN->WAN->LAN)
  * Либо: локальный DNS сервис
  * Желательно - статический IP;
  * SSL сертификат (платный, letsencrypt)
  * Домен с настроенной DNS записью вашего статического IP
  * Интернет >1Mbps

Технические требования к платформе Asterisk: 
  * Поддержка AJAM или AMI
  * Работа вебсервера с поддержкой протокола https
  * PHP с поддержкой json_encode (5.2+ или 5.1+PECL_json)
  * PHP с расширением PDO для бэкэнда CDR
  * Сервер с Asterisk в одной сети с интеграцией

## Пакеты

apt install apache2 libapache2-mod-php5 php5-mysql lame

## Настройка apache
  - Настроить сертификат SSL в соответствии с инструкцией: https://www.digitalocean.com/community/tutorials/how-to-secure-apache-with-let-s-encrypt-on-centos-7

## Настройка asterisk

Настроим Asterisk:

ln -s ./contrib/manager_amocrm.conf /etc/asterisk/manager_amocrm.conf
echo \#include manager_amocrm.conf >> /etc/asterisk/manager.conf
asterisk -rx "manager reload"

Для систем на базе freepbx необходимо создать аналогичные настройки используя веб-интерфейс

## Настройка интеграции

vi ./config/config.php

В остальном настройка интеграции и проверка работы по инструкции https://voxlink.ru/kb/asterisk-configuration/amocrm-asterisk/

# Настройка БД

Добавить поле для имени файла записи разговора:
```sql
ALTER TABLE `cdr` ADD `recordingfile` VARCHAR(120) NOT NULL;
```
Добавить поле для хранения времени добавления cdr записи:
```sql
ALTER TABLE `cdr` ADD `addtime` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
```
Установить значение поля для старой записи:
```sql
UPDATE cdr SET addtime=calldate;
```

  * Amocrm manual: http://support.amocrm.ru/hc/ru/articles/207831798-Asterisk

