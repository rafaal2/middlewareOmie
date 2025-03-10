<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
ini_set('max_execution_time', 0);
set_time_limit(0);

global $conn;
include __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

include __DIR__ . '/Models/produtos/index.php';

$acao = isset($_GET['acao']) ? $_GET['acao'] : null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($acao) {
    case 'importar':
        importarProdutosTotal($conn);
        break;
    case 'apagar':
        limparProdutos($conn);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Ação inválida. Use ?acao=importar ou ?acao=apagar'], JSON_UNESCAPED_UNICODE);
        break;
}

$conn->close();
