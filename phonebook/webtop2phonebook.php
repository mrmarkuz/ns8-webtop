#!/usr/bin/php
<?php
/**
 * Copyright (C) 2023 Nethesis S.r.l.
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * This script extracts contacts from webtop and add them to phonebook.
**/
define("DEBUG", getenv('DEBUG') === 'true');
set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));

// get phonebook connection details
$pbookhost = $_ENV["PHONEBOOK_DB_HOST"];
$pbookpass = $_ENV["PHONEBOOK_DB_PASSWORD"];
$pbookuser = $_ENV["PHONEBOOK_DB_USER"];
$pbookport = $_ENV["PHONEBOOK_DB_PORT"];
$pbookdb = $_ENV["PHONEBOOK_DB_NAME"];

$database = mysql_connect("$pbookhost:$pbookport",$pbookuser,$pbookpass) or die("Database error config");
mysql_select_db($pbookdb, $database);
mysql_set_charset("utf8");

$webtop_db = pg_connect("host=localhost dbname=webtop5 user=postgres");

//get all share IDs
$sql = "SELECT DISTINCT roles_permissions.instance
    FROM core.roles_permissions
    WHERE roles_permissions.role_uid IN (
    SELECT users.user_uid
    FROM core.users
    WHERE users.domain_id='NethServer' AND users.user_id='admin')
    AND (roles_permissions.service_id = 'com.sonicle.webtop.contacts')
    AND (roles_permissions.key = 'CATEGORY_FOLDER@SHARE')
    AND (roles_permissions.action = 'READ')";

if(DEBUG)
    echo "$sql.\n";
$pgresult = pg_query($webtop_db, $sql);
if ($pgresult == FALSE){
  throw new Exception(pg_last_error($webtop_db));
}

//create share IDs array
$ids = array();
while ($row = pg_fetch_assoc($pgresult)) {
    if(DEBUG)
        print_r($row);

    $ids[] = $row['instance'];
}

if(DEBUG) {
    print ("IDs:");
    print_r($ids);
}

if (empty($ids)) {
    if (DEBUG) print ("[WARNING] No ids\n");
    exit (0);
}

//Gat all shares using shares IDs
$sql = "SELECT core.shares.\"instance\"
    FROM core.shares
    INNER JOIN core.users ON (core.shares.user_uid=core.users.user_uid)
    WHERE (core.shares.share_id IN (".implode(',',$ids)."))
    AND (core.shares.service_id = 'com.sonicle.webtop.contacts')
    AND (core.shares.key = 'CATEGORY')";

if(DEBUG)
    echo "$sql.\n";
$pgresult = pg_query($webtop_db, $sql);
if ($pgresult == FALSE){
  throw new Exception(pg_last_error($webtop_db));
}

//Get category IDs
$categoryIDs= array();
while ($row = pg_fetch_assoc($pgresult)) {
    if(DEBUG)
        print_r($row);
    $categoryIDs[] = $row['instance'];
}

//Get contacts query
$sql = "SELECT *
    FROM contacts.contacts
    WHERE contacts.category_id IN (".implode(',',$categoryIDs).") AND contacts.revision_status != 'D'";

if(DEBUG)
    echo "$sql.\n";
$pgresult = pg_query($webtop_db, $sql);
if ($pgresult == FALSE){
  throw new Exception(pg_last_error($webtop_db));
}

// Remove WebTop contacts from centralized phonebook
mysql_query('DELETE FROM phonebook WHERE sid_imported = "webtop"',$database);

while ($row = pg_fetch_assoc($pgresult)) {

     if(DEBUG)
        print_r($row);

     foreach (['home_telephone','work_telephone','work_mobile','work_fax'] as $field) {
         if (isset($row[$field])) {
            $row[$field] = preg_replace('/^\+/','00',$row[$field]);
            $row[$field] = preg_replace('/[^0-9]*/','',$row[$field]);
         } else {
            $row[$field] = '';
         }
     }
     $homeemail=mysql_escape_string($row["home_email"]);
     $workemail=mysql_escape_string($row["work_email"]);
     $homephone=mysql_escape_string($row["home_telephone"]);
     $workphone=mysql_escape_string($row["work_telephone"]);
     $cellphone=mysql_escape_string($row["work_mobile"]);
     $fax=mysql_escape_string($row["work_fax"]);
     $title=mysql_escape_string($row["title"]);
     $company=mysql_escape_string($row["company"]);
     $notes=mysql_escape_string($row["notes"]);
     $name=mysql_escape_string($row["firstname"]." ".$row["lastname"]);
     $homestreet=mysql_escape_string($row["home_address"]);
     $homecity=mysql_escape_string($row["home_city"]);
     $homeprovince=mysql_escape_string($row["home_state"]);
     $homepostalcode=mysql_escape_string($row["home_postalcode"]);
     $homecountry=mysql_escape_string($row["home_country"]);
     $workstreet=mysql_escape_string($row["work_address"]);
     $workcity=mysql_escape_string($row["work_city"]);
     $workprovince=mysql_escape_string($row["work_state"]);
     $workpostalcode=mysql_escape_string($row["work_postalcode"]);
     $workcountry=mysql_escape_string($row["work_country"]);
     $url=mysql_escape_string($row["url"]);

     $sql_in="Insert into phonebook (owner_id,type,sid_imported,homeemail,workemail,homephone,workphone,cellphone,fax,title,company,notes,name,homestreet,homecity,homeprovince,homepostalcode,homecountry,workstreet,workcity,workprovince,workpostalcode,workcountry,url) values ('admin','WebTop','webtop','$homeemail','$workemail','$homephone','$workphone','$cellphone','$fax','$title','$company','$notes','$name','$homestreet','$homecity','$homeprovince','$homepostalcode','$homecountry','$workstreet','$workcity','$workprovince','$workpostalcode','$workcountry','$url')";
     mysql_query($sql_in,$database);
}


function truncateString($string, $size) {
       if (strlen($string) <= $size)
            return $string;
        else
            return substr($string, 0, $size - 1);
}

function is_html($string) {
      return preg_match("/<[^<]+>/", $string, $m) != 0;
}

