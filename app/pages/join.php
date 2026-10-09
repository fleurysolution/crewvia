<?php
require_once __DIR__.'/../intake.php';
if($_SERVER['REQUEST_METHOD']==='POST'){intake_receive();exit;}
render('join');
