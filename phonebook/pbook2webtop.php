#!/usr/bin/php
<?php
/**
 * Copyright (C) 2023 Nethesis S.r.l.
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * This script extracts contacts from phonebook and add them to WebTop5.
**/
define("DEBUG",false);
set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));

if($argc<2)
	$foldername = "WebTop";
else
    $foldername = $argv[1];

$iddomain="NethServer";

//get phonebook db host
$pbookhost = $_ENV["PHONEBOOK_DB_HOST"];
//get phonebook db password
$pbookpass = $_ENV["PHONEBOOK_DB_PASSWORD"];

$database = mysql_connect($pbookhost,'pbookuser',$pbookpass) or die("Database error config");
mysql_select_db('phonebook', $database);
mysql_set_charset("utf8");

$webtop_db = pg_connect("host=localhost dbname=webtop5 user=postgres");

$pgresult = pg_query($webtop_db, "BEGIN;");
if ($pgresult == FALSE)
     throw new Exception(pg_last_error($webtop_db));

$builtin='true';
$foldername="Rubrica Centralizzata";
$user="admin";
$pgtest = pg_query($webtop_db, "SELECT * from contacts.categories where user_id='".$user."' and domain_id='".$iddomain."' and name = '".$foldername."';");
$rows = pg_num_rows($pgtest);
$num_contacts=0;

if ($rows==0) {
       $query="INSERT INTO contacts.categories (category_id,domain_id,user_id,built_in,name,color,sync,is_default) values (nextval('contacts.SEQ_CATEGORIES'),'$iddomain','$user','$builtin','$foldername','#FFFFFF','O','false')";
       $result = pg_query($webtop_db, $query);
}

$folder_id=getCategoryId($foldername,$user,$iddomain);

$pgresult = pg_query($webtop_db, "DELETE from contacts.contacts where category_id='$folder_id';");
if ($pgresult == FALSE)
     throw new Exception(pg_last_error($webtop_db));

$pgresult = pg_query($webtop_db, "COMMIT;");

