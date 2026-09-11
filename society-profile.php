<?php
session_start();

$society_id = intval($_GET['id'] ?? 0);
if (!$society_id) { header('Location: /shivam/index.php'); exit; }

try {
    $pdo = new PDO("mysql:host=localhost;dbname=cc;charset=utf8mb4","root","",[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
} catch(Exception $e) { die("DB Error: ".$e->getMessage()); }

$stmt = $pdo->prepare("SELECT * FROM societies WHERE id = ? LIMIT 1");
$stmt->execute([$society_id]);
$society = $stmt->fetch();
if (!$society) { header('Location: /shivam/index.php'); exit; }



// ── Fetch counts ────────────────────────────────────────────
$total_flats = $pdo->prepare("SELECT COUNT(*) FROM flats WHERE society_id=?");
$total_flats->execute([$society_id]); $total_flats = $total_flats->fetchColumn();

$occupied = $pdo->prepare("SELECT COUNT(*) FROM flats WHERE society_id=? AND status='occupied'");
$occupied->execute([$society_id]); $occupied = $occupied->fetchColumn();

$for_sale = $pdo->prepare("SELECT COUNT(*) FROM flats WHERE society_id=? AND status='for_sale'");
$for_sale->execute([$society_id]); $for_sale = $for_sale->fetchColumn();

$for_rent = $pdo->prepare("SELECT COUNT(*) FROM flats WHERE society_id=? AND status='for_rent'");
$for_rent->execute([$society_id]); $for_rent = $for_rent->fetchColumn();

$gallery_count = $pdo->prepare("SELECT COUNT(*) FROM society_gallery WHERE society_id=?");
$gallery_count->execute([$society_id]); $gallery_count = $gallery_count->fetchColumn();

$events_count = $pdo->prepare("SELECT COUNT(*) FROM society_events WHERE society_id=?");
$events_count->execute([$society_id]); $events_count = $events_count->fetchColumn();

$vendors_count = $pdo->prepare("SELECT COUNT(*) FROM vendors WHERE society_id=?");
$vendors_count->execute([$society_id]); $vendors_count = $vendors_count->fetchColumn();

$committee_count = $pdo->prepare("SELECT COUNT(*) FROM society_committee WHERE society_id=?");
$committee_count->execute([$society_id]); $committee_count = $committee_count->fetchColumn();

$avg_rating = $pdo->prepare("SELECT COALESCE(AVG(rating),4.4) FROM society_reviews WHERE society_id=?");
$avg_rating->execute([$society_id]); $avg_rating = round($avg_rating->fetchColumn(),1);

$nearby = $pdo->prepare("SELECT * FROM society_nearby WHERE society_id=? ORDER BY distance_km LIMIT 4");
$nearby->execute([$society_id]); $nearby = $nearby->fetchAll();

$upcoming_events = $pdo->prepare("SELECT * FROM society_events WHERE society_id=? AND event_date >= NOW() ORDER BY event_date LIMIT 3");
$upcoming_events->execute([$society_id]); $upcoming_events = $upcoming_events->fetchAll();
// fallback: show any events if none upcoming
if (empty($upcoming_events)) {
    $upcoming_events = $pdo->prepare("SELECT * FROM society_events WHERE society_id=? ORDER BY event_date DESC LIMIT 3");
    $upcoming_events->execute([$society_id]); $upcoming_events = $upcoming_events->fetchAll();
}

$flats = $pdo->prepare("SELECT * FROM flats WHERE society_id=? ORDER BY tower,flat_no");
$flats->execute([$society_id]); $flats = $flats->fetchAll();

$vendors = $pdo->prepare("SELECT * FROM vendors WHERE society_id=? ORDER BY name");
$vendors->execute([$society_id]); $vendors = $vendors->fetchAll();

$committee = $pdo->prepare("SELECT * FROM society_committee WHERE society_id=? ORDER BY id");
$committee->execute([$society_id]); $committee = $committee->fetchAll();

$reviews = $pdo->prepare("SELECT * FROM society_reviews WHERE society_id=? ORDER BY created_at DESC LIMIT 10");
$reviews->execute([$society_id]); $reviews = $reviews->fetchAll();

$gallery = $pdo->prepare("SELECT * FROM society_gallery WHERE society_id=? ORDER BY id");
$gallery->execute([$society_id]); $gallery = $gallery->fetchAll();

$amenities_stmt = $pdo->prepare("SELECT name FROM society_amenities WHERE society_id=? ORDER BY id");
$amenities_stmt->execute([$society_id]);
$amenities = $amenities_stmt->fetchAll(PDO::FETCH_COLUMN);

// Cover image
$images = [
    'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?w=1600&q=80',
    'https://images.unsplash.com/photo-1460317442991-0ec209397118?w=1600&q=80',
    'https://images.unsplash.com/photo-1486325212027-8081e485255e?w=1600&q=80',
    'https://images.unsplash.com/photo-1568605114967-8130f3a36994?w=1600&q=80',
    'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=1600&q=80',
    'https://images.unsplash.com/photo-1580587771525-78b9dba3b914?w=1600&q=80',
];
$coverImg = $images[$society_id % count($images)];

$contact_phone = $society['contact_phone'] ?: null;
$contact_email = $society['contact_email'] ?: null;

// ── SEO: title, description, and structured data for this society ──
$seoCityState = trim(($society['city'] ?? '') . (!empty($society['city']) && !empty($society['state']) ? ', ' : '') . ($society['state'] ?? ''), ', ');
$seoTitle = $society['society_name'] . ($seoCityState ? ' - ' . $seoCityState : '') . ' | ColonyCare';
$seoDescription = !empty($society['description'])
    ? mb_substr(strip_tags($society['description']), 0, 155)
    : trim($society['society_name'] . ($seoCityState ? " in $seoCityState" : '') . ". $total_flats flats, rated $avg_rating/5 on ColonyCare.");
$seoCanonical = 'https://www.example.com/shivam/society-profile.php?id=' . (int)$society_id;
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
<meta property="og:image" content="<?= htmlspecialchars($coverImg) ?>">
<meta property="og:site_name" content="ColonyCare">
<meta name="twitter:card" content="summary_large_image">

<script type="application/ld+json">
<?= json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'ApartmentComplex',
    'name' => $society['society_name'],
    'description' => $seoDescription,
    'image' => $coverImg,
    'numberOfAccommodationUnits' => (int)$total_flats ?: null,
    'amenityFeature' => !empty($amenities) ? array_map(fn($a) => ['@type' => 'LocationFeatureSpecification', 'name' => $a], $amenities) : null,
    'address' => array_filter([
        '@type' => 'PostalAddress',
        'streetAddress' => $society['address'] ?? null,
        'addressLocality' => $society['city'] ?? null,
        'addressRegion' => $society['state'] ?? null,
        'postalCode' => $society['pincode'] ?? null,
        'addressCountry' => 'IN',
    ]),
    'aggregateRating' => $avg_rating ? [
        '@type' => 'AggregateRating',
        'ratingValue' => (string)$avg_rating,
        'bestRating' => '5',
    ] : null,
], fn($v) => $v !== null && $v !== []), JSON_UNESCAPED_SLASHES) ?>
</script>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
    --green:#1a7a5e;--green-dark:#0f5c46;--green-light:#e8f5ee;--green-soft:#f0f9f4;
    --text:#1a2e22;--sub:#5a7060;--muted:#8fa898;--border:#e5ece8;--bg:#f5f7f6;--white:#fff;
    --radius:14px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);}

