<?php
/**
 * Recebimento Drilling — lista única de obras (v3.12.1).
 * Junta: 1) obras cadastradas no painel de Indicadores (data/indicadores.json → obras; se vazio, a lista padrão)
 *        2) obras que aparecem nos dados adicionais das NFs de remessa (destino/origem das remessas)
 * Um complementa o outro. O depósito/matriz não entra (é setor interno).
 */
if (!defined('DATA_DIR')) define('DATA_DIR', __DIR__ . '/data');

const RD_SETORES = ['OBRA' => 'Obra', 'DEPOSITO' => 'Depósito', 'CALDEIRARIA' => 'Caldeiraria', 'MANUTENCAO' => 'Manutenção', 'EXPEDICAO' => 'Expedição', 'LOGISTICA' => 'Logística', 'ADM' => 'ADM'];

function rd_obras_padrao(): array {
    return [
        ['id' => 'btec-congonhas', 'name' => 'BTEC MRB Terminal Avante', 'city' => 'Congonhas/MG'],
        ['id' => 'aura-belvedere', 'name' => 'Aura Belvedere Mall', 'city' => 'Belo Horizonte/MG'],
        ['id' => 'grande-sertao', 'name' => 'Grande Sertão', 'city' => 'São João do Paraíso/MG'],
        ['id' => 'capibaribe', 'name' => 'Ponte Capibaribe', 'city' => 'Recife/PE'],
        ['id' => 'aro-jardins', 'name' => 'ARO GSA Jardins Belvedere', 'city' => 'Belo Horizonte/MG'],
        ['id' => 'itaparica', 'name' => 'Ponte Salvador Itaparica', 'city' => 'Itaparica/BA'],
        ['id' => 'sinop', 'name' => 'Obra Sinop', 'city' => 'Sinop/MT'],
        ['id' => 'phv-sinval', 'name' => 'PHV Engenharia Ed. Sinval', 'city' => 'Belo Horizonte/MG'],
        ['id' => 'witplan-panamera', 'name' => 'Witplan BTS Panamera Corp', 'city' => 'Belo Horizonte/MG'],
        ['id' => 'agaspar-grauna', 'name' => 'Construtora Agaspar – Ponte Graúna', 'city' => 'São Paulo/SP'],
        ['id' => 'telar-blumenau', 'name' => 'Telar Blumenau', 'city' => 'Blumenau/SC'],
        ['id' => 'penedo', 'name' => 'Ponte Penedo – Neópolis', 'city' => 'Penedo/AL'],
        ['id' => 'nexa', 'name' => 'Nexa', 'city' => 'Minas Gerais'],
        ['id' => 'sogel', 'name' => 'Sogel', 'city' => 'Minas Gerais'],
        ['id' => 'aterpa-vale', 'name' => 'Aterpa (Vale)', 'city' => 'Minas Gerais'],
    ];
}

function rd_obras_lista(): array {
    $k = is_file(DATA_DIR . '/indicadores.json') ? json_decode((string)@file_get_contents(DATA_DIR . '/indicadores.json'), true) : [];
    $cad = !empty($k['obras']) && is_array($k['obras']) ? $k['obras'] : rd_obras_padrao();
    $out = [];
    $add = function ($id, $name, $city, $fonte) use (&$out) {
        $id = (string)$id; $name = trim((string)$name);
        if ($name === '' || $id === 'drilling-deposito' || preg_match('/^(drilling|dep[oó]sito|matriz)\b/iu', $name)) return;
        $chave = $id !== '' ? $id : mb_strtolower($name);
        if (!isset($out[$chave])) $out[$chave] = ['id' => $chave, 'name' => $name, 'city' => (string)$city, 'fonte' => $fonte];
        elseif ($out[$chave]['fonte'] !== $fonte && !str_contains($out[$chave]['fonte'], $fonte)) $out[$chave]['fonte'] .= '+' . $fonte;
    };
    foreach ($cad as $o) $add($o['id'] ?? '', $o['name'] ?? '', $o['city'] ?? '', 'cadastro');
    // obras citadas nas NFs de remessa (dados adicionais → destino/origem)
    $padroes = [];
    if (function_exists('kpi_obras_padrao')) foreach (kpi_obras_padrao() as $o) $padroes[$o['id']] = $o;
    else foreach (rd_obras_nf_conhecidas() as $o) $padroes[$o['id']] = $o;
    foreach ((array)($k['movimentos'] ?? []) as $m) foreach (['destId', 'originId'] as $c) {
        $id = (string)($m[$c] ?? '');
        if ($id === '' || isset($out[$id])) continue;
        $p = $padroes[$id] ?? null;
        $add($id, $p['name'] ?? ucwords(str_replace('-', ' ', $id)), $p['city'] ?? '', 'NF');
    }
    $l = array_values($out);
    usort($l, fn($a, $b) => strcoll($a['name'], $b['name']));
    // v3.19.1: Depósito (Drilling) sempre disponível no topo da lista
    array_unshift($l, ['id' => 'deposito', 'name' => 'Depósito', 'city' => 'São José da Lapa/MG', 'fonte' => 'fixo']);
    return $l;
}