$result = mysql_query("SELECT owner_id, homeemail, workemail, homephone, workphone, cellphone, fax, title, company, notes, name,
                                homestreet, homepob, homecity, homeprovince, homepostalcode, homecountry, workstreet, workpob, workcity,
                                workprovince, workpostalcode, workcountry, url FROM phonebook where company!='' or name!='' ", $database);
if($result == false) //print errors
{
    echo mysql_error()."\n";
}

while($row = mysql_fetch_array($result))
{
     unset($arrayContact);

     $homeemail=$row["homeemail"];
     $workemail=$row["workemail"];
     $homephone=$row["homephone"];
     $workphone=$row["workphone"];
     $cellphone=$row["cellphone"];
     $fax=$row["fax"];
     $title=$row["title"];
     $company=$row["company"];
     $notes=$row["notes"];
     $name=$row["name"];
     $homestreet=$row["homestreet"];
     $homepob=$row["homepob"];
     $homecity=$row["homecity"];
     $homeprovince=$row["homeprovince"];
     $homepostalcode=$row["homepostalcode"];
     $homecountry=$row["homecountry"];
     $workstreet=$row["workstreet"];
     $workpob=$row["workpob"];
     $workcity=$row["workcity"];
     $workprovince=$row["workprovince"];
     $workpostalcode=$row["workpostalcode"];
     $workcountry=$row["workcountry"];
     $url=$row["url"];

     $arrayContact["searchfield"] = "";

     if (isset($name)) {
         $arrayContact["firstname"] = truncateString($name, 60);
         $arrayContact["display_name"] = truncateString($name, 255);
         $arrayContact["searchfield"] = $arrayContact["searchfield"] . $arrayContact["firstname"];
     }
     if (isset($title)) {
         $arrayContact["title"] = truncateString($title, 30);
     }
     if (isset($company)) {
         $arrayContact["company"] = truncateString($company, 60);
         $arrayContact["searchfield"] = $arrayContact["searchfield"] . $arrayContact["company"];
     }
     if (isset($workstreet)) {
         $arrayContact["work_address"] = truncateString($workstreet, 100);
     }
     if (isset($workcity)) {
         $arrayContact["work_city"] = truncateString($workcity, 30);
     }
     if (isset($workprov)) {
         $arrayContact["work_country"] = truncateString($workprov, 30);
     }
     if (isset($workpostalcode)) {
         $arrayContact["work_postalcode"] = truncateString($workpostalcode, 20);
     }
     if (isset($workcountry)) {
         $arrayContact["work_state"] =truncateString($workcountry, 30);
     }
     if (isset($workphone)) {
         $arrayContact["work_telephone"] = truncateString($workphone, 50);
     }
     if (isset($fax)) {
         $arrayContact["work_fax"] = truncateString($fax, 50);
     }
     if (isset($workemail)) {
         $arrayContact["work_email"] = truncateString($workemail, 80);
     }
     if (isset($homestreet)) {
         $arrayContact["home_address"] = truncateString($homestreet, 100);
     }
     if (isset($homecity)) {
         $arrayContact["home_city"] = truncateString($homecity, 30);
     }
     if (isset($homeprov)) {
         $arrayContact["home_country"] = truncateString($homeprov, 30);
     }
     if (isset($homepostalcode)) {
         $arrayContact["home_postalcode"] = truncateString($homepostalcode, 20);
     }
     if (isset($homecountry)) {
         $arrayContact["home_state"] = truncateString($homecountry, 30);
     }
     if (isset($homphone)) {
         $arrayContact["home_telephone"] = truncateString($homphone, 50);
     }
     if (isset($cellphone)) {
         $arrayContact["work_mobile"] = truncateString($cellphone, 50);
         $arrayContact["home_mobile"] = truncateString($cellphone, 50);
     }

     if (isset($homeemail)) {
         $arrayContact["home_email"] = truncateString($homeemail, 80);
     }
     $arrayContact["revision_timestamp"] = "NOW()";
     $arrayContact["revision_status"] = "N";
     if (isset($url)) {
         $arrayContact["url"] = truncateString($url, 200);
     }
     // ricavare calendar_id (WebTop di admin)
     $arrayContact["category_id"] = "$foldername";

     if (isset($notes)) {
         $arrayContact["notes"] = truncateString($notes, 2000);
     }
     $id = getGlobalKey($webtop_db,'SEQ_CONTACTS');
     $arrayContact["contact_id"] = $id;
     $arrayContact["category_id"] = getCategoryId($foldername,$user,$iddomain);
     $arrayContact["public_uid"] = uniqid();
     $arrayContact["href"] = $arrayContact["public_uid"].".vcf";
     $arrayContact["is_list"] = false;

     if(DEBUG) {
         echo "Importing $name $company di $user su $foldername.....";
         print_r($arrayContact);
     }
     $result_insert = pg_insert($webtop_db, 'contacts.contacts', $arrayContact);
     if ($result_insert == FALSE)
         if(DEBUG)
             echo "Error creating $name $company di $user su $foldername\n";
     if(DEBUG)
         echo "DONE\n";
     $num_contacts++;

}
if(DEBUG)
   echo "Imported $num_contacts contacts on Phonebook $foldername ($user)\n";

function getCategoryId($folderid,$login,$iddomain) {
        global $webtop_db;
        $result_cid = pg_query($webtop_db, "select category_id from contacts.categories where user_id='".$login."' and domain_id='".$iddomain."' and name = '".$folderid."';");
        if ($result_cid == FALSE)
            throw new Exception(pg_last_error($webtop_db));
        while ($row_cid = pg_fetch_row($result_cid)) {
            if (isset($row_cid[0])) {
                return $row_cid[0];
            }
        }
                return null;
}

function getGlobalKey() {
        global $webtop_db;
        $result_contact = pg_query($webtop_db, ("SELECT nextval('contacts.SEQ_CONTACTS') ;"));
        if ($result_contact == FALSE)
            throw new Exception(pg_last_error($webtop_db));
        while ($row_contact = pg_fetch_row($result_contact)) {
            if (isset($row_contact[0])) {
                return $row_contact[0];
            }
        }
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