/* ── COVER ── */
.cover-wrap{position:relative;height:420px;overflow:hidden;}
.cover-wrap img{width:100%;height:100%;object-fit:cover;}
.cover-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(10,30,22,.85) 0%,rgba(10,30,22,.3) 50%,rgba(10,30,22,.1) 100%);}
.back-btn{position:absolute;top:24px;left:24px;display:inline-flex;align-items:center;gap:8px;background:rgba(0,0,0,.4);backdrop-filter:blur(6px);color:#fff;padding:9px 18px;border-radius:99px;text-decoration:none;font-size:.85rem;font-weight:600;transition:background .2s;z-index:2;}
.back-btn:hover{background:rgba(0,0,0,.6);}
.cover-content{position:absolute;bottom:90px;left:0;right:0;padding:0 max(24px,calc((100% - 1200px)/2 + 24px));z-index:1;}
.cover-loc{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.85);font-size:.85rem;margin-bottom:10px;}
.cover-title{font-size:2.6rem;font-weight:700;color:#fff;margin-bottom:10px;line-height:1.1;}
.cover-desc{font-size:.95rem;color:rgba(255,255,255,.85);max-width:720px;line-height:1.6;}

/* ── INFO CARDS (overlap cover) ── */
.info-cards-wrap{max-width:1200px;margin:0 auto;padding:0 24px;position:relative;margin-top:-72px;z-index:3;}
.info-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;}
.info-card{background:#fff;border-radius:var(--radius);padding:16px 20px;box-shadow:0 4px 20px rgba(0,0,0,.08);}
.info-card .ic-label{display:flex;align-items:center;gap:6px;font-size:.72rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;}
.info-card .ic-val{font-size:.92rem;font-weight:600;color:var(--text);line-height:1.4;}

/* ── MAIN CONTENT ── */
.main-wrap{max-width:1200px;margin:0 auto;padding:24px;}

/* ── TABS ── */
.profile-tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:28px;overflow-x:auto;padding-bottom:0;}
.ptab-btn{display:flex;align-items:center;gap:7px;padding:11px 18px;border:none;background:none;font-family:inherit;font-size:.86rem;font-weight:500;color:var(--muted);cursor:pointer;border-radius:10px 10px 0 0;transition:all .2s;white-space:nowrap;border-bottom:2px solid transparent;margin-bottom:-1px;}
.ptab-btn:hover{color:var(--text);}
.ptab-btn.active{background:var(--green);color:#fff;}
.ptab-section{display:none;}.ptab-section.active{display:block;animation:fadeIn .25s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* ── SECTION TITLE ── */
.section-title{display:flex;align-items:center;gap:9px;font-size:1.3rem;font-weight:700;color:var(--text);margin-bottom:20px;}
.section-title i{color:var(--green);}

/* ── GLANCE STATS ── */
.glance-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:36px;}
.glance-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;}
.glance-card .gc-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:8px;}
.glance-card .gc-val{font-size:1.7rem;font-weight:700;color:var(--green);}

