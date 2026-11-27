<?php
/** Recebimento Drilling — rotas nfmail_* (Caixa de NFs por e-mail, v3.59.0). Incluído por api.php depois do portão de permissões. */
if (!defined('DATA_DIR')) { http_response_code(403); exit; }
require_once __DIR__ . '/nfmail_lib.php';

$nfmUser = (function_exists('rd_login_info') && is_array(rd_login_info())) ? rd_login_info()['username'] : 'operador-local';
$nfmMet = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Aplica vínculos automáticos pendentes (feitos pelo cron) antes de mostrar a tela. */
function nfm_api_aplicar(): void {
    nfm_with_lock(function () { $d = nfm_read(); if (nfm_aplicar_pendentes($d)) nfm_write($d); }, false);
}

if ($nfmMet === 'GET') {
    switch ($action) {
        case 'nfmail_painel':
            nfm_api_aplicar();
            respond(['ok' => true, 'caminho' => (function_exists('rd_identity') && rd_identity() !== null && rd_perm('pedidos', 'admin')) ? __DIR__ : ''] + nfm_painel(nfm_read(), nfm_pcs_indice(read_items())));
        case 'nfmail_nota':
            $n = nfm_read()['notas'][(string)($_GET['id'] ?? '')] ?? null;
            if (!$n) nfm_fail('Nota não encontrada.', 404);
            $v = nfm_nota_view($n);
            if ($n['tipo'] === 'NFE') $v['abertos_fornecedor'] = nfm_abertos_do_fornecedor($n, nfm_pcs_indice(read_items()));
            if ($n['status'] !== 'VINCULADA' && $n['tipo'] !== 'CTE') { $dec = nfm_decidir($n, nfm_pcs_indice(read_items())); $v['sugestoes'] = $dec['sugestoes']; if ($n['status'] === 'PENDENTE' && !in_array($n['motivo'], ['desvinculada'], true) && strpos($n['motivo'], 'PC ') !== 0) $v['motivo'] = $dec['motivo']; }
            respond(['ok' => true, 'nota' => $v]);
        case 'nfmail_arquivo': nfm_servir(nfm_read());
        case 'nfmail_csv':
            $csv = nfm_csv(nfm_read());
            header('Content-Type: text/csv; charset=utf-8'); header('Cache-Control: no-store');
            header('Content-Disposition: attachment; filename="caixa-de-nfs-' . date('Ymd-Hi') . '.csv"'); echo $csv; exit;
        case 'nfmail_aviso': respond(['ok' => true, 'aviso' => nfm_aviso_pc((int)($_GET['pc'] ?? 0))]);
        case 'nfmail_config': respond(['ok' => true, 'config' => nfm_config_publica()]);
    }
    nfm_fail('Ação da Caixa de NFs inválida', 400);
}
if ($nfmMet !== 'POST') nfm_fail('Método não permitido', 405);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 256 * 1024) nfm_fail('Corpo muito grande', 413);
$body = json_decode($raw ?: '{}', true);
if (!is_array($body)) nfm_fail('Dados inválidos', 400);

switch ($action) {
    case 'nfmail_config_salvar':
        respond(['ok' => true, 'config' => nfm_config_salvar($body)]);
    case 'nfmail_testar':
        $c = nfm_config();
        // A senha salva só é usada contra o MESMO servidor/usuário salvos: testar outro host com ela a entregaria a terceiros.
        if (empty($body['senha']) && ((!empty($body['host']) && strcasecmp(trim((string)$body['host']), (string)$c['host']) !== 0)
            || (!empty($body['usuario']) && strcasecmp(trim((string)$body['usuario']), (string)$c['usuario']) !== 0)
            || (isset($body['porta']) && (int)$body['porta'] !== (int)$c['porta'])
            || (!empty($body['seguranca']) && (string)$body['seguranca'] !== (string)$c['seguranca'])))
            nfm_fail('Servidor ou usuário diferente do salvo: digite a senha da caixa para testar.');
        if (!empty($body['senha'])) $c['senha'] = (string)$body['senha'];
        foreach (['host', 'usuario', 'pasta', 'seguranca'] as $k) if (!empty($body[$k])) $c[$k] = (string)$body[$k];
        if (isset($body['porta'])) $c['porta'] = (int)$body['porta'];
        if (array_key_exists('verificar_certificado', $body)) $c['verificar_certificado'] = !empty($body['verificar_certificado']);
        try {
            $im = new NfmImap($c); $im->login($c['usuario'], $c['senha']); $im->selecionar($c['pasta'] ?: 'INBOX');
            $n = count($im->buscar('ALL')); $im->sair();
            respond(['ok' => true, 'mensagem' => 'Conectado! A pasta ' . ($c['pasta'] ?: 'INBOX') . ' tem ' . $n . ' e-mail(s).']);
        } catch (Throwable $e) { respond(['ok' => false, 'error' => $e->getMessage()], 422); }
    case 'nfmail_ia_ler':   // lê o PDF (DANFE) com o Gemini; a chave de 44 dígitos valida
        @set_time_limit(120);
        $iid = (string)($body['id'] ?? ''); $nt = nfm_read()['notas'][$iid] ?? null;
        if (!$nt) nfm_fail('Nota não encontrada.', 404);
        if (empty($nt['pdfs'])) nfm_fail('Esta nota não tem PDF para ler.');
        try { $lido = nfm_ia_extrair($nt); } catch (Throwable $e) { nfm_fail($e->getMessage()); }
        $res = nfm_with_lock(function () use ($iid, $lido) {
            $d = nfm_read(); $n = &$d['notas'][$iid];
            nfm_ia_aplicar($n, $lido); nfm_ia_unir_duplicada($d, $iid);
            if ($n['status'] === 'PENDENTE' && (nfm_config()['vincular_auto'] ?? true)) nfm_reavaliar_pendentes($d);
            unset($n); nfm_write($d);
            return ['mensagem' => 'PDF lido pela IA.', 'nota' => nfm_nota_view($d['notas'][$iid])];
        });
        respond(['ok' => true] + $res);
    case 'nfmail_sincronizar':   // botão "Verificar agora" ou tela aberta (auto = respeita o intervalo)
        @set_time_limit(120);
        $r = nfm_sincronizar(!empty($body['auto']) ? 'tela' : 'manual', empty($body['auto']));
        $aplicadas = 0;
        nfm_with_lock(function () use (&$aplicadas) { $d = nfm_read(); $aplicadas = nfm_aplicar_pendentes($d); if ($aplicadas) nfm_write($d); });
        if (empty($r['ok']) && empty($r['pulado'])) respond(['ok' => false, 'error' => implode(' · ', $r['erros'] ?? [$r['erro'] ?? 'Falha ao ler a caixa.'])], 422);
        respond(['ok' => true, 'resultado' => $r, 'vinculadas_agora' => $aplicadas]);
}

