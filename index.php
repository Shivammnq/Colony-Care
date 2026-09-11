<?php
$pageTitle = "ColonyCare - Society Management Platform for RWAs";
$pageDescription = "Manage visitor entry, billing, complaints, events, and facility booking for your residential society — all in one platform. Trusted by 500+ communities.";
$pageCanonical = 'https://www.example.com/shivam/index.php';
$extraSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'ColonyCare',
    'url' => $pageCanonical,
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => 'https://www.example.com/shivam/societies.php?q={search_term_string}',
        'query-input' => 'required name=search_term_string',
    ],
];
include('header.php');
?>

<!-- HERO SECTION -->
<section class="hero">
    <div class="container hero-flex">

        <!-- LEFT -->
        <div class="hero-text">
            <span class="badge">India's Smartest Colony Management Platform</span>

            <h1>Manage Your Colony <span style="color: #e6fffa;">Like Never Before</span></h1>

            <p>From visitor management to billing, complaints to community events - ColonyCare brings everything your RWA needs into one powerful platform.</p>

            <div class="hero-buttons">
                <a href="#" class="btn primary">Get Started Free</a>
                <a href="#" class="btn secondary">Book a Demo</a>
            </div>

            <div class="stats">
                <div><h3>500+</h3><p>Communities</p></div>
                <div><h3>50K+</h3><p>Residents</p></div>
                <div><h3>99.9%</h3><p>Uptime</p></div>
                <div><h3>4.8★</h3><p>Rating</p></div>
            </div>
        </div>

        <!-- RIGHT (FLOATING IMAGE) -->
        <div class="hero-image">
            <img src="<?php echo $base_url; ?>assets/images/dashboard.png" alt="dashboard">
        </div>

    </div>

<div class="scroll-down">
    <a href="#features">
        <i class="fa fa-chevron-down"></i>
    </a>
</div>

</section>


<!-- FEATURES -->
<section class="features" id="features">
    <div class="container">
        <h2>Powerful Features</h2>

        <div class="grid">
            <?php
            $features = [
                ["Resident Directory", "fa-users"],
                ["Announcements", "fa-bullhorn"],
                ["Event Calendar", "fa-calendar"],
                ["Complaints", "fa-comments"],
                ["Facility Booking", "fa-building"],
                ["Visitor Management", "fa-id-badge"],
                ["Online Payments", "fa-credit-card"],
                ["Billing", "fa-file-invoice"],
                ["Reports", "fa-chart-line"],
            ];

            foreach($features as $f){
                echo "
                <div class='card-item-wrapper'>
                    <div class='card'>
                        <i class='fa ".$f[1]."'></i>
                        <h3>".$f[0]."</h3>
                        <p>Manage ".$f[0]." efficiently with ColonyCare.</p>
                    </div>
                </div>";
            }
            ?>
        </div>

    </div>
</section>

