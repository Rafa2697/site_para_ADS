<?php
// sitemap.php - gera um sitemap XML simples baseado em páginas conhecidas
header('Content-Type: application/xml; charset=utf-8');
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'example.com';
$base = $protocol . '://' . $host;

$pages = [
    '/',
    '/index.html',
    '/tabela_fipe.html',
    '/previsao_oceanica.html',
    '/financiamento_comentado.php'
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n';
foreach ($pages as $p) {
    $file = __DIR__ . ($p === '/' ? '/index.html' : $p);
    $lastmod = file_exists($file) ? gmdate('Y-m-d\TH:i:s\Z', filemtime($file)) : gmdate('Y-m-d\TH:i:s\Z');
    $loc = rtrim($base, '/') . $p;
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($loc, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . "</loc>\n";
    echo "    <lastmod>$lastmod</lastmod>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.6</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>';

// Nota: verifique os caminhos e substitua/adicione URLs conforme necessário.
?>
