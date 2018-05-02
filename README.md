Данная интеграция основана на штатной интеграции amocrm и asterisk.

# Файлы

Содержимое архива расположить в /opt/iqtek/amocrm

Приложения и файлы настроек:
./amocrm-cron.php		# Amocrm Cron
./amocrm.php			# Интеграций с Amocrm по HTTP
./bin
  app.py			# Amocrm Daemon
  settings.py
  contrib/install.centos	# Установка зависимостей в CentOS
  contrib/install.debian 	# Установка зависимостей в Debian
  contrib/init.d/amocrm		# Задача дл init.d

./config
  config@example.php		# Привязка полей amocrm
  config.php.sample		# Настройки интеграции
  example@test@example.com.key	# Ключ API AmoCRM

./contrib
  extensions_amocrm.conf	# Контексты Asterisk для реализации дополнительных функций
  manager_amocrm.conf		# Пример настройки AMI пользователя

./README.md			# Этот файл

# Amocrm Cron

<<< deprecated >>>

Приложение запускается по расписанию, запоминает время последнего запуска и выбирает из БД все записи cdr, добавленные за время прошедшее с запуска.
Функции выполняемые приложением:
1) Создание сделок и контактов для тех номеров телефонов, которые не участвуют в какой-либо открытой сделке (pushleads=true)
2) Автоматическое создание контактов (pushcontacts=true)

## Установка

Установить composer и необходимые библиотеки:
```shell
    cd /opt/iqtek/amocrm
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php -r "if (hash_file('SHA384', 'composer-setup.php') === 'e115a8dc7871f15d853148a7fbac7da27d6c0030b848d9b3dc09e2a0388afed865e6a3d6b3c0fad45c48e2b5fc1196ae') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
    php composer-setup.php
    php -r "unlink('composer-setup.php');"
    ./composer.phar install
```

Настройка:
```
    cp ./config/config@example.php ./config/config@<domain>.php
    cp ./config/config.php.sample ./config/config.php
    echo  '<domainkey>' ./config/<domain>@<email>.key
```

<domain> - домен компании в amocrm используемый для входа в аккаунты <domain>.amocrm.ru
<domainkey> - ключ API администратора amocrm
<email> - адрес электронной почты администратора AmoCRM, используемый для входа

Указать используемые значения <domain> и <email> в config.php в константах AC_AMOCRM_DOMAIN и AC_AMOCRM_ACCOUNT

Добавить в crontab:
*/5 * * * * /opt/iqtek/amocrm/amocrm-cron.php

# Amocrm Daemon

Производит запись CDR, полученного по AMI в БД для тех случаев, когда хранение в CDR в MySQL напрямую невозможно.

## Установка

```
./bin/contrib/install.centos
cp ./bin/contrib/init.d/amocrm /etc/init.d
```

При запросе пароля нажать ENTER.

Внести настройки в settings.py:
  * AMI_SETTINGS - настройка доступа к AMI
  * MYSQL_SETTINGS - настройки доступа к mysql
  * MYSQL_MAPPING - соответствие полей cdr_manager и БД mysql

```
/etc/init.d/amocrm start
chkconfig amocrm on
```

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