/* ── EXPLORE GRID ── */
.explore-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:36px;}
.explore-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:20px;display:flex;align-items:flex-start;justify-content:space-between;cursor:pointer;transition:all .2s;text-decoration:none;color:inherit;}
.explore-card:hover{border-color:var(--green);box-shadow:0 4px 16px rgba(26,122,94,.12);transform:translateY(-2px);}
.explore-icon{width:42px;height:42px;border-radius:11px;background:var(--green-light);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:1.05rem;margin-bottom:30px;}
.explore-arrow{color:var(--muted);font-size:.85rem;}
.explore-card-body{position:absolute;}
.explore-title{font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:2px;}
.explore-sub{font-size:.78rem;color:var(--muted);}
.explore-card-inner{display:flex;flex-direction:column;width:100%;}

/* ── NEARBY ── */
.nearby-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:36px;}
.nearby-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;display:flex;align-items:center;gap:14px;}
.nearby-icon{width:40px;height:40px;border-radius:10px;background:var(--green-light);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;}
.nearby-name{font-size:.9rem;font-weight:700;color:var(--text);}
.nearby-meta{font-size:.78rem;color:var(--muted);}

/* ── EVENTS ── */
.events-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.view-all-link{display:flex;align-items:center;gap:5px;color:var(--green);text-decoration:none;font-size:.85rem;font-weight:600;}
.events-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:40px;}
.event-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;}
.event-tag{display:inline-block;background:var(--green);color:#fff;font-size:.68rem;font-weight:700;padding:3px 12px;border-radius:99px;margin-bottom:12px;}
.event-title{font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:6px;}
.event-date{font-size:.78rem;color:var(--muted);}

/* ── CTA ── */
.cta-section{text-align:center;padding:40px 0 60px;}
.cta-text{font-size:.95rem;color:var(--sub);margin-bottom:16px;}
.cta-btn{display:inline-flex;align-items:center;gap:8px;background:var(--green);color:#fff;padding:13px 28px;border-radius:10px;text-decoration:none;font-weight:700;font-size:.92rem;transition:background .2s;}
.cta-btn:hover{background:var(--green-dark);}

/* ── FLAT GRID (Flats tab) ── */
.flat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.flat-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:16px;position:relative;}
.flat-badge{position:absolute;top:12px;right:12px;font-size:.65rem;font-weight:700;text-transform:uppercase;padding:3px 9px;border-radius:99px;}
.fb-occupied{background:#dcfce7;color:#166534;}
.fb-available{background:#f0f9f4;color:#166534;}
.fb-for_sale{background:#fef3c7;color:#92400e;}
.fb-for_rent{background:#eff6ff;color:#1d4ed8;}
.fb-resale{background:#ffedd5;color:#c2410c;}
.flat-no{font-size:1rem;font-weight:700;margin-bottom:4px;}
.flat-sub{font-size:.75rem;color:var(--muted);margin-bottom:10px;}
.flat-details{display:flex;justify-content:space-between;font-size:.78rem;color:var(--sub);}

/* ── VENDOR GRID ── */
.vendor-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.vendor-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;display:flex;align-items:center;gap:14px;}
.vendor-icon{width:42px;height:42px;border-radius:10px;background:var(--green-light);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
.vendor-name{font-size:.9rem;font-weight:700;}
.vendor-cat{font-size:.75rem;color:var(--muted);}

/* ── COMMITTEE ── */
.committee-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.committee-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:20px;text-align:center;}
.committee-avatar{width:56px;height:56px;border-radius:50%;background:var(--green-light);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:700;margin:0 auto 12px;}
.committee-name{font-size:.9rem;font-weight:700;margin-bottom:2px;}
.committee-pos{font-size:.78rem;color:var(--muted);}

/* ── REVIEWS ── */
.review-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:14px;}
.review-stars{color:#f59e0b;margin-bottom:8px;}
.review-comment{font-size:.88rem;color:var(--sub);line-height:1.6;margin-bottom:10px;}
.review-name{font-size:.82rem;font-weight:700;color:var(--text);}

/* ── GALLERY ── */
.gallery-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.gallery-item{border-radius:var(--radius);overflow:hidden;aspect-ratio:1;}
.gallery-item img{width:100%;height:100%;object-fit:cover;transition:transform .3s;}
.gallery-item:hover img{transform:scale(1.05);}

/* ── ABOUT ── */
.about-content{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:28px;}
.about-content p{font-size:.92rem;color:var(--sub);line-height:1.8;margin-bottom:20px;}
.amenities-list{display:flex;flex-wrap:wrap;gap:10px;}
.amenity-pill{display:inline-flex;align-items:center;gap:7px;background:var(--green-soft);border:1px solid #d4eddf;border-radius:99px;padding:7px 16px;font-size:.83rem;font-weight:600;color:var(--green-dark);}

/* ── EMPTY ── */
.empty-state{text-align:center;padding:48px 20px;color:var(--muted);background:#fff;border:1px solid var(--border);border-radius:var(--radius);}
.empty-state i{font-size:2.2rem;display:block;margin-bottom:12px;color:var(--border);}

@media(max-width:1100px){
    .info-cards{grid-template-columns:repeat(2,1fr);}
    .glance-grid{grid-template-columns:repeat(3,1fr);}
    .explore-grid{grid-template-columns:repeat(2,1fr);}
    .flat-grid{grid-template-columns:repeat(2,1fr);}
    .vendor-grid{grid-template-columns:1fr 1fr;}
    .committee-grid{grid-template-columns:1fr 1fr;}
    .events-grid{grid-template-columns:1fr 1fr;}
    .gallery-grid{grid-template-columns:repeat(3,1fr);}
}
@media(max-width:768px){
    .cover-wrap{height:300px;}
    .cover-title{font-size:1.6rem;}
    .cover-desc{font-size:.85rem;}
    .info-cards-wrap{margin-top:-50px;padding:0 14px;}
    .info-cards{grid-template-columns:1fr 1fr;}
    .glance-grid{grid-template-columns:1fr 1fr;}
    .explore-grid{grid-template-columns:1fr 1fr;}
    .nearby-grid{grid-template-columns:1fr;}
    .events-grid{grid-template-columns:1fr;}
    .flat-grid{grid-template-columns:1fr 1fr;}
    .vendor-grid{grid-template-columns:1fr;}
    .committee-grid{grid-template-columns:1fr 1fr;}
    .gallery-grid{grid-template-columns:1fr 1fr;}
    .main-wrap{padding:14px;}
    .profile-tabs{gap:2px;}
    .ptab-btn{padding:10px 12px;font-size:.8rem;}
    .section-title{font-size:1.1rem;}
}
@media(max-width:480px){
    .cover-wrap{height:260px;}
    .cover-title{font-size:1.3rem;}
    .cover-content{bottom:60px;}
    .info-cards{grid-template-columns:1fr;}
    .glance-grid{grid-template-columns:1fr 1fr;}
    .explore-grid{grid-template-columns:1fr;}
    .flat-grid{grid-template-columns:1fr;}
    .committee-grid{grid-template-columns:1fr;}
    .gallery-grid{grid-template-columns:1fr 1fr;}
    .profile-tabs{overflow-x:auto;flex-wrap:nowrap;padding-bottom:0;}
    .ptab-btn{flex-shrink:0;padding:9px 10px;font-size:.75rem;}
    .amenities-list{gap:6px;}
    .amenity-pill{font-size:.76rem;padding:5px 11px;}
}
</style>
</head>
<body>

<!-- COVER -->
<div class="cover-wrap">
    <img src="<?= $coverImg ?>" alt="<?= htmlspecialchars($society['society_name']) ?>">
    <div class="cover-overlay"></div>
    <a href="/shivam/societies.php" class="back-btn"><i class="fa fa-arrow-left"></i> All Societies</a>
    <div class="cover-content">
        <div class="cover-loc"><i class="fa fa-building"></i> <?= htmlspecialchars(trim(($society['city']??'').', '.($society['state']??''),', ')) ?></div>
        <div class="cover-title"><?= htmlspecialchars($society['society_name']) ?></div>
        <div class="cover-desc"><?= htmlspecialchars($society['description'] ?? '') ?></div>
    </div>
</div>

<!-- INFO CARDS -->
<div class="info-cards-wrap">
    <div class="info-cards">
        <div class="info-card">
            <div class="ic-label"><i class="fa fa-location-dot"></i> Address</div>
            <div class="ic-val"><?= htmlspecialchars($society['address'] ?? '—') ?> <?= htmlspecialchars($society['pincode'] ?? '') ?></div>
        </div>
        <div class="info-card">
            <div class="ic-label"><i class="fa fa-phone"></i> Contact</div>
            <div class="ic-val"><?= $contact_phone ? htmlspecialchars($contact_phone) : '<span style="color:var(--muted)">Not provided</span>' ?></div>
        </div>
        <div class="info-card">
            <div class="ic-label"><i class="fa fa-envelope"></i> Email</div>
            <div class="ic-val"><?= $contact_email ? htmlspecialchars($contact_email) : '<span style="color:var(--muted)">Not provided</span>' ?></div>
        </div>
        <div class="info-card">
            <div class="ic-label"><i class="fa fa-calendar"></i> Established</div>
            <div class="ic-val"><?= htmlspecialchars($society['established_year'] ?? '—') ?></div>
        </div>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-wrap">

    <!-- TABS -->
    <div class="profile-tabs">
        <button class="ptab-btn active" onclick="pSwitchTab('overview',this)"><i class="fa fa-layer-group"></i> Overview</button>
        <button class="ptab-btn" onclick="pSwitchTab('about',this)"><i class="fa fa-building"></i> About</button>
        <button class="ptab-btn" onclick="pSwitchTab('gallery',this)"><i class="fa fa-images"></i> Gallery</button>
        <button class="ptab-btn" onclick="pSwitchTab('events',this)"><i class="fa fa-calendar"></i> Events</button>
        <button class="ptab-btn" onclick="pSwitchTab('reviews',this)"><i class="fa fa-star"></i> Reviews</button>
        <button class="ptab-btn" onclick="pSwitchTab('vendors',this)"><i class="fa fa-store"></i> Vendors</button>
        <button class="ptab-btn" onclick="pSwitchTab('flats',this)"><i class="fa fa-th"></i> Flats</button>
        <button class="ptab-btn" onclick="pSwitchTab('committee',this)"><i class="fa fa-users"></i> Committee</button>
    </div>

    <!-- ══ OVERVIEW TAB ══ -->
    <div class="ptab-section active" id="ptab-overview">

        <div class="section-title"><i class="fa fa-layer-group"></i> Society at a Glance</div>
        <div class="glance-grid">
            <div class="glance-card"><div class="gc-label">Towers</div><div class="gc-val"><?= count(array_unique(array_filter(array_column($flats,'tower')))) ?: '—' ?></div></div>
            <div class="glance-card"><div class="gc-label">Floors</div><div class="gc-val"><?= $flats ? max(array_column($flats,'floor')) : '—' ?></div></div>
            <div class="glance-card"><div class="gc-label">Total Flats</div><div class="gc-val"><?= $society['total_flats'] ?: $total_flats ?></div></div>
            <div class="glance-card"><div class="gc-label">Occupied</div><div class="gc-val"><?= $occupied ?></div></div>
            <div class="glance-card"><div class="gc-label">For Sale</div><div class="gc-val"><?= $for_sale ?></div></div>
            <div class="glance-card"><div class="gc-label">For Rent</div><div class="gc-val"><?= $for_rent ?></div></div>
        </div>

        <div class="section-title" style="font-size:1.15rem">Explore Sections</div>
        <div class="explore-grid">
            <a href="javascript:void(0)" class="explore-card" onclick="pSwitchTab('gallery',document.querySelectorAll('.ptab-btn')[2])">
                <div class="explore-card-inner">
                    <div class="explore-icon"><i class="fa fa-images"></i></div>
                    <div class="explore-title">Photo Gallery</div>
                    <div class="explore-sub"><?= $gallery_count ?> albums</div>
                </div>
                <i class="fa fa-chevron-right explore-arrow"></i>
            </a>
            <a href="javascript:void(0)" class="explore-card" onclick="pSwitchTab('events',document.querySelectorAll('.ptab-btn')[3])">
                <div class="explore-card-inner">
                    <div class="explore-icon"><i class="fa fa-calendar"></i></div>
                    <div class="explore-title">Events</div>
                    <div class="explore-sub"><?= $events_count ?> total</div>
                </div>
                <i class="fa fa-chevron-right explore-arrow"></i>
            </a>
            <a href="javascript:void(0)" class="explore-card" onclick="pSwitchTab('reviews',document.querySelectorAll('.ptab-btn')[4])">
                <div class="explore-card-inner">
                    <div class="explore-icon"><i class="fa fa-star"></i></div>
                    <div class="explore-title">Reviews</div>
                    <div class="explore-sub"><?= $avg_rating ?> ★</div>
                </div>
                <i class="fa fa-chevron-right explore-arrow"></i>
            </a>
            <a href="javascript:void(0)" class="explore-card" onclick="pSwitchTab('vendors',document.querySelectorAll('.ptab-btn')[5])">
                <div class="explore-card-inner">
                    <div class="explore-icon"><i class="fa fa-store"></i></div>
                    <div class="explore-title">Vendors</div>
                    <div class="explore-sub"><?= $vendors_count ?> services</div>
                </div>
                <i class="fa fa-chevron-right explore-arrow"></i>
            </a>
            <a href="javascript:void(0)" class="explore-card" onclick="pSwitchTab('flats',document.querySelectorAll('.ptab-btn')[6])">
                <div class="explore-card-inner">
                    <div class="explore-icon"><i class="fa fa-th"></i></div>
                    <div class="explore-title">Flats</div>
                    <div class="explore-sub"><?= $total_flats ?> units</div>
                </div>
                <i class="fa fa-chevron-right explore-arrow"></i>
            </a>
            <a href="javascript:void(0)" class="explore-card" onclick="pSwitchTab('committee',document.querySelectorAll('.ptab-btn')[7])">
                <div class="explore-card-inner">
                    <div class="explore-icon"><i class="fa fa-users"></i></div>
                    <div class="explore-title">Committee</div>
                    <div class="explore-sub"><?= $committee_count ?> members</div>
                </div>
                <i class="fa fa-chevron-right explore-arrow"></i>
            </a>
            <a href="javascript:void(0)" class="explore-card" onclick="pSwitchTab('about',document.querySelectorAll('.ptab-btn')[1])">
                <div class="explore-card-inner">
                    <div class="explore-icon"><i class="fa fa-star"></i></div>
                    <div class="explore-title">Amenities</div>
                    <div class="explore-sub"><?= count($amenities) ?> listed</div>
                </div>
                <i class="fa fa-chevron-right explore-arrow"></i>
            </a>
        </div>

        <?php if(!empty($nearby)): ?>
        <div class="section-title" style="font-size:1.15rem"><i class="fa fa-location-dot"></i> Nearby Places</div>
        <div class="nearby-grid">
            <?php foreach($nearby as $n): ?>
            <div class="nearby-card">
                <div class="nearby-icon"><i class="fa fa-location-dot"></i></div>
                <div>
                    <div class="nearby-name"><?= htmlspecialchars($n['name']) ?></div>
                    <div class="nearby-meta"><?= htmlspecialchars($n['category']) ?> · <?= $n['distance_km'] ?> km</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if(!empty($upcoming_events)): ?>
        <div class="events-head">
            <div class="section-title" style="font-size:1.15rem;margin-bottom:0"><i class="fa fa-calendar"></i> Upcoming Events</div>
            <a href="javascript:void(0)" class="view-all-link" onclick="pSwitchTab('events',document.querySelectorAll('.ptab-btn')[3])">View all <i class="fa fa-chevron-right"></i></a>
        </div>
        <div class="events-grid">
            <?php foreach($upcoming_events as $ev): ?>
            <div class="event-card">
                <span class="event-tag">Event</span>
                <div class="event-title"><?= htmlspecialchars($ev['title']) ?></div>
                <div class="event-date"><?= date('d/m/Y, H:i:s', strtotime($ev['event_date'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="cta-section">
            <div class="cta-text">Own or manage a society?</div>
            <a href="/shivam/register-society.php" class="cta-btn"><i class="fa fa-building"></i> Register Your Society</a>
        </div>

    </div>

    <!-- ══ ABOUT TAB ══ -->
    <div class="ptab-section" id="ptab-about">
        <div class="section-title"><i class="fa fa-building"></i> About <?= htmlspecialchars($society['society_name']) ?></div>
        <div class="about-content">
            <p><?= nl2br(htmlspecialchars($society['description'] ?? 'No description available.')) ?></p>
            <div class="section-title" style="font-size:1rem;margin-bottom:14px"><i class="fa fa-star"></i> Amenities</div>
            <?php if(empty($amenities)): ?>
            <p style="font-size:.85rem;color:var(--muted)">No amenities listed yet.</p>
            <?php else: ?>
            <div class="amenities-list">
                <?php foreach($amenities as $a): ?>
                <span class="amenity-pill"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($a) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ GALLERY TAB ══ -->
    <div class="ptab-section" id="ptab-gallery">
        <div class="section-title"><i class="fa fa-images"></i> Photo Gallery</div>
        <?php if(empty($gallery)): ?>
        <div class="empty-state"><i class="fa fa-images"></i><p>No photos uploaded yet.</p></div>
        <?php else: ?>
        <div class="gallery-grid">
            <?php foreach($gallery as $g): ?>
            <div class="gallery-item"><img src="<?= htmlspecialchars($g['image_url']) ?>" alt="<?= htmlspecialchars($g['caption']??'') ?>" loading="lazy"></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ EVENTS TAB ══ -->
    <div class="ptab-section" id="ptab-events">
        <div class="section-title"><i class="fa fa-calendar"></i> All Events</div>
        <?php
        $all_events = $pdo->prepare("SELECT * FROM society_events WHERE society_id=? ORDER BY event_date DESC");
        $all_events->execute([$society_id]); $all_events = $all_events->fetchAll();
        ?>
        <?php if(empty($all_events)): ?>
        <div class="empty-state"><i class="fa fa-calendar"></i><p>No events scheduled yet.</p></div>
        <?php else: ?>
        <div class="events-grid">
            <?php foreach($all_events as $ev): ?>
            <div class="event-card">
                <span class="event-tag">Event</span>
                <div class="event-title"><?= htmlspecialchars($ev['title']) ?></div>
                <div class="event-date"><?= date('d/m/Y, H:i:s', strtotime($ev['event_date'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ REVIEWS TAB ══ -->
    <div class="ptab-section" id="ptab-reviews">
        <div class="section-title"><i class="fa fa-star"></i> Reviews <span style="color:var(--muted);font-size:.9rem;font-weight:500">(<?= $avg_rating ?> ★ average)</span></div>
        <?php if(empty($reviews)): ?>
        <div class="empty-state"><i class="fa fa-star"></i><p>No reviews yet.</p></div>
        <?php else: ?>
        <?php foreach($reviews as $r): ?>
        <div class="review-card">
            <div class="review-stars"><?= str_repeat('★',round($r['rating'])) ?><?= str_repeat('☆',5-round($r['rating'])) ?></div>
            <p class="review-comment">"<?= htmlspecialchars($r['comment']) ?>"</p>
            <div class="review-name"><?= htmlspecialchars($r['reviewer_name']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ══ VENDORS TAB ══ -->
    <div class="ptab-section" id="ptab-vendors">
        <div class="section-title"><i class="fa fa-store"></i> Vendor Services</div>
        <?php if(empty($vendors)): ?>
        <div class="empty-state"><i class="fa fa-store"></i><p>No vendors listed yet.</p></div>
        <?php else: ?>
        <div class="vendor-grid">
            <?php foreach($vendors as $v): ?>
            <div class="vendor-card">
                <div class="vendor-icon"><i class="fa fa-store"></i></div>
                <div>
                    <div class="vendor-name"><?= htmlspecialchars($v['name']) ?></div>
                    <div class="vendor-cat"><?= htmlspecialchars($v['category']??'General') ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ FLATS TAB ══ -->
    <div class="ptab-section" id="ptab-flats">
        <div class="section-title"><i class="fa fa-th"></i> Flats &amp; Units</div>
        <?php if(empty($flats)): ?>
        <div class="empty-state"><i class="fa fa-th"></i><p>No flats listed yet.</p></div>
        <?php else: ?>
        <div class="flat-grid">
            <?php foreach($flats as $f):
                $st=$f['status']; $bc='fb-'.$st; $bl=strtoupper(str_replace('_',' ',$st));
            ?>
            <div class="flat-card">
                <span class="flat-badge <?= $bc ?>"><?= $bl ?></span>
                <div class="flat-no"><?= htmlspecialchars($f['flat_no']) ?></div>
                <div class="flat-sub">Tower <?= htmlspecialchars($f['tower']??'—') ?> · Floor <?= $f['floor'] ?></div>
                <div class="flat-details"><span><?= htmlspecialchars($f['bhk']) ?></span><span><?= $f['area_sqft']?number_format($f['area_sqft']).' sqft':'' ?></span></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ COMMITTEE TAB ══ -->
    <div class="ptab-section" id="ptab-committee">
        <div class="section-title"><i class="fa fa-users"></i> Society Committee</div>
        <?php if(empty($committee)): ?>
        <div class="empty-state"><i class="fa fa-users"></i><p>No committee members listed yet.</p></div>
        <?php else: ?>
        <div class="committee-grid">
            <?php
            $colors=['#2d7a52','#1d4ed8','#6d28d9','#c2410c','#0369a1'];
            foreach($committee as $c):
                $init=strtoupper(substr($c['name'],0,1));
                $col=$colors[abs(crc32($c['name']))%count($colors)];
            ?>
            <div class="committee-card">
                <div class="committee-avatar" style="background:<?= $col ?>22;color:<?= $col ?>"><?= $init ?></div>
                <div class="committee-name"><?= htmlspecialchars($c['name']) ?></div>
                <div class="committee-pos"><?= htmlspecialchars($c['position']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /main-wrap -->

<script>
function pSwitchTab(id, btn) {
    document.querySelectorAll('.ptab-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.ptab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('ptab-' + id).classList.add('active');
    if (btn) btn.classList.add('active');
    window.scrollTo({ top: document.querySelector('.profile-tabs').offsetTop - 20, behavior: 'smooth' });
}
</script>

</body>
</html>