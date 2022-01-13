SELECT from_unixtime(timecreated), * from mdl_logstore_standard_log where component = 'local_tlconnect' and  other like '%errorCode%' and
(timecreated BETWEEN
UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 HOUR))
AND
UNIX_TIMESTAMP(CURDATE()));