<!-- SERVICES -->
<section class="services">
    <div class="container">

        <span class="badge-green">Our Services</span>
        <h2>Complete Colony Services</h2>
        <p class="sub-text">
            Everything your community needs - from gate security to financial 
            management, all in one platform.
        </p>

        <div class="grid services-grid">

            <!-- 1 -->
            <div>
                <div class="service-card">
                    <i class="fa fa-door-open"></i>
                    <h3>Gate Office</h3>
                    <p>24/7 digital gate management with visitor passes, delivery tracking, vehicle logging, and real-time entry/exit monitoring.</p>
                    <a href="#" class="explore-btn">Explore <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>

            <!-- 2 -->
            <div>
                <div class="service-card">
                    <i class="fa fa-wrench"></i>
                    <h3>Vendor Services</h3>
                    <p>Manage plumbers, electricians, housekeeping & AMC vendors with contracts, ratings, and payment tracking.</p>
                    <a href="#" class="explore-btn">Explore <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>

            <!-- 3 -->
            <div>
                <div class="service-card">
                    <i class="fa fa-shield"></i>
                    <h3>Security & Access</h3>
                    <p>QR-based resident access, temporary visitor passes, OTP verification, and emergency SOS broadcasting.</p>
                    <a href="#" class="explore-btn">Explore <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>

            <!-- 4 -->
            <div>
                <div class="service-card">
                    <i class="fa fa-calculator"></i>
                    <h3>Billing & Accounts</h3>
                    <p>Automated maintenance billing, online payments (UPI, cards), expense tracking, and audit-ready financial reports.</p>
                    <a href="#" class="explore-btn">Explore <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>

            <!-- 5 -->
            <div>
                <div class="service-card">
                    <i class="fa fa-bullhorn"></i>
                    <h3>Community Notices</h3>
                    <p>Broadcast announcements with priority levels, scheduling, read receipts, and push notifications to all residents.</p>
                    <a href="#" class="explore-btn">Explore <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>

            <!-- 6 -->
            <div>
                <div class="service-card">
                    <i class="fa fa-cog"></i>
                    <h3>Facility Management</h3>
                    <p>Book clubhouse, gym, swimming pool, and courts with real-time availability, conflict prevention & auto-reminders.</p>
                    <a href="#" class="explore-btn">Explore <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- Society Secretary -->
<section class="secretary-tools">
    <div class="container">

        <h2>Tools for the Society Secretary</h2>
        <p class="sub-text">
            Streamline all your secretarial duties - from meeting management to compliance tracking, everything at your fingertips.
        </p>

        <div class="tools-grid">

            <div class="tool-card">
                <i class="fa-solid fa-clipboard-list"></i>
                <h3>Meeting Management</h3>
                <p>Schedule AGMs, committee meetings with agenda, minutes, and attendance tracking.</p>
            </div>

            <div class="tool-card">
                <i class="fa-solid fa-bell"></i>
                <h3>Notice Board</h3>
                <p>Draft, schedule, and broadcast official notices with priority tagging and read receipts.</p>
            </div>

            <div class="tool-card">
                <i class="fa-solid fa-chart-column"></i>
                <h3>Society Reports</h3>
                <p>Generate monthly/annual reports covering finances, complaints, occupancy, and compliance.</p>
            </div>

            <div class="tool-card">
                <i class="fa-solid fa-users"></i>
                <h3>Committee Dashboard</h3>
                <p>Manage RWA committee members, assign roles, and track responsibilities.</p>
            </div>

            <div class="tool-card">
                <i class="fa-solid fa-file-lines"></i>
                <h3>Document Vault</h3>
                <p>Store society bylaws, NOCs, registration certificates, and legal documents securely.</p>
            </div>

            <div class="tool-card">
                <i class="fa-solid fa-square-check"></i>
                <h3>Resolution Tracking</h3>
                <p>Track all society resolutions, voting outcomes, and implementation status.</p>
            </div>

            <div class="tool-card">
                <i class="fa-solid fa-shield-halved"></i>
                <h3>Compliance Monitor</h3>
                <p>Stay on top of statutory compliance - fire safety, lift AMC, insurance renewals.</p>
            </div>

            <div class="tool-card">
                <i class="fa-solid fa-calendar-check"></i>
                <h3>Event Coordination</h3>
                <p>Plan society events, festivals, and community programs with budget and volunteer management.</p>
            </div>

        </div>
    </div>
</section>

