#!/usr/local/bin/php
<?php
/**
 * Copyright (C) 2025 Nethesis S.r.l.
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * This script extracts contacts from webtop and add them to phonebook.
 **/
set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));

// get phonebook connection details
$pbookhost = $_ENV["PHONEBOOK_DB_HOST"];
$pbookpass = $_ENV["PHONEBOOK_DB_PASSWORD"];
$pbookuser = $_ENV["PHONEBOOK_DB_USER"];
$pbookport = $_ENV["PHONEBOOK_DB_PORT"];
$pbookdb = $_ENV["PHONEBOOK_DB_NAME"];

$database = new mysqli($pbookhost, $pbookuser, $pbookpass, $pbookdb, $pbookport);
if ($database->connect_error) {
    die("Database error config: " . $database->connect_error);
}
$database->set_charset("utf8");

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

debug("Share IDs query: " . $sql);
$pgresult = pg_query($webtop_db, $sql);
if ($pgresult == FALSE) {
    throw new Exception(pg_last_error($webtop_db));
}

//create share IDs array
$ids = array();
while ($row = pg_fetch_assoc($pgresult)) {
    debug("Share ID: " . $row['instance']);
    $ids[] = $row['instance'];
}


debug("IDs: " . implode(', ', $ids));
if (empty($ids)) {
    debug("No ids, exiting");
    exit(0);
}

//Gat all shares using shares IDs
$sql = "SELECT core.shares.\"instance\"
    FROM core.shares
    INNER JOIN core.users ON (core.shares.user_uid=core.users.user_uid)
    WHERE (core.shares.share_id IN (" . implode(',', $ids) . "))
    AND (core.shares.service_id = 'com.sonicle.webtop.contacts')
    AND (core.shares.key = 'CATEGORY')";

debug("Share query: " . $sql);
$pgresult = pg_query($webtop_db, $sql);
if ($pgresult == FALSE) {
    throw new Exception(pg_last_error($webtop_db));
}

//Get category IDs
$categoryIDs = array();
while ($row = pg_fetch_assoc($pgresult)) {
    debug("Category ID: " . $row['instance']);
    $categoryIDs[] = $row['instance'];
}

//Get contacts query
$sql = "SELECT *
    FROM contacts.contacts
    WHERE contacts.category_id IN (" . implode(',', $categoryIDs) . ") AND contacts.revision_status != 'D'";
debug("Contacts query: " . $sql);
$pgresult = pg_query($webtop_db, $sql);
if ($pgresult == FALSE) {
    throw new Exception(pg_last_error($webtop_db));
}

// Remove WebTop contacts from centralized phonebook
$database->query('DELETE FROM phonebook WHERE sid_imported = "webtop"');

while ($row = pg_fetch_assoc($pgresult)) {
    debug("Processing contact: " . json_encode($row));

    foreach (['home_telephone', 'work_telephone', 'work_mobile', 'work_fax'] as $field) {
        if (isset($row[$field])) {
            $row[$field] = preg_replace('/^\+/', '00', $row[$field]);
            $row[$field] = preg_replace('/[^0-9]*/', '', $row[$field]);
        } else {
            $row[$field] = '';
        }
    }
    $stmt = $database->prepare("INSERT INTO phonebook (owner_id, type, sid_imported, homeemail, workemail, homephone, workphone, cellphone, fax, title, company, notes, name, homestreet, homecity, homeprovince, homepostalcode, homecountry, workstreet, workcity, workprovince, workpostalcode, workcountry, url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $owner_id = 'admin';
    $type = 'WebtOp';
    $sid_imported = 'webtop';
    $homeemail = $row["home_email"];
    $workemail = $row["work_email"];
    $homephone = $row["home_telephone"];
    $workphone = $row["work_telephone"];
    $cellphone = $row["work_mobile"];
    $fax = $row["work_fax"];
    $title = $row["title"];
    $company = $row["company"];
    $notes = $row["notes"];
    $name = $row["firstname"] . " " . $row["lastname"];
    $homestreet = $row["home_address"];
    $homecity = $row["home_city"];
    $homeprovince = $row["home_state"];
    $homepostalcode = $row["home_postalcode"];
    $homecountry = $row["home_country"];
    $workstreet = $row["work_address"];
    $workcity = $row["work_city"];
    $workprovince = $row["work_state"];
    $workpostalcode = $row["work_postalcode"];
    $workcountry = $row["work_country"];
    $url = $row["url"];

    $stmt->bind_param(
        'ssssssssssssssssssssssss',
        $owner_id,
        $type,
        $sid_imported,
        $homeemail,
        $workemail,
        $homephone,
        $workphone,
        $cellphone,
        $fax,
        $title,
        $company,
        $notes,
        $name,
        $homestreet,
        $homecity,
        $homeprovince,
        $homepostalcode,
        $homecountry,
        $workstreet,
        $workcity,
        $workprovince,
        $workpostalcode,
        $workcountry,
        $url
    );
    $stmt->execute();
    $stmt->close();
}


function truncateString($string, $size)
{
    if (strlen($string) <= $size)
        return $string;
    else
        return substr($string, 0, $size - 1);
}

function is_html($string)
{
    return preg_match("/<[^<]+>/", $string) != 0;
}

function debug($msg)
{
    if (getenv('DEBUG')) {
        echo $msg . "\n";
    }
}