<?php
declare(strict_types=1);

$host = $_SERVER['HTTP_HOST'] ?? 'benchbuddy.ca';
$baseUrl = 'https://' . $host;

$pages = [
    [
        'loc' => '/',
        'changefreq' => 'weekly',
        'priority' => '1.0',
    ],
    [
        'loc' => '/signup.php',
        'changefreq' => 'monthly',
        'priority' => '0.9',
    ],
    [
        'loc' => '/login.php',
        'changefreq' => 'monthly',
        'priority' => '0.6',
    ],
    [
        'loc' => '/terms.php',
        'changefreq' => 'monthly',
        'priority' => '0.6',
    ],
    [
        'loc' => '/privacy_policy.php',
        'changefreq' => 'monthly',
        'priority' => '0.6',
    ],
    [
        'loc' => '/features.php',
        'changefreq' => 'weekly',
        'priority' => '0.8',
    ],
    [
        'loc' => '/cookie_policy.php',
        'changefreq' => 'yearly',
        'priority' => '0.3',
    ],
    [
        'loc' => '/sitemap.php',
        'changefreq' => 'monthly',
        'priority' => '0.4',
    ],
];

header('Content-Type: application/xml; charset=UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as $page): ?>
  <url>
    <loc><?= htmlspecialchars($baseUrl . $page['loc'], ENT_XML1, 'UTF-8') ?></loc>
    <lastmod><?= date('Y-m-d') ?></lastmod>
    <changefreq><?= htmlspecialchars($page['changefreq'], ENT_XML1, 'UTF-8') ?></changefreq>
    <priority><?= htmlspecialchars($page['priority'], ENT_XML1, 'UTF-8') ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