<!-- Society Members Section -->
<section class="household-section">
    <div class="container household-flex">

        <!-- LEFT CONTENT -->
        <div class="household-left">

            <span class="badge">Society Members</span>

            <h2>Your Household, Fully Connected</h2>

            <p class="main-text">
                Add your family members, give them access to society features, and manage
                everything from one place. The main admin of each unit has full control
                over all household member activities.
            </p>

            <!-- FEATURES LIST -->
            <div class="household-list">

                <div class="list-item">
                    <i class="fa-solid fa-user-plus"></i>
                    <div>
                        <h4>Add Family Members</h4>
                        <p>Register spouse, children, parents, and other family members under your unit.</p>
                    </div>
                </div>

                <div class="list-item">
                    <i class="fa-solid fa-key"></i>
                    <div>
                        <h4>Access Management</h4>
                        <p>Control which features each member can access - payments, complaints, bookings.</p>
                    </div>
                </div>

                <div class="list-item">
                    <i class="fa-solid fa-eye"></i>
                    <div>
                        <h4>House Admin Control</h4>
                        <p>Monitor all family activities, bookings, and visitor logs.</p>
                    </div>
                </div>

            </div>

            <a href="#" class="household-btn">
                Manage Your Household →
            </a>

        </div>


        <!-- RIGHT CARDS -->
        <div class="household-right">

            <div class="house-card">
                <i class="fa-solid fa-user-plus"></i>
                <h3>Add Family Members</h3>
                <p>Register spouse, children, parents, and other members with individual profiles.</p>
            </div>

            <div class="house-card">
                <i class="fa-solid fa-key"></i>
                <h3>Access Management</h3>
                <p>Control which society features each member can access.</p>
            </div>

            <div class="house-card">
                <i class="fa-solid fa-eye"></i>
                <h3>House Admin Control</h3>
                <p>Monitor activities, bookings, and visitor logs.</p>
            </div>

            <div class="house-card">
                <i class="fa-solid fa-qrcode"></i>
                <h3>Individual QR Access</h3>
                <p>Each member gets a QR code for seamless entry.</p>
            </div>

            <div class="house-card">
                <i class="fa-solid fa-house"></i>
                <h3>Unit Dashboard</h3>
                <p>View dues, visitors, complaints, and bookings in one place.</p>
            </div>

            <div class="house-card">
                <i class="fa-solid fa-shield"></i>
                <h3>Permission Levels</h3>
                <p>Set Full, Limited, or Custom access for each member.</p>
            </div>

        </div>

    </div>
</section>

<!-- Stakeholders -->
<section class="stakeholders">
    <div class="container">

        <span class="badge light">Role-Based Access</span>

        <h2>Built for Every Stakeholder</h2>
        <p class="sub-text">
            Tailored tools for every role in your society - simple, powerful, and easy to use.
        </p>

        <div class="grid stakeholders-grid">

            <!-- Resident -->
            <div class="stake-card">
                <i class="fa fa-user"></i>
                <h3>Resident</h3>
                <ul>
                    <li>View directory & notices</li>
                    <li>Raise complaints</li>
                    <li>Book facilities</li>
                    <li>Make payments</li>
                    <li>Participate in polls</li>
                </ul>
                <a href="/resident" class="explore-btn">Explore →</a>
            </div>

            <!-- Gate Staff -->
            <div class="stake-card">
                <i class="fa fa-door-open"></i>
                <h3>Gate Staff</h3>
                <ul>
                    <li>Manage visitor entry</li>
                    <li>Delivery & cab logs</li>
                    <li>Vehicle tracking</li>
                    <li>Digital pass scanning</li>
                </ul>
                <a href="/gate-staff" class="explore-btn">Explore →</a>
            </div>

            <!-- Admin -->
            <div class="stake-card">
                <i class="fa fa-shield"></i>
                <h3>Admin / RWA</h3>
                <ul>
                    <li>Billing management</li>
                    <li>Complaint management</li>
                    <li>Vendor management</li>
                    <li>Reports & auditing</li>
                </ul>
                <a href="/admin" class="explore-btn">Explore →</a>
            </div>

            <!-- Accountant -->
            <div class="stake-card">
                <i class="fa fa-calculator"></i>
                <h3>Accountant</h3>
                <ul>
                    <li>Billing & invoices</li>
                    <li>Expense tracking</li>
                    <li>Audit-ready exports</li>
                    <li>Tax summaries</li>
                </ul>
                <a href="/accountant" class="explore-btn">Explore →</a>
            </div>

            <!-- Vendor -->
            <div class="stake-card">
                <i class="fa fa-store"></i>
                <h3>Vendor</h3>
                <ul>
                    <li>Service ticket management</li>
                    <li>Payment tracking</li>
                    <li>Performance dashboard</li>
                </ul>
                <a href="/vendor" class="explore-btn">Explore →</a>
            </div>

        </div>
    </div>
