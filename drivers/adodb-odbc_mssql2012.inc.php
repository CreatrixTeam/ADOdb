<?php
/*
 @version   v5.22.0-dev  Unreleased
 @copyright (c) 2015      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.
  Set tabs to 4.

  Microsoft SQL Server 2012 via ODBC
*/

if (!defined('ADODB_DIR')) 
	die();

include_once(ADODB_DIR."/drivers/adodb-odbc_mssql.inc.php");

class  ADODB_odbc_mssql2012 extends ADODB_odbc_mssql
{
	public $databaseType = 'odbc_mssql2012';
	/*
	* Makes behavior similar to prior versions of SQL Server
	*/
	public $connectStmt = 'SET CONCAT_NULL_YIELDS_NULL ON';
}

class  ADORecordSet_odbc_mssql2012 extends ADORecordSet_odbc_mssql
{
	public $databaseType = 'odbc_mssql2012';
}