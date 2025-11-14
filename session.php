<?php
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["ok"=>false,"error"=>"unauthorized"]);
    exit;
}
?>
