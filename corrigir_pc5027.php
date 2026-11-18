<?php
/**
 * Correção única do PC 5027 (v3.53.0). Rodar no servidor, na pasta do sistema:
 *   php corrigir_pc5027.php          -> só mostra o que mudaria (nada é gravado)
 *   php corrigir_pc5027.php --apply  -> grava (faz cópia de segurança antes)
 * O que houve: o conferente deu entrada manual no 5027 com 30 BALACLAVAS da NF 5593 (Layane Logerfo Tossato, R$ 360,00).
 * O PC 5027 de verdade é da CDM (8 armários de aço, R$ 6.280,00) e ainda NÃO foi recebido.
 * Faz: (1) devolve o 5027 ao pedido real, "em preparação"; (2) guarda o recebimento das balaclavas como entrada AVULSA
 * (continuam no estoque, sem PC), para você ligar ao pedido certo depois; (3) tira a NF 5593 do protocolo ao Financeiro do 5027.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Somente linha de comando.'); }
$apply = in_array('--apply', $argv, true);
$dir = __DIR__ . '/data'; $out = [];
$ler = fn($f) => json_decode((string)file_get_contents("$dir/$f"), true);
$grv = function ($f, $d) use ($dir, $apply) { if (!$apply) return; copy("$dir/$f", "$dir/$f.antes-5027.json"); file_put_contents("$dir/$f", json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX); };

$it = $ler('items.json'); $ok = false;
foreach ($it as &$p) if ((int)$p['pc'] === 5027) {
    if (stripos((string)$p['fornecedor'], 'CDM') !== false) { $out[] = 'items.json: 5027 já está corrigido.'; $ok = true; break; }
    $snap = array_intersect_key($p, array_flip(['fornecedor', 'material', 'numeroNF', 'chaveNF', 'valorNF', 'cnpjEmitente', 'insumos', 'entregas', 'fotosProdutos', 'primeira_confirmacao_em']));
    $p['historico_retificacoes'][] = ['em' => date('c'), 'por' => 'correcao-5027', 'motivo' => 'Entrada manual de balaclavas (NF 5593 Layane) lançada no PC 5027 por engano; pedido real é da CDM', 'antes' => $snap];
    foreach (['chaveNF', 'fotoNF', 'nfResumo', 'numeroNF', 'serieNF', 'cnpjEmitente', 'emissaoNF', 'valorNF', 'itensNF', 'origemNF', 'xmlNF', 'chegou', 'data', 'qtd', 'origem_entrega', 'recebimento_estado', 'obs', 'urgente'] as $k) $p[$k] = '';
    $p += ['x' => 1]; unset($p['x']);
    $p['fornecedor'] = 'CDM-CENTRO DE DISTRIBUICAO DE MOVEIS LTDA'; $p['material'] = 'ARMARIO ACO COM 4 PORTAS';
    $p['situacao'] = 'EM PREPARAÇÃO'; $p['situacao_origem'] = 'PENDENTE'; $p['entregas'] = []; $p['fotosProdutos'] = [];
    $p['primeira_confirmacao_em'] = null; $p['recebimento_iniciado_em'] = null; $p['recebimento_finalizado_em'] = null; $p['ultima_acao'] = 'Pedido corrigido (PC 5027 CDM)'; $p['ultima_acao_em'] = date('c'); $p['updated_at'] = date('c');
    $p['insumos'] = [['id' => 'item_1', 'sku' => 'UC000491', 'descricao' => 'ARMARIO ACO COM 4 PORTAS', 'unidade' => 'UN', 'quantidade_pedida' => 8, 'quantidade_recebida' => 0, 'quantidade_pendente' => 8, 'conferido_etapa' => false]];
    $p['destino_setor'] = 'DEPOSITO'; $p['finalidade_pc'] = 'COMPRA U/C - DEPOSIT'; $p['observacoes_pc'] = 'APLICAÇÃO - CONSUMO DEPOSITO SOLICITANTE - DAIANA Baseado em Solicitação de compra 4831. Valor dos Produtos: R$ 6.632,00 Desconto: R$ 352,00 Valor Total: R$ 6.280,00';
    $out[] = 'items.json: 5027 -> CDM, 8 armários (UC000491), sem NF, não recebido.'; $ok = true; break;
} unset($p);
if (!$ok) $out[] = 'items.json: PC 5027 não encontrado.'; else $grv('items.json', $it);

$e = $ler('estoque.json');
if (isset($e['recebimentos']['PC-5027'])) {
    $doc = $e['recebimentos']['PC-5027']; $doc['pc'] = null; $doc['observacao'] = 'Balaclavas NF 5593 (Layane). Entrada lançada no PC 5027 por engano; ligar ao pedido correto. ' . ($doc['observacao'] ?? '');
    $e['recebimentos']['AVULSA-NF5593'] = $doc; unset($e['recebimentos']['PC-5027']);
    foreach ($e['movimentos'] as &$m) if ((int)($m['pc'] ?? 0) === 5027 && $m['tipo'] === 'ENTRADA_RECEBIMENTO') { $m['pc'] = null; $m['observacao'] = trim(($m['observacao'] ?? '') . ' [PC 5027 era engano; NF 5593 Layane]'); } unset($m);
    $out[] = 'estoque.json: 30 balaclavas continuam no estoque, agora como entrada avulsa (sem PC).'; $grv('estoque.json', $e);
} else $out[] = 'estoque.json: nada a mudar.';

$pr = $ler('protocolos_nf.json'); $n = 0;
$walk = function (&$x) use (&$walk, &$n) { if (is_array($x)) { if (isset($x['pc'], $x['numeroNF']) && (int)$x['pc'] === 5027 && (string)$x['numeroNF'] === '5593') { $x['pc'] = 5027; $x['pendente_correcao'] = 'NF 5593 (Layane) não pertence ao PC 5027 da CDM'; $n++; } foreach ($x as &$v) $walk($v); } };
$walk($pr); $out[] = "protocolos_nf.json: $n linha(s) marcadas (já enviadas não são apagadas; avise o Financeiro).";
if ($n) $grv('protocolos_nf.json', $pr);
echo implode("\n", $out), "\n", $apply ? "APLICADO (cópias: *.antes-5027.json)\n" : "SIMULAÇÃO — rode com --apply para gravar.\n";
