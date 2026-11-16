<?php
/**
 * Recebimento Drilling · Gemini em segundo plano (invisível).
 * Não há chat nem botão: o Gemini lê um RESUMO numérico do estoque (sem nomes de funcionários,
 * sem chaves, sem dados pessoais) e devolve observações curtas que entram nos alertas do painel.
 * Resultado em cache (data/gemini_insights.json) por 6 h; qualquer falha é silenciosa.
 */
declare(strict_types=1);
require_once __DIR__ . '/gemini_import.php';

const RD_IA_CACHE = __DIR__ . '/data/gemini_insights.json';
const RD_IA_TTL = 21600;

function rd_ia_cache_read(): array {
    $d = is_file(RD_IA_CACHE) ? json_decode((string)@file_get_contents(RD_IA_CACHE), true) : null;
    return is_array($d) ? $d : [];
}

function rd_ia_resumo_estoque(array $a): array {
    $k = $a['kpis'] ?? [];
    $lim = fn($rows, $campos, $n = 8) => array_map(fn($r) => array_intersect_key($r, array_flip($campos)), array_slice((array)$rows, 0, $n));
    return [
        'periodo_dias' => $a['periodo_dias'] ?? 90,
        'kpis' => $k,
        'mensal' => $a['mensal'] ?? [],
        'familias' => $lim($a['familias'] ?? [], ['familia', 'itens', 'valor'], 12),
        'origem' => $a['origem'] ?? [],
        'abc' => $a['abc'] ?? [],
        'obras' => $lim($a['obras'] ?? [], ['obra', 'valor', 'saidas']),
        'risco_ruptura' => $lim($a['cobertura'] ?? [], ['codigo', 'descricao', 'saldo', 'consumo_dia', 'dias']),
        'mais_consumidos' => $lim($a['top_consumo'] ?? [], ['codigo', 'descricao', 'quantidade', 'valor']),
        'parados' => $lim($a['parados'] ?? [], ['codigo', 'descricao', 'valor', 'ultimo']),
    ];
}

/** Devolve observações em cache; se vencidas e $gerar, chama o Gemini (até ~35 s). Nunca lança exceção. */
function rd_ia_insights_estoque(array $analise, bool $gerar): array {
    $cache = rd_ia_cache_read();
    $chave = 'estoque_' . (int)($analise['periodo_dias'] ?? 90);
    $c = $cache[$chave] ?? null;
    $fresco = $c && (time() - (int)($c['ts'] ?? 0)) < RD_IA_TTL;
    if ($fresco || !$gerar) return ['itens' => $c['itens'] ?? [], 'em' => $c['em'] ?? null];
    try {
        if (!rd_gemini_ready()) return ['itens' => $c['itens'] ?? [], 'em' => $c['em'] ?? null];
        // trava simples para não disparar várias chamadas simultâneas
        $lock = @fopen(RD_IA_CACHE . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['itens' => $c['itens'] ?? [], 'em' => $c['em'] ?? null];
        $prompt = "Você é analista de almoxarifado de uma empresa de perfuração/fundações (obras). "
            . "Analise o resumo JSON do estoque abaixo (valores em R$, valorizados pelo custo médio) e devolva até 5 observações "
            . "objetivas e acionáveis em português do Brasil, priorizando riscos de falta de material para as obras, capital parado, "
            . "inconsistências de cadastro e tendências de consumo. Não invente números que não estejam no resumo. "
            . "Responda SOMENTE JSON no formato {\"itens\":[{\"nivel\":\"danger|warn|info\",\"texto\":\"até 160 caracteres\",\"aba\":\"reposicao|itens|movimentos|abc|integridade|inventario|saidas|entradas\"}]}.\n\n"
            . json_encode(rd_ia_resumo_estoque($analise), JSON_UNESCAPED_UNICODE);
        $raw = rd_gemini_call([['text' => $prompt]], 1200);
        $j = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $raw)), true);
        $itens = [];
        foreach ((array)($j['itens'] ?? []) as $x) {
            $t = trim(strip_tags((string)($x['texto'] ?? '')));
            if ($t === '') continue;
            $itens[] = ['nivel' => in_array($x['nivel'] ?? '', ['danger', 'warn', 'info'], true) ? $x['nivel'] : 'info',
                        'texto' => mb_substr($t, 0, 200),
                        'aba' => preg_match('/^[a-z]{3,20}$/', (string)($x['aba'] ?? '')) ? $x['aba'] : 'painel'];
            if (count($itens) >= 5) break;
        }
        $cache[$chave] = ['ts' => time(), 'em' => date('c'), 'itens' => $itens];
        $tmp = RD_IA_CACHE . '.tmp'; file_put_contents($tmp, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); rename($tmp, RD_IA_CACHE);
        flock($lock, LOCK_UN);
        return ['itens' => $itens, 'em' => $cache[$chave]['em']];
    } catch (Throwable $e) {
        // silencioso: registra o erro e reaproveita o último resultado
        $cache[$chave] = ['ts' => time() - RD_IA_TTL + 1800, 'em' => $c['em'] ?? null, 'itens' => $c['itens'] ?? [], 'erro' => mb_substr($e->getMessage(), 0, 200)];
        @file_put_contents(RD_IA_CACHE, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return ['itens' => $c['itens'] ?? [], 'em' => $c['em'] ?? null];
    }
}