$res = nfm_with_lock(function () use ($action, $body, $nfmUser) {
    $d = nfm_read(); $id = (string)($body['id'] ?? '');
    switch ($action) {
        case 'nfmail_vincular':
            $pc = (int)($body['pc'] ?? 0); if ($pc <= 0) nfm_fail('Informe o PC.');
            $r = nfm_vincular($d, $id, $pc, $nfmUser); break;
        case 'nfmail_vincular_varios':   // uma NF para vários PCs (fornecedor que fatura depois / faturamento mínimo)
            $lista = array_values(array_unique(array_filter(array_map('intval', (array)($body['pcs'] ?? [])), fn($x) => $x > 0)));
            if (!$lista || count($lista) > 60) nfm_fail('Informe de 1 a 60 PCs.');
            if (($d['notas'][$id]['status'] ?? '') !== 'VINCULADA') { $r0 = nfm_vincular($d, $id, array_shift($lista), $nfmUser); }
            foreach ($lista as $x) nfm_vincular_extra($d, $id, $x, $nfmUser, !empty($body['forcar']));
            $r = ['msg' => 'NF ligada a ' . (1 + count($d['notas'][$id]['pcs_extra'] ?? [])) . ' pedido(s).']; break;
        case 'nfmail_desvincular_extra': nfm_desvincular_extra($d, $id, (int)($body['pc'] ?? 0), $nfmUser); $r = ['msg' => 'PC retirado da NF.']; break;
        case 'nfmail_frete_pc':   // liga/desliga manualmente um CT-e a um PC (quando o CT-e não cita a chave das NFs)
            $nt = &$d['notas'][$id]; if (!$nt || $nt['tipo'] !== 'CTE') nfm_fail('CT-e não encontrado.', 404);
            $pcf = (int)($body['pc'] ?? 0); $ix = nfm_pcs_indice();
            if ($pcf > 0 && !isset($ix[$pcf])) nfm_fail('PC ' . $pcf . ' não existe.');
            $nt['frete_pcs'] = $pcf > 0 ? [$pcf] : [];
            $nt['historico'][] = ['em' => nfm_now(), 'por' => $nfmUser, 'acao' => $pcf > 0 ? 'Frete ligado ao PC ' . $pcf : 'Ligação manual do frete removida']; unset($nt);
            $r = ['msg' => $pcf > 0 ? 'Frete ligado ao PC ' . $pcf . '.' : 'Ligação removida.']; break;
        case 'nfmail_desvincular': nfm_desvincular($d, $id, $nfmUser, nfm_str($body['motivo'] ?? '', 200)); $r = ['msg' => 'Desvinculada.']; break;
        case 'nfmail_ignorar': nfm_ignorar($d, $id, $nfmUser, (string)($body['motivo'] ?? ''), !empty($body['desfazer'])); $r = ['msg' => !empty($body['desfazer']) ? 'Voltou para pendentes.' : 'Arquivada sem PC.']; break;
        default: nfm_fail('Ação da Caixa de NFs inválida', 400);
    }
    nfm_write($d);
    return ['mensagem' => $r['msg'] ?? '', 'nota' => nfm_nota_view($d['notas'][$id])];
});
respond(['ok' => true] + $res);

