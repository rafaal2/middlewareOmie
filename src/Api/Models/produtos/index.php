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

    $tentativasMax = 3;

    for ($pagina = $startPage; $pagina <= $endPage; $pagina++) {
        $sucesso = false;

        for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
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

            if (!curl_errno($ch)) {
                $result = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($result['produto_servico_cadastro'])) {
                    $sucesso = true;
                    curl_close($ch);
                    break; // sucesso, sai do retry
                }
            }

            curl_close($ch);
            sleep(2); // espera 2 segundos antes de tentar novamente
        }

        if (!$sucesso) {
            $logMessages[] = "Página $pagina falhou após 3 tentativas.";
            error_log("Falha definitiva após 3 tentativas na página $pagina.");
            continue; // pula para próxima página sem parar a execução inteira
        }

        if (!$sucesso) {
            continue; // avança para próxima página caso falhe após tentativas
        }

        $produtos = $result['produto_servico_cadastro'];
        foreach ($produtos as $produto) {
            $descricao = isset($produto['descricao']) ? $produto['descricao'] : '';
            $preco     = isset($produto['valor_unitario']) ? $produto['valor_unitario'] : 0.0;
            $ean13     = isset($produto['ean']) ? $produto['ean'] : '';
            $codOmie   = isset($produto['codigo']) ? $produto['codigo'] : '';

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
        processBatch($conn, $nextBatchStart, $batchSize, $totalPaginas, $stmt, $totalInseridos, $logMessages);
    } else {
        $logMessages[] = "Processamento finalizado.";
    }
}

function importarProdutosTotal(mysqli $conn) {
    set_time_limit(0);

    if (!limparProdutos($conn)) {
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao limpar produtos antes da importação.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $url = 'https://app.omie.com.br/api/v1/geral/produtos/';
    $payload = [
        "app_key"    => "1342738657260",
        "app_secret" => "ca75177c3522a6cd640d18ac767dd901",
        "call"       => "ListarProdutos",
        "param"      => [
            [
                "pagina"                => 1,
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
        http_response_code(500);
        echo json_encode(['error' => 'Erro inicial ao consultar API: ' . curl_error($ch)], JSON_UNESCAPED_UNICODE);
        curl_close($ch);
        return;
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($result['total_de_paginas'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao obter total de páginas da API.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $totalPaginas = (int)$result['total_de_paginas'];

    $batchSize = 10;
    $startPage = 1;

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
    $sql = "DELETE FROM produtos; ALTER TABLE produtos AUTO_INCREMENT = 1;";
    if (!$conn->multi_query($sql)) {
        error_log('Erro ao executar comandos: ' . $conn->error);
        return false;
    }
    do {
        if ($resultado = $conn->store_result()) {
            $resultado = $conn->next_result();
        }
    } while ($conn->more_results() && $conn->next_result());

    return true;
}