/** Nomes das obras reconhecidas pelo leitor de NF (quando indicadores/api.php não está carregado). */
function rd_obras_nf_conhecidas(): array {
    return array_merge(rd_obras_padrao(), [
        ['id' => 'rivoli', 'name' => 'Rivoli', 'city' => 'BA'],
        ['id' => 'conartes', 'name' => 'Conartes', 'city' => ''],
        ['id' => 'governador-valadares', 'name' => 'Governador Valadares', 'city' => 'Governador Valadares/MG'],
    ]);
}

/** Normaliza setor + obra recebidos do formulário. Retorna [setor, obra_id, rótulo]. */
function rd_setor_destino($setor, $obraId, $obraNome = ''): array {
    $setor = strtoupper(trim((string)$setor));
    if (!isset(RD_SETORES[$setor])) return ['', '', ''];
    if ($setor !== 'OBRA') return [$setor, '', mb_strtoupper(RD_SETORES[$setor])];
    $obraId = trim((string)$obraId);
    foreach (rd_obras_lista() as $o) if ($o['id'] === $obraId || ($obraNome !== '' && mb_strtolower($o['name']) === mb_strtolower(trim((string)$obraNome))))
        return ['OBRA', $o['id'], 'OBRA · ' . mb_strtoupper($o['name'])];
    $n = mb_substr(trim((string)$obraNome), 0, 80);
    return $n !== '' ? ['OBRA', '', 'OBRA · ' . mb_strtoupper($n)] : ['', '', ''];
}


/* ================================================================== */
/* v3.20.0 — destino do pedido (obra ou setor) lido do texto do PC      */
/* ================================================================== */
function rd_txt_chave(string $s): string {
    $s = mb_strtoupper($s, 'UTF-8');
    $s = strtr($s, ['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C']);
    return ' ' . trim(preg_replace('/[^A-Z0-9]+/', ' ', $s)) . ' ';
}
/** Palavras que identificam cada obra no texto do pedido (além do próprio nome). */
function rd_obras_apelidos(): array {
    return [
        'btec-congonhas' => ['BTEC', 'TERMINAL AVANTE', 'CONGONHAS'], 'aura-belvedere' => ['AURA'], 'grande-sertao' => ['GRANDE SERTAO'],
        'capibaribe' => ['CAPIBARIBE'], 'aro-jardins' => ['ARO GSA', 'JARDINS BELVEDERE'], 'itaparica' => ['ITAPARICA'], 'sinop' => ['SINOP'],
        'phv-sinval' => ['PHV', 'SINVAL'], 'witplan-panamera' => ['WITPLAN', 'PANAMERA'], 'agaspar-grauna' => ['AGASPAR', 'GRAUNA'],
        'telar-blumenau' => ['TELAR', 'BLUMENAU'], 'penedo' => ['PENEDO', 'NEOPOLIS'], 'nexa' => ['NEXA', 'VAZANTE'], 'sogel' => ['SOGEL'],
        'aterpa-vale' => ['ATERPA'], 'rivoli' => ['RIVOLI'], 'conartes' => ['CONARTES'], 'governador-valadares' => ['VALADARES'],
    ];
}
/** Lê o destino do pedido no texto (observações, finalidade, material). Retorna ['setor'=>..,'obra_id'=>..,'obra'=>..] ou []. */
function rd_destino_detectar(string $texto): array {
    $t = rd_txt_chave($texto);
    if (trim($t) === '') return [];
    $ap = rd_obras_apelidos();
    foreach (rd_obras_lista() as $o) {
        if ($o['id'] === 'deposito') continue;
        $chaves = array_merge([$o['name']], $ap[$o['id']] ?? []);
        foreach ($chaves as $k) { $k = trim(rd_txt_chave((string)$k)); if (strlen($k) >= 3 && str_contains($t, ' ' . $k . ' ')) return ['setor' => 'OBRA', 'obra_id' => $o['id'], 'obra' => $o['name']]; }
    }
    if (preg_match('/ CALDEIRARIA /', $t)) return ['setor' => 'CALDEIRARIA'];
    if (preg_match('/ (MANUT|MANUTENCAO|PREVENTIVA|CORRETIVA) /', $t)) return ['setor' => 'MANUTENCAO'];
    if (preg_match('/ (EXPEDICAO) /', $t)) return ['setor' => 'EXPEDICAO'];
    if (preg_match('/ (LOGISTICA) /', $t)) return ['setor' => 'LOGISTICA'];
    if (preg_match('/ (DEPOSITO|ALMOXARIFADO|REPOSICAO DE ESTOQUE|ESTOQUE) /', $t)) return ['setor' => 'DEPOSITO'];
    if (preg_match('/ (ESCRITORIO|ADMINISTRATIVO|ADM) /', $t)) return ['setor' => 'ADM'];
    if (preg_match('/ OBRA /', $t)) return ['setor' => 'OBRA'];
    return [];
}
/** Rótulo curto do destino de um pedido. */
function rd_destino_rotulo(array $p): string {
    $s = (string)($p['destino_setor'] ?? '');
    if ($s === 'OBRA') return (string)($p['obra'] ?? '') !== '' ? (string)$p['obra'] : 'Obra (não identificada)';
    return RD_SETORES[$s] ?? '';
}
