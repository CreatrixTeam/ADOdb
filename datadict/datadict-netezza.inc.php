<?php

/**
  V5.20dev  ??-???-2014  (c) 2000-2014 John Lim (jlim#natsoft.com). All rights reserved.
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.

  Set tabs to 4 for best viewing.

*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

include_once(ADODB_DIR.'/datadict/datadict-postgres.inc.php');

class ADODB2_netezza extends ADODB2_postgres {
	public  $databaseType = 'netezza';
	public  $sql_concatenateOperator = '||';
	public  $sql_sysDate = "CURRENT_DATE";
	public  $sql_sysTimeStamp = "CURRENT_TIMESTAMP";
}