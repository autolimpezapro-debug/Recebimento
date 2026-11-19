<?php
/**
 * Recebimento Drilling — Cadastros duplicados no estoque (v3.57.0).
 * Duplicidade típica: o PC chega sem SKU e a descrição do pedido ("PARAFLU P/ USO ORG ROSA 20L") difere do cadastro do SAP
 * ("ADITIVO PARA ARREFECIMENTO PARAFLU"); o sistema não casa e cria outro item. Aqui: (1) sugestões de possíveis duplicados,
 * (2) unificação auditada: o saldo do duplicado vai para o item mantido (com fator de conversão de unidade), as descrições
 * viram apelidos (próximas entradas casam sozinhas), e o duplicado fica como "unificado" — histórico nunca é apagado.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/estoque_lib.php';

const DUP_STOP = ['DE', 'DA', 'DO', 'DAS', 'DOS', 'PARA', 'P', 'PRA', 'COM', 'EM', 'E', 'A', 'O', 'OS', 'AS', 'UM', 'UMA', 'USO', 'TIPO', 'MODELO', 'MARCA', 'UN', 'UND', 'PC', 'PCS', 'PECA', 'PECAS', 'KG', 'L', 'LT', 'ML', 'MM', 'CM', 'M', 'ORG', 'IMP', 'NORMAL', 'COMUM', 'PADRAO'];

function dup_tokens(string $desc): array {
    $out = [];
    foreach (preg_split('/\s+/', strtoupper(est_norm_key($desc)), -1, PREG_SPLIT_NO_EMPTY) as $t) {
        if (in_array($t, DUP_STOP, true) || mb_strlen($t) < 3 || preg_match('/^\d+$/', $t)) continue;
        $out[$t] = true;
    }
    return array_keys($out);
}
function dup_ativo(array $it): bool { return empty($it['unificado_para']); }
function dup_view(string $cod, array $it): array {
    return ['codigo' => (string)$cod, 'descricao' => (string)$it['descricao'], 'unidade' => (string)($it['unidade'] ?? 'UN'), 'saldo' => round((float)$it['saldo'], 4),
        'reservado' => round((float)($it['reservado'] ?? 0), 4), 'localizacao' => (string)($it['localizacao'] ?? ''), 'provisorio' => str_starts_with((string)$cod, 'EST-'),
        'importado' => !empty($it['importado']), 'custo_medio' => round((float)($it['custo_medio'] ?? 0), 4)];
}
function dup_chave(string $a, string $b): string { $x = [$a, $b]; sort($x, SORT_STRING); return implode('|', $x); }

/** Pares de itens ativos que dividem palavras raras (≤3 itens as usam) — candidatos a cadastro duplicado. */
function dup_sugestoes(array $db, int $max = 60): array {
    $tok = []; $df = [];
    foreach ($db['itens'] ?? [] as $c => $it) {
        if (!dup_ativo($it)) continue;
        $t = dup_tokens($it['descricao'] ?? ''); $tok[(string)$c] = $t;
        foreach ($t as $w) $df[$w] = ($df[$w] ?? 0) + 1;
    }
    $idx = [];
    foreach ($tok as $c => $t) foreach ($t as $w) if (mb_strlen($w) >= 5 && $df[$w] >= 2 && $df[$w] <= 3) $idx[$w][] = $c;
    $pares = []; $ign = array_flip((array)($db['duplicados_ignorados'] ?? []));
    foreach ($idx as $w => $cods) {
        for ($i = 0; $i < count($cods); $i++) for ($j = $i + 1; $j < count($cods); $j++) {
            $k = dup_chave((string)$cods[$i], (string)$cods[$j]); if (isset($ign[$k])) continue;
            $pares[$k]['a'] = (string)$cods[$i]; $pares[$k]['b'] = (string)$cods[$j]; $pares[$k]['comuns'][] = $w; $pares[$k]['score'] = ($pares[$k]['score'] ?? 0) + 1 / $df[$w];
        }
    }
    $out = [];
    foreach ($pares as $k => $p) {
        $A = $db['itens'][$p['a']]; $B = $db['itens'][$p['b']];
        $ea = str_starts_with($p['a'], 'EST-'); $eb = str_starts_with($p['b'], 'EST-');
        $score = $p['score'] + (($ea xor $eb) ? 0.6 : 0);
        // sugestão: manter o item com código SAP (não provisório); se iguais, o de maior saldo
        $manterA = $ea === $eb ? ((float)$A['saldo'] >= (float)$B['saldo']) : $eb;
        $out[] = ['chave' => $k, 'score' => round($score, 2), 'comuns' => $p['comuns'], 'a' => dup_view($p['a'], $A), 'b' => dup_view($p['b'], $B), 'manter' => $manterA ? $p['a'] : $p['b']];
    }
    usort($out, fn($x, $y) => $y['score'] <=> $x['score']);
    return array_slice($out, 0, $max);
}

function dup_ignorar(array &$db, string $a, string $b, string $usuario): void {
    $k = dup_chave($a, $b); $db['duplicados_ignorados'] = array_values(array_unique(array_merge((array)($db['duplicados_ignorados'] ?? []), [$k])));
    est_log_event('estoque', "Duplicidade $k marcada como 'são itens diferentes' por $usuario");
}

