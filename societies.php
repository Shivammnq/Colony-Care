<?php
require_once __DIR__ . '/config.php';
session_start();

try {
    $pdo = get_db_connection();
} catch(Exception $e) { die("DB Error: ".$e->getMessage()); }

// ── Filters ──────────────────────────────────────────────────
$f_q    = trim($_GET['q'] ?? '');
$f_city = trim($_GET['city'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$where  = [];
$params = [];
if ($f_q !== '')    { $where[] = 'society_name LIKE ?'; $params[] = "%$f_q%"; }
if ($f_city !== '') { $where[] = 'city = ?';            $params[] = $f_city; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM societies $whereSql");
$countStmt->execute($params);
$totalSocieties = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalSocieties / $perPage));

// Page of results
$stmt = $pdo->prepare("
    SELECT id, society_name, city, state, pincode, total_flats, established_year, description
    FROM societies
    $whereSql
    ORDER BY created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$societies = $stmt->fetchAll();

// Distinct cities for the filter dropdown
$cities = $pdo->query("SELECT DISTINCT city FROM societies WHERE city IS NOT NULL AND city != '' ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);

// ── Placeholder images (cycled if no real image) ───────────
$placeholderImages = [
    'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?w=600&q=80',
    'https://images.unsplash.com/photo-1460317442991-0ec209397118?w=600&q=80',
    'https://images.unsplash.com/photo-1486325212027-8081e485255e?w=600&q=80',
    'https://images.unsplash.com/photo-1568605114967-8130f3a36994?w=600&q=80',
    'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=600&q=80',
    'https://images.unsplash.com/photo-1580587771525-78b9dba3b914?w=600&q=80',
];

if (!function_exists('societySlug')) {
    function societySlug($name) {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    }
}

// ── SEO: dynamic title/description reflecting the active filters ──
$seoTitle = 'Browse All Societies' . ($f_city ? " in $f_city" : '') . ' | ColonyCare';
$seoDescription = 'Explore ' . $totalSocieties . ' residential societies' . ($f_city ? " in $f_city" : '')
    . ' on ColonyCare — view amenities, events, gallery, and available flats for each community.';
$seoCanonical = 'https://www.example.com/societies.php' . ($_SERVER['QUERY_STRING'] ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($seoTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($seoDescription) ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?= htmlspecialchars($seoCanonical) ?>">

<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($seoTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($seoDescription) ?>">
<meta property="og:url" content="<?= htmlspecialchars($seoCanonical) ?>">
<meta property="og:site_name" content="ColonyCare">
<meta name="twitter:card" content="summary_large_image">

<?php if (!empty($societies)): ?>
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'itemListElement' => array_values(array_map(function($s, $i) {
        return [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'url' => 'https://www.example.com/society-profile.php?slug=' . urlencode(societySlug($s['society_name'])) . '&id=' . $s['id'],
            'name' => $s['society_name'],
        ];
    }, $societies, array_keys($societies))),
], JSON_UNESCAPED_SLASHES) ?>
</script>
<?php endif; ?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:#f8fffe;color:#1a2e22;}
.container{max-width:1180px;margin:0 auto;padding:0 24px;}
a{color:inherit;}

/* Top bar */
.sp-topbar{background:#fff;border-bottom:1px solid #e5ece8;padding:16px 0;}
.sp-topbar .container{display:flex;align-items:center;justify-content:space-between;}
.sp-back{display:inline-flex;align-items:center;gap:8px;text-decoration:none;color:#1a2e22;font-weight:600;font-size:.9rem;}
.sp-back:hover{color:#1a7a5e;}

/* Header */
.soc-header{padding:48px 0 28px;}
.soc-header h1{font-size:2rem;font-weight:700;color:#1a2e22;margin-bottom:8px;}
.soc-header p{font-size:.95rem;color:#5a7060;max-width:560px;}

/* Filter bar */
.soc-filters{background:#fff;border:1px solid #e5ece8;border-radius:14px;padding:16px 20px;margin-bottom:32px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;}
.soc-filters input[type=text],.soc-filters select{padding:10px 14px;border:1.5px solid #e5ece8;border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;background:#fff;}
.soc-filters input[type=text]{flex:1;min-width:180px;}
.soc-filters button{background:#1a7a5e;color:#fff;border:none;padding:10px 20px;border-radius:9px;font-family:inherit;font-size:.87rem;font-weight:700;cursor:pointer;}
.soc-filters button:hover{background:#0f5c46;}
.soc-filters .clear-link{font-size:.83rem;color:#5a7060;text-decoration:none;}
.soc-filters .clear-link:hover{color:#1a7a5e;}
.soc-count{font-size:.85rem;color:#5a7060;margin-bottom:18px;}

/* Grid (same card language as the homepage's Featured Societies) */
.soc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:40px;}
.fs-card{background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e5ece8;text-decoration:none;color:inherit;display:flex;flex-direction:column;transition:box-shadow .2s,transform .2s;cursor:pointer;}
.fs-card:hover{box-shadow:0 8px 32px rgba(26,46,34,.12);transform:translateY(-3px);}
.fs-card-img{width:100%;height:180px;overflow:hidden;}
.fs-card-img img{width:100%;height:100%;object-fit:cover;transition:transform .3s ease;}
.fs-card:hover .fs-card-img img{transform:scale(1.04);}
.fs-card-body{padding:16px 20px 20px;flex:1;display:flex;flex-direction:column;gap:6px;}
.fs-card-name{font-size:1rem;font-weight:700;color:#1a7a5e;margin:0;line-height:1.3;}
.fs-card-loc{font-size:.8rem;color:#5a7060;display:flex;align-items:center;gap:5px;}
.fs-card-loc i{color:#1a7a5e;font-size:.75rem;}
.fs-card-desc{font-size:.82rem;color:#5a7060;line-height:1.5;margin:2px 0 0;flex:1;}
.fs-card-meta{display:flex;align-items:center;gap:6px;font-size:.78rem;color:#8fa898;margin-top:4px;}
.fs-dot{color:#c5d5cc;}

.soc-empty{background:#fff;border:1px solid #e5ece8;border-radius:14px;padding:48px 20px;text-align:center;color:#5a7060;margin-bottom:40px;}
.soc-empty i{font-size:2rem;color:#a0c4b8;margin-bottom:12px;display:block;}

/* Pagination */
.soc-pagination{display:flex;justify-content:center;align-items:center;gap:8px;padding-bottom:60px;flex-wrap:wrap;}
.soc-pagination a,.soc-pagination span{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;padding:0 10px;border-radius:9px;font-size:.85rem;font-weight:600;text-decoration:none;color:#1a2e22;border:1.5px solid #e5ece8;}
.soc-pagination a:hover{border-color:#1a7a5e;color:#1a7a5e;}
.soc-pagination .active{background:#1a7a5e;border-color:#1a7a5e;color:#fff;}
.soc-pagination .disabled{opacity:.4;pointer-events:none;}

@media(max-width:900px){.soc-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px){.soc-grid{grid-template-columns:1fr;}.soc-header h1{font-size:1.5rem;}}
</style>
</head>
<body>

<div class="sp-topbar">
    <div class="container">
        <a href="/index.php" class="sp-back"><i class="fa fa-arrow-left"></i> Back to Home</a>
    </div>
</div>

<div class="container">

    <div class="soc-header">
        <h1>Browse All Societies<?= $f_city ? ' in ' . htmlspecialchars($f_city) : '' ?></h1>
        <p>Explore residential societies powered by ColonyCare — view amenities, events, gallery, and available properties for each community.</p>
    </div>

    <form method="GET" class="soc-filters">
        <input type="text" name="q" placeholder="Search by society name..." value="<?= htmlspecialchars($f_q) ?>">
        <select name="city">
            <option value="">All cities</option>
            <?php foreach ($cities as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $f_city === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><i class="fa fa-search"></i> Search</button>
        <?php if ($f_q || $f_city): ?>
        <a href="/societies.php" class="clear-link">Clear filters</a>
        <?php endif; ?>
    </form>

    <p class="soc-count"><?= $totalSocieties ?> societ<?= $totalSocieties === 1 ? 'y' : 'ies' ?> found</p>

    <?php if (empty($societies)): ?>
    <div class="soc-empty">
        <i class="fa-solid fa-building-circle-xmark"></i>
        No societies match your search. Try a different name or city.
    </div>
    <?php else: ?>
    <div class="soc-grid">
        <?php foreach ($societies as $i => $soc):
            $slug  = societySlug($soc['society_name']);
            $img   = $placeholderImages[$soc['id'] % count($placeholderImages)];
            $flats = $soc['total_flats'] ? $soc['total_flats'].' flats' : '';
            $est   = $soc['established_year'] ? 'Est. '.$soc['established_year'] : '';
            $loc   = trim(($soc['city'] ?? '').', '.($soc['state'] ?? ''), ', ');
            $desc  = $soc['description'] ? (strlen($soc['description']) > 100 ? substr($soc['description'],0,100).'...' : $soc['description']) : '';
        ?>
        <a href="/society-profile.php?slug=<?= urlencode($slug) ?>&id=<?= $soc['id'] ?>" class="fs-card">
            <div class="fs-card-img">
                <img src="<?= $img ?>" alt="<?= htmlspecialchars($soc['society_name']) ?>" loading="lazy">
            </div>
            <div class="fs-card-body">
                <h3 class="fs-card-name"><?= htmlspecialchars($soc['society_name']) ?></h3>
                <?php if ($loc): ?>
                <div class="fs-card-loc"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($loc) ?></div>
                <?php endif; ?>
                <?php if ($desc): ?>
                <p class="fs-card-desc"><?= htmlspecialchars($desc) ?></p>
                <?php endif; ?>
                <?php if ($flats || $est): ?>
                <div class="fs-card-meta">
                    <?php if($flats): ?><span><?= $flats ?></span><?php endif; ?>
                    <?php if($flats && $est): ?><span class="fs-dot">·</span><?php endif; ?>
                    <?php if($est): ?><span><?= $est ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1):
        $qs = array_filter(['q' => $f_q, 'city' => $f_city]);
    ?>
    <div class="soc-pagination">
        <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($qs, ['page' => $page - 1])) ?>"><i class="fa fa-chevron-left"></i></a>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php if ($p === $page): ?>
            <span class="active"><?= $p ?></span>
            <?php else: ?>
            <a href="?<?= http_build_query(array_merge($qs, ['page' => $p])) ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($qs, ['page' => $page + 1])) ?>"><i class="fa fa-chevron-right"></i></a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

</body>
</html>