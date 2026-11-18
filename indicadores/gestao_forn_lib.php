<?php
/**
 * Recebimento Drilling — Indicadores de gestão, módulo de fornecedores (v3.52.0)
 *  - kg_forn_resolve(): une grafias do MESMO fornecedor (CNPJ igual, ou nome igual sem "LTDA/COMERCIO…",
 *    ou um nome que é começo do outro quando só há UM candidato). Antes cada grafia virava um fornecedor.
 *  - kg_cidade_geo(): centro da cidade (aproximado) para estimar km quando o fornecedor ainda não foi localizado no mapa.
 *  - kpi_recebimentos_fornecedor(): quantas vezes recebemos de cada fornecedor (dia/semana/mês), por origem e material.
 */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }

const KG_STOP = ['LTDA','LTDA.','ME','EPP','EIRELI','SA','S','A','CIA','COMERCIO','COM','COMERCIAL','INDUSTRIA','IND','INDUSTRIAL','DE','DO','DA','DOS','DAS','E','EM','EQUIP','EQUIPAMENTOS','DISTRIBUICAO','DIST','SERVICOS','LIMITADA','MG','BRASIL'];

function kg_forn_chave_nome(string $nome): string {
    $s = mb_strtoupper(trim($nome));
    $s = strtr($s, ['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ú'=>'U','Ü'=>'U','Ç'=>'C']);
    $t = array_values(array_filter(preg_split('/[^A-Z0-9]+/', $s) ?: [], fn($x) => $x !== '' && !in_array($x, KG_STOP, true)));
    return implode(' ', $t);
}

/** Monta (uma vez) o mapa de unificação a partir de pedidos e coletas. */
function kg_forn_mapa(): array {
    static $m = null;
    if ($m !== null) return $m;
    $pares = [];   // [nome, cnpj]
    foreach (kpi_json_file(DATA_DIR . '/items.json', []) as $p)
        $pares[] = [trim((string)($p['fornecedor'] ?? '')), preg_replace('/\D/', '', (string)($p['cnpjEmitente'] ?? ''))];
    $col = kpi_json_file(DATA_DIR . '/coletas.json', []);
    foreach ((array)($col['coletas'] ?? []) as $c) $pares[] = [trim((string)($c['fornecedor'] ?? '')), preg_replace('/\D/', '', (string)($c['cnpj'] ?? ''))];
    foreach ((array)($col['fornecedores'] ?? []) as $cn => $x) $pares[] = [trim((string)($x['nome'] ?? '')), preg_replace('/\D/', '', (string)$cn)];

    $pai = [];
    $find = function ($x) use (&$pai, &$find) { while (($pai[$x] ?? $x) !== $x) { $pai[$x] = $pai[$pai[$x]] ?? $pai[$x]; $x = $pai[$x]; } return $x; };
    $uni = function ($a, $b) use (&$pai, $find) { $pai[$a] ??= $a; $pai[$b] ??= $b; $ra = $find($a); $rb = $find($b); if ($ra !== $rb) $pai[$rb] = $ra; };
    $nomes = [];   // chave nome → [grafia => qtd]
    $cnpjsDoNome = [];
    foreach ($pares as [$nome, $cn]) {
        if ($nome === '') continue;
        $kn = kg_forn_chave_nome($nome); if ($kn === '') $kn = kg_chave($nome);
        $nomes[$kn][$nome] = ($nomes[$kn][$nome] ?? 0) + 1;
        $pai['N' . $kn] ??= 'N' . $kn;
        if (strlen($cn) === 14) { $pai['C' . $cn] ??= 'C' . $cn; $uni('N' . $kn, 'C' . $cn); $cnpjsDoNome['N' . $kn][$cn] = true; }
    }
    // nome que é o COMEÇO de outro (ex.: "MPA" → "MPA HIDRAULICA E PNEUMATICA"): só une se houver UM candidato
    $ks = array_keys($nomes);
    foreach ($ks as $a) {
        $ta = explode(' ', $a); if (mb_strlen($ta[0]) < 4) continue;
        $cands = [];
        foreach ($ks as $b) if ($b !== $a && count(explode(' ', $b)) > count($ta) && str_starts_with($b . ' ', $a . ' ')) $cands[$find('N' . $b)] = $b;
        if (count($cands) === 1) $uni('N' . $a, 'N' . reset($cands));
    }
    // nome de exibição e CNPJ de cada grupo
    $grupos = [];
    foreach ($nomes as $kn => $graf) {
        $g = $find('N' . $kn);
        foreach ($graf as $nome => $q) $grupos[$g]['nomes'][$nome] = ($grupos[$g]['nomes'][$nome] ?? 0) + $q;
        foreach (array_keys($cnpjsDoNome['N' . $kn] ?? []) as $cn) $grupos[$g]['cnpj'][$cn] = true;
    }
    $disp = []; $cnpjG = [];
    foreach ($grupos as $g => $x) {
        $n = $x['nomes']; uksort($n, fn($a, $b) => [$n[$b], mb_strlen($b)] <=> [$n[$a], mb_strlen($a)]);
        $disp[$g] = mb_strtoupper((string)array_key_first($n));
        $cnpjG[$g] = (string)(array_key_first($x['cnpj'] ?? []) ?? '');
    }
    $m = ['find' => $find, 'disp' => $disp, 'cnpj' => $cnpjG, 'pai' => &$pai];
    return $m;
}