</section>

<!-- Simple Step -->
<section class="steps">
    <div class="container">

        <span class="badge light">Simple Setup</span>

        <h2>Get Started in Minutes</h2>
        <p class="sub-text">
            Four simple steps to transform how your colony operates.
        </p>

        <div class="steps-wrapper">

            <!-- STEP 1 -->
            <div class="step-card">
                <div class="step-icon">
                    <i class="fa fa-user-plus"></i>
                </div>
                <span class="step-number">STEP 01</span>
                <h3>Register Your Colony</h3>
                <p>Sign up and add your community details - blocks, flats, and common areas.</p>
            </div>

            <!-- STEP 2 -->
            <div class="step-card">
                <div class="step-icon">
                    <i class="fa fa-cog"></i>
                </div>
                <span class="step-number">STEP 02</span>
                <h3>Configure Modules</h3>
                <p>Enable billing, visitor management, complaints, and other modules your RWA needs.</p>
            </div>

            <!-- STEP 3 -->
            <div class="step-card">
                <div class="step-icon">
                    <i class="fa fa-paper-plane"></i>
                </div>
                <span class="step-number">STEP 03</span>
                <h3>Invite Residents</h3>
                <p>Share invite links. Residents join, update profiles, and start using the platform.</p>
            </div>

            <!-- STEP 4 -->
            <div class="step-card">
                <div class="step-icon">
                    <i class="fa fa-chart-line"></i>
                </div>
                <span class="step-number">STEP 04</span>
                <h3>Manage & Grow</h3>
                <p>Track finances, resolve complaints, manage vendors - all from one dashboard.</p>
            </div>

        </div>

    </div>
</section>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- FEATURED SOCIETIES SECTION — paste between Steps & Testimonials -->
<!-- ══════════════════════════════════════════════════════════ -->

