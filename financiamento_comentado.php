<?php
// Página de cálculo de financiamento (Tabela Price)
// Versão comentada: cada linha contém uma explicação concisa em português

// Desativa exibição direta de erros no navegador (ajuda a não misturar HTML/JSON)
ini_set('display_errors', '0');
// Define o nível de relatório de erros: todos exceto notices e warnings
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
// Se não houver buffer de saída ativo, inicia um para evitar saída precoce
if (!ob_get_level()) {
    ob_start();
}

// Função que tenta obter uma taxa a partir da BrasilAPI (/taxas/v1)
function buscarTaxaApi(): array
{
    // URL da API usada para tentar obter uma taxa
    $url = 'https://brasilapi.com.br/api/taxas/v1';
    // Inicializa variáveis para resposta e erro
    $resp = false;
    $err = null;

    // Primeiro, tenta usar cURL se disponível no ambiente
    if (function_exists('curl_init')) {
        $ch = curl_init($url); // inicia sessão cURL com a URL
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // pede retorno como string
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // timeout de 10 segundos
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json', // header aceita JSON
            'User-Agent: PHP-financiamento-client/1.0' // identifica o cliente
        ]);
        $resp = curl_exec($ch); // executa a requisição e guarda a resposta
        $err = curl_error($ch); // captura mensagem de erro do cURL, se houver
        curl_close($ch); // fecha recurso cURL
    } else {
        // fallback: tenta usar file_get_contents com stream context
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: PHP-financiamento-client/1.0\r\n",
                'timeout' => 10,
            ]
        ];
        $context = stream_context_create($opts); // cria contexto com headers e timeout
        $resp = @file_get_contents($url, false, $context); // tenta buscar o conteúdo (silencia warnings)
        if ($resp === false) {
            $last = error_get_last(); // obtém último erro do PHP
            $err = $last ? $last['message'] : 'file_get_contents falhou'; // define mensagem de erro
        }
    }

    // Se não houve resposta válida, retorna estrutura de erro
    if ($resp === false || $resp === null) {
        return ['ok' => false, 'message' => 'Erro ao conectar na API: ' . $err];
    }

    // Decodifica JSON recebido para array associativo
    $data = json_decode($resp, true);
    if ($data === null) {
        // JSON inválido
        return ['ok' => false, 'message' => 'Resposta inválida da API'];
    }

    // Tenta extrair o primeiro valor numérico encontrado na resposta
    $valor = null;
    if (is_array($data)) {
        // Se for uma lista/array, varre itens procurando chaves prováveis
        foreach ($data as $item) {
            if (is_array($item)) {
                foreach (['valor', 'taxa', 'percentual', 'numero'] as $k) {
                    if (isset($item[$k]) && is_numeric($item[$k])) {
                        $valor = (float)$item[$k];
                        break 2; // sai dos dois loops ao encontrar
                    }
                }
                // Se não encontrou por chave, procura o primeiro valor numérico no item
                foreach ($item as $v) {
                    if (is_numeric($v)) {
                        $valor = (float)$v; // captura primeiro número
                        break 2; // sai dos loops
                    }
                }
            }
        }
    } elseif (is_numeric($data)) {
        // Se a resposta for um simples número
        $valor = (float)$data;
    }

    // Se não foi possível extrair valor numérico, retorna erro
    if ($valor === null) {
        return ['ok' => false, 'message' => 'Não foi possível extrair taxa da resposta da API'];
    }

    // Retorna sucesso com o valor encontrado e a resposta bruta
    return ['ok' => true, 'valor' => $valor, 'raw' => $data];
}

// Endpoint simples para retornar taxa via AJAX quando chamado com ?acao=taxa
if (isset($_GET['acao']) && $_GET['acao'] === 'taxa') {
    header('Content-Type: application/json; charset=utf-8'); // define header JSON
    $res = buscarTaxaApi(); // busca taxa na API
    echo json_encode($res); // envia JSON de resposta
    exit; // encerra execução
}

// Inicializa variáveis usadas pelo processamento do formulário
$erro = '';
$resultado = null;

