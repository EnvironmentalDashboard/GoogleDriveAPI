<?php
require 'vendor/autoload.php';

$client = new Google_Client();
$client->setAuthConfigFile('secret/client_secret.json');
$client->setRedirectUri('https://' . $_SERVER['HTTP_HOST'] . '/google-drive/callback');
$client->addScope(Google_Service_Drive::DRIVE); //::DRIVE_METADATA_READONLY

if (!isset($_GET['code'])) {
  $auth_url = $client->createAuthUrl();
  header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
} else {
  $client->authenticate($_GET['code']);
  $access_token = $client->getAccessToken();
  setcookie("GoogleAPICredentials", json_encode($access_token), time()+(60*60*24*90), "/");
   
  $redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . '/google-drive/';
  header('Location: ' . filter_var($redirect_uri, FILTER_SANITIZE_URL));
}
?>