<?php
// ── Fetch societies from DB ────────────────────────────────
$featured_societies = [];
try {
    $pdo_fs = new PDO("mysql:host=localhost;dbname=cc;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $featured_societies = $pdo_fs->query("
        SELECT id, society_name, city, state, pincode, total_flats, established_year, description
        FROM societies
        ORDER BY created_at DESC
        LIMIT 6
    ")->fetchAll();
} catch (Exception $e) {
    // silently fail — section just won't show
}

// ── Placeholder images (cycled if no real image) ───────────
$placeholderImages = [
    'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?w=600&q=80',
    'https://images.unsplash.com/photo-1460317442991-0ec209397118?w=600&q=80',
    'https://images.unsplash.com/photo-1486325212027-8081e485255e?w=600&q=80',
    'https://images.unsplash.com/photo-1568605114967-8130f3a36994?w=600&q=80',
    'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=600&q=80',
    'https://images.unsplash.com/photo-1580587771525-78b9dba3b914?w=600&q=80',
];

// ── Slug generator ─────────────────────────────────────────
function societySlug($name) {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
}
?>

<?php if (!empty($featured_societies)): ?>
<section class="featured-societies">
    <div class="container">

        <div class="fs-header">
            <div>
                <h2>Featured Societies</h2>
                <p class="sub-text">Explore residential societies powered by ColonyCare - view amenities, events, gallery and available properties.</p>
            </div>
            <a href="/shivam/societies.php" class="fs-browse-btn">Browse all <i class="fa fa-arrow-right"></i></a>
        </div>

        <div class="fs-grid">
            <?php foreach($featured_societies as $i => $soc):
                $slug  = societySlug($soc['society_name']);
                $img   = $placeholderImages[$i % count($placeholderImages)];
                $flats = $soc['total_flats'] ? $soc['total_flats'].' flats' : '';
                $est   = $soc['established_year'] ? 'Est. '.$soc['established_year'] : '';
                $loc   = trim(($soc['city'] ?? '').', '.($soc['state'] ?? ''), ', ');
                $desc  = $soc['description'] ? (strlen($soc['description']) > 100 ? substr($soc['description'],0,100).'...' : $soc['description']) : '';
                $isFirst = ($i === 0); // first card has no image (icon placeholder style)
            ?>
            <a href="/shivam/society-profile.php?slug=<?= urlencode($slug) ?>&id=<?= $soc['id'] ?>"
               class="fs-card <?= $isFirst ? 'fs-card-placeholder' : '' ?>">

                <?php if ($isFirst): ?>
                    <!-- Placeholder card (no image) -->
                    <div class="fs-card-noimg">
                        <i class="fa-solid fa-building-columns"></i>
                    </div>
                <?php else: ?>
                    <div class="fs-card-img">
                        <img src="<?= $img ?>" alt="<?= htmlspecialchars($soc['society_name']) ?>" loading="lazy">
                    </div>
                <?php endif; ?>

                <div class="fs-card-body">
                    <h3 class="fs-card-name"><?= htmlspecialchars($soc['society_name']) ?></h3>
                    <?php if ($loc): ?>
                    <div class="fs-card-loc">
                        <i class="fa-solid fa-location-dot"></i>
                        <?= htmlspecialchars($loc) ?>
                    </div>
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

    </div>
</section>

<style>
/* ══ FEATURED SOCIETIES ══════════════════════════════════════ */
.featured-societies {
    padding: 80px 0;
    background: #f8fffe;
    border-top: 1px solid #e5ece8;
}
.fs-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 36px;
    flex-wrap: wrap;
    gap: 16px;
}
.fs-header h2 {
    font-size: 2rem;
    font-weight: 700;
    color: #1a2e22;
    margin-bottom: 6px;
}
.fs-header .sub-text {
    font-size: .9rem;
    color: #5a7060;
    max-width: 520px;
    margin: 0;
}
.fs-browse-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    border: 1.5px solid #1a2e22;
    border-radius: 99px;
    font-size: .85rem;
    font-weight: 600;
    color: #1a2e22;
    text-decoration: none;
    white-space: nowrap;
    transition: all .2s;
    flex-shrink: 0;
    margin-top: 6px;
}
.fs-browse-btn:hover {
    background: #1a2e22;
    color: #fff;
}

/* Grid */
.fs-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

/* Card */
.fs-card {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid #e5ece8;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    transition: box-shadow .2s, transform .2s;
    cursor: pointer;
}
.fs-card:hover {
    box-shadow: 0 8px 32px rgba(26,46,34,.12);
    transform: translateY(-3px);
}

/* Image card */
.fs-card-img {
    width: 100%;
    height: 200px;
    overflow: hidden;
}
.fs-card-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform .3s ease;
}
.fs-card:hover .fs-card-img img {
    transform: scale(1.04);
}

/* Placeholder (no image) card */
.fs-card-placeholder {
    border-color: #dce8e1;
}
.fs-card-noimg {
    width: 100%;
    height: 200px;
    background: #f0f5f2;
    display: flex;
    align-items: center;
    justify-content: center;
}
.fs-card-noimg i {
    font-size: 3rem;
    color: #a0c4b8;
}

