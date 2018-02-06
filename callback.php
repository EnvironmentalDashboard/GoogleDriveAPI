<?php
require 'vendor/autoload.php';

$client = new Google_Client();
$client->setAuthConfigFile('secret/client_secret.json');
$client->setRedirectUri('https://' . $_SERVER['HTTP_HOST'] . '/google-drive/callback');
$client->setAccessType('offline'); // https://stackoverflow.com/a/43412638/2624391
$client->setApprovalPrompt('force');
$client->addScope(Google_Service_Drive::DRIVE);

if (isset($_GET['code'])) {
  $access_token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
  // $client->setAccessToken($access_token); // need to do this on index.php
  setcookie("GoogleAPICredentials", json_encode($access_token), time()+(60*60*24*1), "/");
  $redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . '/google-drive/';
  header('Location: ' . filter_var($redirect_uri, FILTER_SANITIZE_URL));
} else {
	$auth_url = $client->createAuthUrl();
  header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
}
?>