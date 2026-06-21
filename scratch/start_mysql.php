<?php
pclose(popen('start /B "" "C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqld.exe" --defaults-file="C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\my.ini"', "r"));
echo "MySQL detached start triggered.\n";
