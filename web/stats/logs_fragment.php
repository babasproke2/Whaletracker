<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=10');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 60;
$data = wt_get_cached_logs($limit);
echo $data['html'] ?? '';