/** [chave do grupo, nome de exibição, CNPJ]. */
function kg_forn_resolve(string $nome, string $cnpj = ''): array {
    $nome = trim($nome); $cn = preg_replace('/\D/', '', $cnpj);
    if ($nome === '' && strlen($cn) !== 14) return ['NNAOINFORMADO', 'NÃO INFORMADO', ''];
    $M = kg_forn_mapa(); $find = $M['find'];
    $kn = kg_forn_chave_nome($nome); if ($kn === '') $kn = kg_chave($nome);
    $no = isset($M['pai']['C' . $cn]) && strlen($cn) === 14 ? 'C' . $cn : 'N' . $kn;
    if (!isset($M['pai'][$no])) return ['N' . $kn, mb_strtoupper($nome), strlen($cn) === 14 ? $cn : ''];
    $g = $find($no);
    return ['G' . $g, $M['disp'][$g] ?? mb_strtoupper($nome), $M['cnpj'][$g] ?? (strlen($cn) === 14 ? $cn : '')];
}
function kg_forn_nome(string $nome, string $cnpj = ''): string { return kg_forn_resolve($nome, $cnpj)[1]; }

/* ------------------------------------------------------------------ */
/* Centro aproximado das cidades (estimativa de km quando falta o mapa)  */
/* ------------------------------------------------------------------ */
function kg_cidade_geo(string $cidade, string $uf = ''): ?array {
    static $t = null;
    if ($t === null) {
        $raw = ['BELO HORIZONTE' => [-19.9167, -43.9345], 'CONTAGEM' => [-19.9319, -44.0539], 'BETIM' => [-19.9678, -44.1987],
            'SAO JOSE DA LAPA' => [-19.7019, -43.9589], 'SETE LAGOAS' => [-19.4658, -44.2467], 'SANTA LUZIA' => [-19.7697, -43.8514],
            'RIBEIRAO DAS NEVES' => [-19.7669, -44.0867], 'VESPASIANO' => [-19.6914, -43.9231], 'LAGOA SANTA' => [-19.6314, -43.8961],
            'PEDRO LEOPOLDO' => [-19.6189, -44.0408], 'SABARA' => [-19.8836, -43.8264], 'NOVA LIMA' => [-19.9856, -43.8467],
            'IBIRITE' => [-20.0219, -44.0583], 'SARZEDO' => [-20.0369, -44.1411], 'MARIO CAMPOS' => [-20.0653, -44.1883],
            'ESMERALDAS' => [-19.7650, -44.3114], 'MATEUS LEME' => [-19.9794, -44.4264], 'JUATUBA' => [-19.9467, -44.3417],
            'IGARAPE' => [-20.0706, -44.3036], 'SAO JOAQUIM DE BICAS' => [-20.0469, -44.2775], 'BRUMADINHO' => [-20.1431, -44.1997],
            'CAETE' => [-19.9006, -43.6700], 'ITABIRITO' => [-20.2536, -43.8036], 'OURO PRETO' => [-20.3856, -43.5036], 'MARIANA' => [-20.3778, -43.4167],
            'ITAUNA' => [-20.0753, -44.5761], 'DIVINOPOLIS' => [-20.1386, -44.8839], 'PARA DE MINAS' => [-19.8603, -44.6086],
            'CONSELHEIRO LAFAIETE' => [-20.6603, -43.7864], 'CONGONHAS' => [-20.4997, -43.8586], 'IPATINGA' => [-19.4703, -42.5369],
            'JOAO MONLEVADE' => [-19.8100, -43.1736], 'BARAO DE COCAIS' => [-19.9453, -43.4767], 'SANTA BARBARA' => [-19.9606, -43.4136],
            'JABOTICATUBAS' => [-19.5153, -43.7453], 'MATOZINHOS' => [-19.5547, -44.0847], 'CONFINS' => [-19.6244, -43.9811], 'CAPIM BRANCO' => [-19.5469, -44.1264],
            'JUIZ DE FORA' => [-21.7642, -43.3503], 'UBERLANDIA' => [-18.9186, -48.2772], 'MONTES CLAROS' => [-16.7286, -43.8636],
            'GOVERNADOR VALADARES' => [-18.8511, -41.9494], 'POCOS DE CALDAS' => [-21.7878, -46.5614], 'VARGINHA' => [-21.5514, -45.4303],
            'SAO PAULO' => [-23.5505, -46.6333], 'PARAGUACU PAULISTA' => [-22.4167, -50.5786], 'CAMPINAS' => [-22.9099, -47.0626],
            'GUARULHOS' => [-23.4543, -46.5333], 'RIO DE JANEIRO' => [-22.9068, -43.1729], 'VITORIA' => [-20.3155, -40.3128],
            'CURITIBA' => [-25.4284, -49.2733], 'GOIANIA' => [-16.6869, -49.2648], 'BRASILIA' => [-15.7939, -47.8828]];
        $t = [];
        foreach ($raw as $k => $v) $t[$k] = ['lat' => $v[0], 'lng' => $v[1]];
    }
    $k = kg_chave($cidade);
    return $t[$k] ?? null;
}

