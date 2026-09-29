<?php
// Dynamic XML sitemap — generated fresh on each request so job listings
// (which are added/expire regularly) are always current, unlike a static
// sitemap.xml file that would immediately go stale.
require_once __DIR__ . '/db.php';
header('Content-Type: application/xml; charset=utf-8');

$base = 'https://thekeria.com';

function url_entry($loc, $lastmod = null, $changefreq = 'weekly', $priority = '0.5') {
    $xml = "  <url>\n";
    $xml .= "    <loc>" . htmlspecialchars($loc) . "</loc>\n";
    if ($lastmod) {
        $xml .= "    <lastmod>" . htmlspecialchars($lastmod) . "</lastmod>\n";
    }
    $xml .= "    <changefreq>$changefreq</changefreq>\n";
    $xml .= "    <priority>$priority</priority>\n";
    $xml .= "  </url>\n";
    return $xml;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Static, evergreen public pages
echo url_entry("$base/index.php", null, 'weekly', '1.0');
echo url_entry("$base/jobs.php", null, 'daily', '0.9');
echo url_entry("$base/resume_builder.php", null, 'monthly', '0.8');
echo url_entry("$base/resume_check.php", null, 'monthly', '0.8');
echo url_entry("$base/terms.php", null, 'yearly', '0.3');

// One entry per active job listing, each with its own crawlable URL
try {
    $stmt = $pdo->query("SELECT id, created_at FROM jobs WHERE status = 'Active' OR status IS NULL ORDER BY id DESC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
        $lastmod = $job['created_at'] ?? null;
        $lastmod_fmt = $lastmod ? date('Y-m-d', strtotime($lastmod)) : null;
        echo url_entry("$base/jobs.php?id=" . (int)$job['id'], $lastmod_fmt, 'daily', '0.7');
    }
} catch (\Throwable $e) {
    // If the jobs table/query ever fails, still serve a valid sitemap
    // for the static pages above rather than a broken response.
}

echo '</urlset>' . "\n";