// Se o formulário foi submetido via POST, processa os dados
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Valor presente: converte vírgula para ponto e para float
    $pv = isset($_POST['pv']) ? (float)str_replace(',', '.', $_POST['pv']) : 0.0;
    // Entrada: converte vírgula para ponto e para float
    $entrada = isset($_POST['entrada']) ? (float)str_replace(',', '.', $_POST['entrada']) : 0.0;
    // Número de parcelas: converte para inteiro
    $n = isset($_POST['parcelas']) ? (int)$_POST['parcelas'] : 0;
    // Taxa informada pelo usuário (opcional): vazio => null
    $juros_input = isset($_POST['juros']) && $_POST['juros'] !== '' ? (float)str_replace(',', '.', $_POST['juros']) : null;

    // Validação simples: pv e parcelas devem ser positivos
    if ($pv <= 0 || $n <= 0 || $entrada < 0 || $entrada >= $pv) {
        $erro = 'Informe o valor do principal, entrada e o número de parcelas válidos.';
    } else {
        // Se o usuário não informou juros, tenta obter pela API
        if ($juros_input === null) {
            $taxaApi = buscarTaxaApi(); // chama função que consulta BrasilAPI
            if ($taxaApi['ok']) {
                // Assume-se que o valor retornado é em percentual anual (ex: 13.75)
                $annualPercent = (float)$taxaApi['valor']; // percentual anual
                $annualDecimal = $annualPercent / 100.0; // converte para decimal anual
                $i = pow(1 + $annualDecimal, 1 / 12) - 1; // calcula taxa mensal equivalente (composta)
                $fonte = 'API (assumida anual → convertida para mensal)'; // texto fonte
                $taxaUsadaPercentMensal = ($i * 100); // taxa mensal em percentuais
            } else {
                // Se a API falhar, define mensagem de erro
                $erro = 'Não foi possível obter taxa da API: ' . $taxaApi['message'];
            }
        } else {
            // Usuario informou taxa: usamos como taxa mensal em porcentagem
            $taxaUsadaPercentMensal = $juros_input; // percentual mensal informado
            $i = $taxaUsadaPercentMensal / 100.0; // converte para decimal
            $fonte = 'Entrada do usuário (mensal)'; // texto fonte apropriado
        }

        // Se não houve erro até aqui, realiza os cálculos da Tabela Price
        if ($erro === '') {
            // Calcula a prestação fixa: caso i == 0, divisão simples
            if ($i == 0.0) {
                $prestacao = $pv / $n; // sem juros
            } else if ($entrada > 0) {
                // Fórmula da Tabela Price para pagamento fixo com entrada
                $prestacao = ($pv - $entrada) * ($i * pow(1 + $i, $n)) / (pow(1 + $i, $n) - 1);
            } else {
                $prestacao = ($pv * $i) / (1 - pow(1 + $i, -$n));
            }

            // Monta a tabela de amortização linha a linha
            $saldo = $pv; // saldo devedor inicial
            $saldo = $pv - $entrada; // saldo devedor após entrada
            $linhas = [];
            $totalPago = 0.0;
            $totalJuros = 0.0;
            for ($m = 1; $m <= $n; $m++) {
                $jurosParcela = $saldo * $i; // juros sobre saldo
                $amortizacao = $prestacao - $jurosParcela; // parte que amortiza o principal
                $saldo = max(0.0, $saldo - $amortizacao); // atualiza saldo (evita negativo por arredondamento)
                $linhas[] = [
                    'parcela' => $m, // número da parcela
                    'prestacao' => $prestacao, // valor fixo da prestação
                    'juros' => $jurosParcela, // juros desta parcela
                    'amortizacao' => $amortizacao, // amortização desta parcela
                    'saldo' => $saldo, // saldo após pagamento
                ];
                $totalPago += $prestacao; // acumula total pago
                $totalJuros += $jurosParcela; // acumula total de juros
            }

            // Prepara array de resultado para uso na exibição HTML
            $resultado = [
                'pv' => $pv,
                'entrada' => $entrada,
                'n' => $n,
                'taxa_mensal_percent' => $taxaUsadaPercentMensal,
                'fonte' => $fonte,
                'prestacao' => $prestacao,
                'linhas' => $linhas,
                'total_pago' => $totalPago,
                'total_juros' => $totalJuros,
            ];
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8"> <!-- define encoding UTF-8 -->
    <meta name="viewport" content="width=device-width,initial-scale=1"> <!-- torna responsivo -->
    <title>Calculadora de Financiamento (Tabela Price)</title> <!-- título da página -->
    <style>
        /* Estilos inline: definem aparência básica da página */
        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 20px;
            background: #f7f9fc
        }


        .card {
            background: #fff;
            padding: 18px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .06);
            width: 900px;
            margin: auto
        }

        label {
            display: block;
            margin: 10px 0 6px
        }

        input[type=number],
        input[type=text] {
            width: 80%;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #ccd
        }


        .row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .col {
            flex: 1
        }


        button {
            background: #0078d4;
            color: #fff;
            border: 0;
            padding: 10px 14px;
            border-radius: 6px;
            cursor: pointer
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px
        }
        
        /* Torna a tabela rolável horizontalmente quando necessário
           e ativa smooth scrolling em dispositivos touch */
        .wrapper-table {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        th,
        td {
            padding: 8px;
            border-bottom: 1px solid #eee;
            text-align: right
        }

        th {
            background: #f3f6fb;
            text-align: center
        }

        .muted {
            color: #666;
            font-size: 0.9em
        }


        
        @media screen and (max-width: 768px) {
            .card {
                width: 90%;
            }

            .row{
                font-size: 14px;
                display: flex;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="card">
        <h2>Calculadora de Financiamento — Tabela Price</h2>
        <p class="muted">Preencha o valor e número de parcelas. Se não informar a taxa mensal, tentaremos obter a taxa atual via BrasilAPI.</p>

        <?php if ($erro): ?>
            <!-- Se existir mensagem de erro, exibe-a com escape para evitar XSS -->
            <div style="background:#ffecec;padding:10px;border-radius:6px;color:#900;margin-bottom:12px"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <form method="post" id="formCalc"> <!-- formulário que envia dados via POST -->
            <div class="row">
                <div class="col">
                    <label>Valor do financiamento (R$)</label>
                    <!-- Campo pv: preserva valor enviado ou usa padrão 20000 -->
                    <input type="text" name="pv" required inputmode="decimal" value="<?= isset($_POST['pv']) ? htmlspecialchars($_POST['pv']) : '20000' ?>">
                </div>
                <div class="col">
                    <label>Entrada (R$) <span class="muted">(opcional)</span></label>
                    <!-- Campo entrada: preserva valor enviado ou usa padrão 0 -->
                    <input type="text" name="entrada" inputmode="decimal" placeholder="Ex: 5000">
                </div>
                <div class="col">
                    <label>Nº de parcelas</label>
                    <!-- Campo parcelas: preserva valor enviado ou usa padrão 12 -->
                    <input type="number" name="parcelas" min="1" required value="<?= isset($_POST['parcelas']) ? (int)$_POST['parcelas'] : 12 ?>">
                </div>
                <div class="col">
                    <label>Taxa mensal (%) <span class="muted">(opcional)</span></label>
                    <!-- Campo taxa: opcional, preenchido pelo usuário ou pelo botão AJAX -->
                    <input type="text" name="juros" id="juros" inputmode="decimal" placeholder="Ex: 1.5">
                    <!-- Botão que chama endpoint local ?acao=taxa via JavaScript -->
                    <button type="button" style="margin-top:8px;display:inline-block" id="btnTaxa">Obter taxa atual</button>
                </div>
            </div>
            <div style="margin-top:12px">
                <button type="submit">Calcular</button>
            </div>
        </form>

        <?php if ($resultado): ?>
            <!-- Se houve cálculo, exibe resultados formatados -->
            <h3 style="margin-top:18px">Resultado</h3>
            <p class="muted">Fonte: <?= htmlspecialchars($resultado['fonte']) ?> — Taxa mensal: <strong><?= number_format($resultado['taxa_mensal_percent'], 6, ',', '.') ?>%</strong></p>

            <p>Prestação mensal: <strong>R$ <?= number_format($resultado['prestacao'], 2, ',', '.') ?></strong></p>
            <p>Total pago (<?= $resultado['n'] ?>x): <strong>R$ <?= number_format($resultado['total_pago'], 2, ',', '.') ?></strong></p>
            <p>Entrada <strong>R$ <?= number_format($resultado['entrada'], 2, ',', '.') ?></strong></p>
            <p>Total de juros: <strong>R$ <?= number_format($resultado['total_juros'], 2, ',', '.') ?></strong></p>

            <div class="wrapper-table">
                <table>
                    <thead>
                        <tr>
                            <th>Parcela</th>
                            <th>Prestação (R$)</th>
                            <th>Juros (R$)</th>
                            <th>Amortização (R$)</th>
                            <th>Saldo (R$)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultado['linhas'] as $l): ?>
                            <tr>
                                <td style="text-align:center"><?= $l['parcela'] ?></td>
                                <td>R$ <?= number_format($l['prestacao'], 2, ',', '.') ?></td>
                                <td>R$ <?= number_format($l['juros'], 2, ',', '.') ?></td>
                                <td>R$ <?= number_format($l['amortizacao'], 2, ',', '.') ?></td>
                                <td>R$ <?= number_format($l['saldo'], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Script que chama endpoint local para obter taxa via AJAX
        document.getElementById('btnTaxa').addEventListener('click', function() {
            this.disabled = true;
            this.textContent = 'Buscando...'; // feedback visual
            fetch('?acao=taxa').then(r => r.json()).then(j => {
                if (j.ok && j.valor !== undefined) {
                    // Converte assumindo que j.valor é percentual anual
                    var annual = parseFloat(j.valor); // valor anual em %
                    var monthly = Math.pow(1 + (annual / 100), 1 / 12) - 1; // taxa mensal decimal
                    var monthlyPercent = monthly * 100; // taxa mensal em %
                    // Preenche campo juros usando vírgula como separador (interface BR)
                    document.getElementById('juros').value = monthlyPercent.toFixed(6).replace('.', ',');
                    alert('Taxa obtida: ' + annual + '% a.a. (convertida para ' + monthlyPercent.toFixed(6) + '% a.m.)');
                } else {
                    alert('Não foi possível obter a taxa: ' + (j.message || 'erro'));
                }
            }).catch(e => {
                alert('Erro: ' + e.message);
            }).finally(() => {
                this.disabled = false;
                this.textContent = 'Obter taxa atual';
            });
        });
    </script>
</body>

</html>