/** Segunda-feira da semana (ISO) de uma data AAAA-MM-DD. */
function kg_semana(string $d): string { $t = strtotime($d . ' 12:00'); return date('Y-m-d', $t - ((int)date('N', $t) - 1) * 86400); }

/* ------------------------------------------------------------------ */
/* Recebimentos por fornecedor                                           */
/* ------------------------------------------------------------------ */
function kpi_recebimentos_fornecedor(?string $de, ?string $ate): array {
    $ate = $ate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) ? $ate : date('Y-m-d');
    $de = $de && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) ? $de : date('Y-m-d', strtotime($ate . ' -90 day'));
    $pedidos = kpi_json_file(DATA_DIR . '/items.json', []);
    $col = kpi_json_file(DATA_DIR . '/coletas.json', []);
    $coletaPc = [];
    foreach ((array)($col['coletas'] ?? []) as $c)
        if ((int)($c['pc'] ?? 0) && in_array($c['status'] ?? '', ['COLETADO', 'TRANSPORTE', 'CHEGOU'], true)) $coletaPc[(int)$c['pc']] = true;

    $F = []; $porDia = []; $totPcs = 0; $totEv = 0; $orig = ['coleta' => 0, 'entrega' => 0, 'nao_informado' => 0]; $linhas = 0; $valor = 0.0;
    foreach ($pedidos as $p) {
        $pc = (int)($p['pc'] ?? 0);
        // cada entrega confirmada é um recebimento (entrega parcial + saldo depois = 2 recebimentos)
        $ev = [];
        foreach ((array)($p['entregas'] ?? []) as $e) {
            $d = substr((string)($e['confirmado_em'] ?? ''), 0, 10);
            if ($d !== '') $ev[] = ['data' => $d, 'ins' => (array)($e['insumos'] ?? []), 'status' => (string)($e['status'] ?? '')];
        }
        if (!$ev) {
            $d = substr((string)($p['primeira_confirmacao_em'] ?? $p['recebimento_finalizado_em'] ?? ''), 0, 10);
            if ($d === '') continue;
            $ev[] = ['data' => $d, 'ins' => [], 'status' => (string)($p['chegou'] ?? '')];
        }
        $ev = array_values(array_filter($ev, fn($e) => $e['data'] >= $de && $e['data'] <= $ate));
        if (!$ev) continue;
        [$k, $nome, $cn] = kg_forn_resolve((string)($p['fornecedor'] ?? ''), (string)($p['cnpjEmitente'] ?? ''));
        $oe = (string)($p['origem_entrega'] ?? '');
        $origem = $oe === 'ENTREGA_FORNECEDOR' ? 'entrega' : ($oe === 'COLETA_DRILLING' || isset($coletaPc[$pc]) ? 'coleta' : 'nao_informado');   // a marcação do conferente vale mais que um registro de coleta solto
        $F[$k] = $F[$k] ?? ['fornecedor' => $nome, 'cnpj' => $cn, 'pcs' => [], 'recebimentos' => 0, 'dias' => [], 'origem' => ['coleta' => 0, 'entrega' => 0, 'nao_informado' => 0],
            'valor' => 0.0, 'mat' => [], 'ev' => []];
        $F[$k]['pcs'][$pc] = true;
        $F[$k]['origem'][$origem]++; $orig[$origem]++; $totPcs++;
        $v = function_exists('kg_valor') ? kg_valor($p['valorNF'] ?? 0) : 0.0; $F[$k]['valor'] += $v; $valor += $v;
        foreach ($ev as $i => $e) {
            $F[$k]['recebimentos']++; $totEv++;
            $F[$k]['dias'][$e['data']]['pcs'][$pc] = true;
            $F[$k]['dias'][$e['data']]['origem'] = $origem;
            $porDia[$e['data']]['pcs'][$pc] = true; $porDia[$e['data']]['forn'][$k] = true;
            // material desta entrega (ou o recebido, quando a entrega não detalha)
            $ins = $e['ins'] ?: (array)($p['insumos'] ?? []);
            foreach ($ins as $l) {
                $q = $e['ins'] ? (float)($l['quantidade_nesta_entrega'] ?? $l['quantidade_recebida'] ?? 0) : (float)($l['quantidade_recebida'] ?? 0);
                if ($q <= 0) continue;
                $u = mb_strtoupper(trim((string)($l['unidade'] ?? 'UN'))) ?: 'UN';
                $desc = trim(preg_replace('/\s+/', ' ', (string)($l['descricao'] ?? '')));
                $ch = kg_chave($desc) . '|' . $u;
                $F[$k]['mat'][$ch] = $F[$k]['mat'][$ch] ?? ['descricao' => $desc, 'unidade' => $u, 'quantidade' => 0.0, 'pcs' => [], 'datas' => []];
                $F[$k]['mat'][$ch]['quantidade'] += $q; $F[$k]['mat'][$ch]['pcs'][$pc] = true; $F[$k]['mat'][$ch]['datas'][$e['data']] = true;
                $linhas++;
            }
        }
    }
    $out = [];
    foreach ($F as $k => $f) {
        $dias = []; $sem = []; $mes = []; $maxDia = 0; $multi = 0;
        foreach ($f['dias'] as $d => $x) {
            $n = count($x['pcs']); $maxDia = max($maxDia, $n); if ($n > 1) $multi++;
            $sem[kg_semana($d)] = ($sem[kg_semana($d)] ?? 0) + 1; $mes[substr($d, 0, 7)] = ($mes[substr($d, 0, 7)] ?? 0) + 1;
            $dias[] = ['data' => $d, 'pcs' => array_keys($x['pcs']), 'origem' => $x['origem']];
        }
        usort($dias, fn($a, $b) => strcmp($b['data'], $a['data']));
        $mat = array_values($f['mat']);
        foreach ($mat as &$m) { $m['vezes'] = count($m['pcs']); $m['datas'] = array_keys($m['datas']); sort($m['datas']); unset($m['pcs']); $m['quantidade'] = round($m['quantidade'], 3); }
        unset($m);
        usort($mat, fn($a, $b) => [$b['vezes'], $b['quantidade']] <=> [$a['vezes'], $a['quantidade']]);
        $out[] = ['fornecedor' => $f['fornecedor'], 'cnpj' => $f['cnpj'], 'pcs' => count($f['pcs']), 'recebimentos' => $f['recebimentos'], 'dias' => count($f['dias']),
            'semanas' => count($sem), 'max_no_dia' => $maxDia, 'dias_com_varios_pcs' => $multi, 'origem' => $f['origem'], 'valor' => round($f['valor'], 2),
            'materiais_total' => count($mat), 'materiais' => array_slice($mat, 0, 60), 'dias_lista' => $dias,
            'ultimo' => $dias ? $dias[0]['data'] : null];
    }
    usort($out, fn($a, $b) => [$b['dias'], $b['pcs']] <=> [$a['dias'], $a['pcs']]);
    // séries por dia / semana / mês (todos os fornecedores)
    ksort($porDia); $dias = []; $sem = []; $mes = [];
    foreach ($porDia as $d => $x) {
        $dias[] = ['data' => $d, 'pcs' => count($x['pcs']), 'fornecedores' => count($x['forn'])];
        $w = kg_semana($d); $sem[$w] = $sem[$w] ?? ['semana' => $w, 'dias' => 0, 'pcs' => 0, 'fornecedores' => []];
        $sem[$w]['dias']++; $sem[$w]['pcs'] += count($x['pcs']); foreach ($x['forn'] as $kk => $_) $sem[$w]['fornecedores'][$kk] = true;
        $m = substr($d, 0, 7); $mes[$m] = $mes[$m] ?? ['mes' => $m, 'dias' => 0, 'pcs' => 0, 'fornecedores' => []];
        $mes[$m]['dias']++; $mes[$m]['pcs'] += count($x['pcs']); foreach ($x['forn'] as $kk => $_) $mes[$m]['fornecedores'][$kk] = true;
    }
    $fixa = fn($a) => array_values(array_map(function ($r) { $r['fornecedores'] = count($r['fornecedores']); return $r; }, $a));
    $repetidos = count(array_filter($out, fn($f) => $f['dias'] > 1));
    return ['de' => $de, 'ate' => $ate,
        'totais' => ['fornecedores' => count($out), 'pcs' => $totPcs, 'recebimentos' => $totEv, 'por_origem' => $orig, 'linhas_material' => $linhas, 'valor' => round($valor, 2),
            'fornecedores_repetidos' => $repetidos, 'dias_com_recebimento' => count($dias)],
        'fornecedores' => $out, 'dias' => array_reverse($dias), 'semanas' => array_reverse($fixa($sem)), 'meses' => array_reverse($fixa($mes))];
}
