<?php
/**
 *
 * @param mysqli $conn Conexão com o banco de dados.
 * @param int $startPage Página inicial do lote.
 * @param int $batchSize Tamanho fixo do lote (número de páginas).
 * @param int $totalPaginas Número máximo de páginas a processar.
 * @param mysqli_stmt $stmt Statement preparado para inserção.
 * @param int &$totalInseridos Variável acumuladora do total de registros inseridos.
 * @param array &$logMessages Array que acumula mensagens de log do processamento.
 */
function processBatch(mysqli $conn, $startPage, $batchSize, $totalPaginas, $stmt, &$totalInseridos, &$logMessages) {
    $endPage = $startPage + $batchSize - 1;
    if ($endPage > $totalPaginas) {
        $endPage = $totalPaginas;
    }

    $url = 'https://app.omie.com.br/api/v1/geral/produtos/';

    for ($pagina = $startPage; $pagina <= $endPage; $pagina++) {
        $payload = [
            "app_key"    => "1342738657260",
            "app_secret" => "ca75177c3522a6cd640d18ac767dd901",
            "call"       => "ListarProdutos",
            "param"      => [
                [
                    "pagina"                => $pagina,
                    "registros_por_pagina"  => 50,
                    "apenas_importado_api"  => "N",
                    "filtrar_apenas_omiepdv"=> "N"
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            error_log("Erro na requisição cURL na página $pagina: $err");
            $logMessages[] = "Erro na requisição cURL na página $pagina: $err";
            curl_close($ch);
            continue;
        }
        curl_close($ch);

        $result = json_decode($response, true);
        $jsonError = json_last_error_msg();

        if (json_last_error() !== JSON_ERROR_NONE || !isset($result['produto_servico_cadastro']) || !is_array($result['produto_servico_cadastro'])) {
            $logMessages[] = "Resposta inesperada na página $pagina: JSON Error: $jsonError. Raw: $response";
            error_log("Resposta inesperada na página $pagina: JSON Error: $jsonError. Raw: $response");
            break;
        }

        $produtos = $result['produto_servico_cadastro'];
        if (count($produtos) == 0) {
            $logMessages[] = "Nenhum produto encontrado na página $pagina. Encerrando lote.";
            break;
        }
        foreach ($produtos as $produto) {
            $descricao = isset($produto['descricao']) ? $produto['descricao'] : '';
            $preco     = isset($produto['valor_unitario']) ? $produto['valor_unitario'] : 0.0;
            $ean13     = isset($produto['ean']) ? $produto['ean'] : '';
            $codOmie   = isset($produto['codigo_produto_integracao']) ? $produto['codigo_produto_integracao'] : '';

            $stmt->bind_param("sdss", $descricao, $preco, $ean13, $codOmie);
            if (!$stmt->execute()) {
                $logMessages[] = "Erro ao inserir produto na página $pagina: " . $stmt->error;
                error_log("Erro ao inserir produto na página $pagina: " . $stmt->error);
            } else {
                $totalInseridos++;
            }
        }
    }

    $logMessages[] = "Lote processado: páginas $startPage até $endPage. Total inseridos até aqui: $totalInseridos.";

    $nextBatchStart = $endPage + 1;
    if ($nextBatchStart <= $totalPaginas) {
        $logMessages[] = "Iniciando próximo lote a partir da página $nextBatchStart.";
        // Chama recursivamente para o próximo lote
        processBatch($conn, $nextBatchStart, $batchSize, $totalPaginas, $stmt, $totalInseridos, $logMessages);
    } else {
        $logMessages[] = "Processamento finalizado.";
    }
}


function importarProdutosTotal(mysqli $conn) {
    set_time_limit(0);

    $batchSize = 10;
    // 900= n de paginas
    $totalPaginas = 900;
    $startPage = 0;

    $stmt = $conn->prepare("INSERT INTO produtos (descricao, preco, ean13, cod_omie) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao preparar statement: ' . $conn->error], JSON_UNESCAPED_UNICODE);
        return;
    }

    $totalInseridos = 0;
    $logMessages = [];

    processBatch($conn, $startPage, $batchSize, $totalPaginas, $stmt, $totalInseridos, $logMessages);

    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => "Importação finalizada. Total inseridos: $totalInseridos.",
        'log' => $logMessages
    ], JSON_UNESCAPED_UNICODE);
}


function limparProdutos(mysqli $conn) {
    $sql = "DELETE FROM produtos";
    if (!$conn->query($sql)) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao deletar produtos: ' . $conn->error], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode(['success' => true, 'message' => 'Todos os produtos foram deletados com sucesso'], JSON_UNESCAPED_UNICODE);
}

