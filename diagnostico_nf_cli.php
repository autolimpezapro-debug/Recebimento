<?php
/** Diagnóstico somente CLI. Não transmite nem mostra dados pessoais ou pedidos. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Acesso negado'); }
$dir=__DIR__.'/data';$path=$dir.'/items.json';
echo "Recebimento Drilling — diagnóstico local (sem alterações)\n";
echo "PHP: ".PHP_VERSION."\n";
echo "Extensões JSON: ".(extension_loaded('json')?'OK':'FALTA')."\n";
echo "Extensões fileinfo: ".(extension_loaded('fileinfo')?'OK':'FALTA')."\n";
echo "Diretório data existe: ".(is_dir($dir)?'SIM':'NÃO')."\n";
echo "Diretório data gravável: ".(is_writable($dir)?'SIM':'NÃO')."\n";
echo "Arquivo items.json existe: ".(is_file($path)?'SIM':'NÃO')."\n";
if (is_file($path)) {
  echo "Arquivo items.json legível: ".(is_readable($path)?'SIM':'NÃO')."\n";
  if (is_readable($path)) {
    $raw=file_get_contents($path);$obj=json_decode($raw,true);
    echo "JSON válido: ".(json_last_error()===JSON_ERROR_NONE?'SIM':'NÃO')."\n";
    echo "Estrutura de registros: ".(is_array($obj)?'ARRAY':'INVÁLIDA')."\n";
  }
}
echo "Se a página falhar, confira também o log de erros PHP do cPanel.\n";