/** Unifica $de dentro de $para. $fator = quantas unidades do item mantido equivalem a 1 unidade do duplicado (ex.: galão 20L → 20). */
function dup_unificar(array &$db, string $de, string $para, float $fator, string $motivo, string $usuario): array {
    if ($de === $para) est_fail('Escolha dois cadastros diferentes.');
    if (!isset($db['itens'][$de]) || !isset($db['itens'][$para])) est_fail('Item não encontrado.', 404);
    if (!dup_ativo($db['itens'][$de]) || !dup_ativo($db['itens'][$para])) est_fail('Um dos cadastros já foi unificado.', 409);
    if (!is_finite($fator) || $fator <= 0 || $fator > 100000) est_fail('Fator de conversão inválido.');
    $motivo = est_str($motivo, 200); if (mb_strlen($motivo) < 3) est_fail('Informe o motivo da unificação (ex.: cadastro duplicado por descrição diferente no PC).');
    $D = $db['itens'][$de]; $P = $db['itens'][$para];
    $saldoDe = round((float)$D['saldo'], 4); $resDe = round((float)($D['reservado'] ?? 0), 4);
    $doc = 'UNIF-' . date('Ymd-His'); $movs = [];
    // 1) reservas ativas impedem (o vínculo reserva→item precisaria ser refeito à mão); linhas de recebimento de PC só passam a apontar
    //    para o item mantido quando as unidades são equivalentes (fator 1) — com conversão, a linha do PC ficaria em unidade errada.
    if ($resDe > 0) est_fail("O cadastro $de tem $resDe unidade(s) reservada(s). Libere ou baixe a reserva antes de unificar.", 409);
    $pcsMovidos = 0;
    if (abs($fator - 1.0) < 1e-9) foreach (array_keys($db['recebimentos'] ?? []) as $rk) foreach (array_keys($db['recebimentos'][$rk]['linhas'] ?? []) as $li) {
        if (($db['recebimentos'][$rk]['linhas'][$li]['codigo'] ?? '') === $de) { $db['recebimentos'][$rk]['linhas'][$li]['codigo'] = $para; $db['recebimentos'][$rk]['linhas'][$li]['codigo_anterior'] = $de; $pcsMovidos++; }
    }
    // 2) saldo: sai do duplicado e entra no mantido, convertido (movimentos auditáveis; histórico antigo fica intacto)
    $obs = "Unificação de cadastro duplicado: $de → $para (fator $fator). $motivo";
    if ($saldoDe > 0) {
        $movs[] = est_apply_movement($db, ['tipo' => 'AJUSTE_NEGATIVO', 'codigo' => $de, 'quantidade' => $saldoDe, 'origem' => 'UNIFICACAO', 'documento' => $doc, 'usuario' => $usuario, 'observacao' => $obs]);
        $movs[] = est_apply_movement($db, ['tipo' => 'AJUSTE_POSITIVO', 'codigo' => $para, 'quantidade' => round($saldoDe * $fator, 4), 'origem' => 'UNIFICACAO', 'documento' => $doc, 'usuario' => $usuario, 'observacao' => $obs]);
    } elseif ($saldoDe < 0) {
        $movs[] = est_apply_movement($db, ['tipo' => 'AJUSTE_INVENTARIO', 'codigo' => $de, 'quantidade' => 0, 'origem' => 'UNIFICACAO', 'documento' => $doc, 'usuario' => $usuario, 'observacao' => $obs]);
    }
    // 3) o mantido herda descrições/códigos do duplicado como apelidos (próximas entradas casam sozinhas) e os ids de origem
    $al = array_merge((array)($db['itens'][$para]['aliases'] ?? []), (array)($D['aliases'] ?? []), [$D['descricao']], str_starts_with($de, 'EST-') ? [] : [$de]);
    $db['itens'][$para]['aliases'] = array_slice(array_values(array_filter(array_unique($al), fn($a) => $a !== $P['descricao'])), 0, 40);
    $db['itens'][$para]['origem_ids'] = array_values(array_unique(array_merge((array)($db['itens'][$para]['origem_ids'] ?? []), (array)($D['origem_ids'] ?? []))));
    foreach (['minimo', 'maximo', 'ponto_pedido'] as $k) if (empty($db['itens'][$para][$k]) && !empty($D[$k])) $db['itens'][$para][$k] = round((float)$D[$k] * $fator, 4);
    if (empty($db['itens'][$para]['localizacao']) && !empty($D['localizacao'])) $db['itens'][$para]['localizacao'] = $D['localizacao'];
    $db['itens'][$para]['unificados'][] = ['de' => $de, 'descricao' => $D['descricao'], 'unidade' => $D['unidade'] ?? '', 'fator' => $fator, 'saldo_transferido' => $saldoDe, 'em' => est_now(), 'por' => $usuario, 'motivo' => $motivo, 'documento' => $doc];
    // 4) o duplicado vira "unificado": some das listas/buscas, não recebe entradas (est_match_item redireciona), histórico permanece
    $db['itens'][$de]['unificado_para'] = $para; $db['itens'][$de]['unificado_em'] = est_now(); $db['itens'][$de]['unificado_por'] = $usuario; $db['itens'][$de]['unificado_motivo'] = $motivo;
    est_log_event('estoque', "Cadastro $de unificado em $para (fator $fator, saldo transferido $saldoDe) por $usuario — $motivo");
    return ['documento' => $doc, 'de' => $de, 'para' => $para, 'saldo_transferido' => $saldoDe, 'saldo_final' => round((float)$db['itens'][$para]['saldo'], 4), 'movimentos' => array_column($movs, 'id'), 'linhas_pc_movidas' => $pcsMovidos,
        'aviso' => abs($fator - 1.0) < 1e-9 ? '' : 'Com conversão de unidade, as linhas de PC antigas continuam no cadastro antigo (saldo zerado). Faça novas saídas pelo item mantido.'];
}
