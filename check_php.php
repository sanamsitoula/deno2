<?php
include("zklib/zklib.php");
$zk = new ZKLibrary('10.10.10.18', 4370);
$zk->connect();
$zk->disableDevice();
$zk->testVoice();
$zk->enableDevice();
$zk->disconnect();
?>