/* Card body */
.fs-card-body {
    padding: 16px 20px 20px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.fs-card-name {
    font-size: 1rem;
    font-weight: 700;
    color: #1a7a5e;
    margin: 0;
    line-height: 1.3;
}
.fs-card-loc {
    font-size: .8rem;
    color: #5a7060;
    display: flex;
    align-items: center;
    gap: 5px;
}
.fs-card-loc i {
    color: #1a7a5e;
    font-size: .75rem;
}
.fs-card-desc {
    font-size: .82rem;
    color: #5a7060;
    line-height: 1.5;
    margin: 2px 0 0;
    flex: 1;
}
.fs-card-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .78rem;
    color: #8fa898;
    margin-top: 4px;
}
.fs-dot { color: #c5d5cc; }

/* Responsive */
@media (max-width: 900px) { .fs-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) {
    .fs-grid { grid-template-columns: 1fr; }
    .fs-header h2 { font-size: 1.5rem; }
    .featured-societies { padding: 50px 0; }
}
</style>
<?php endif; ?>

<!-- TESTIMONIAL -->
<section class="testimonials">
    <div class="container">

        <span class="badge">Trusted by Communities</span>
        <h2>What Our Users Say</h2>
        <p class="sub-text">
            Hear from RWA presidents, residents, and staff who transformed their community operations.
        </p>

        <div class="testimonial-slider">

            <div class="testimonial-track">

                <!-- SLIDE 1 -->
                <div class="testimonial-slide">
                    
                    <div class="testimonial-card">
                        <p>"ColonyCare has completely transformed how we manage our 300-unit society. 
                            Billing collection went from 60% to 95% within 3 months. T
                            he automated reminders and online payment system are game-changers."</p>
                        <div class="stars">★★★★★</div>
                        <h4>Rajesh Sharma</h4>
                        <span>RWA President • Green Valley Township, Gurgaon</span>
                    </div>

                    <div class="testimonial-card">
                        <p>"I love how easy it is to book the clubhouse, pay maintenance, and track my complaints - all from my phone. 
                            The visitor management gives me so much peace of mind when I'm traveling."</p>
                        <div class="stars">★★★★★</div>
                        <h4>Priya Mehta</h4>
                        <span>Resident • Sunshine Apartments, Mumbai</span>
                    </div>

                    <div class="testimonial-card">
                        <p>"The audit-ready reports and expense tracking saved me weeks of work during our annual audit. 
                            I can generate income vs expense statements, defaulter lists, and GST invoices in just a few clicks."</p>
                        <div class="stars">★★★★★</div>
                        <h4>Anil Gupta</h4>
                        <span>Society Accountant • Palm Residency, Noida</span>
                    </div>

                </div>

                <!-- SLIDE 2 -->
                <div class="testimonial-slide">
                    
                    <div class="testimonial-card">
                        <p>"Managing 5 vendor contracts, tracking guard attendance, 
                            and resolving 50+ monthly complaints used to be a nightmare. 
                            ColonyCare's dashboard gives me everything at a glance."</p>
                        <div class="stars">★★★★★</div>
                        <h4>Sunita Reddy</h4>
                        <span>Admin Manager • Lakeside Villas, Hyderabad</span>
                    </div>

                    <div class="testimonial-card">
                        <p>"The gate management module is brilliant. Digital visitor passes, vehicle logging, 
                            and delivery tracking have made our security operations seamless. 
                            Guards love the simple interface."</p>
                        <div class="stars">★★★★☆</div>
                        <h4>Mohammed Farooq</h4>
                        <span>Security Head • Royal Enclave, Bangalore</span>
                    </div>

                    <div class="testimonial-card">
                        <p>"Polls and voting feature helped us conduct our AGM smoothly with 85% participation. 
                            The discussion forums keep residents engaged and the event calendar is always buzzing."</p>
                        <div class="stars">★★★★★</div>
                        <h4>Kavita Singh</h4>
                        <span>RWA Secretary • Heritage Heights, Jaipur</span>
                    </div>

                </div>

            </div>

        </div>

        <!-- CONTROLS -->
        <div class="testimonial-controls">
            <button class="nav-btn prev">&#10094;</button>

            <div class="dots">
                <span class="dot active"></span>
                <span class="dot"></span>
            </div>

            <button class="nav-btn next">&#10095;</button>
        </div>

    </div>
</section>

<?php include('footer.php'); ?>