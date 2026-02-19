<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/TornApi.php';

/* --------------------
   ENV + API SETUP
-------------------- */
// Try environment variable first (Render, Docker, etc.)
$key = (string)(getenv('TORN_API_KEY') ?: '');

// Fall back to .env file for local development
if ($key === '' || $key === 'put-your-key-here') {
    $envPath = __DIR__ . '/../.env';
    if (file_exists($envPath)) {
        $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
        $key = (string)($env['TORN_API_KEY'] ?? '');
    }
}

if ($key === '' || $key === 'put-your-key-here') {
    http_response_code(500);
    exit('Set TORN_API_KEY environment variable');
}

$api = new TornApi($key);

/* --------------------
   FETCH OWNED PROPERTIES
-------------------- */
$userData = $api->get('/user/', ['selections' => 'properties']);
$allProperties = $userData['properties'] ?? [];

// Debug mode: dump raw property data to inspect statuses
if (isset($_GET['debug'])) {
    header('Content-Type: application/json');
    $debug = [];
    foreach ($allProperties as $prop) {
        $debug[] = [
            'id'          => $prop['id'] ?? null,
            'property_id' => $prop['property']['id'] ?? null,
            'status'      => $prop['status'] ?? null,
            'happy'       => $prop['happy'] ?? null,
        ];
    }
    echo json_encode($debug, JSON_PRETTY_PRINT);
    exit;
}

$privateIslands = [];
foreach ($allProperties as $property) {
    if ((int)($property['property']['id'] ?? 0) === 13 && ($property['status'] ?? '') !== 'in_use') {
        $privateIslands[] = $property;
    }
}

// Sort by happy DESC
usort($privateIslands, fn($a,$b) => ($b['happy'] ?? 0) <=> ($a['happy'] ?? 0));

/* --------------------
   MARKET RENTAL CACHE
-------------------- */
$cacheDir  = __DIR__ . '/../cache';
$cacheFile = $cacheDir . '/pi_rentals.json';
$cacheTtl  = 300;

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

function readCache(string $file, int $ttl): ?array {
    if (!file_exists($file)) return null;
    if (time() - filemtime($file) > $ttl) return null;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function writeCache(string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES));
}

/* --------------------
   FETCH PI RENTALS (PAGED)
-------------------- */
function fetchPirentals(TornApi $api, int $limit = 50, int $pages = 6): array {
    $all = [];
    $offset = 0;

    for ($i = 0; $i < $pages; $i++) {
        // Your wrapper appears to already be on /v2, so we use /market/... not /v2/market/...
        $data = $api->get('/market/13/rentals', [
            'limit' => $limit,
            'offset' => $offset,
            'sort' => 'asc',
        ]);

        $listings = $data['rentals']['listings'] ?? [];
        if (is_array($listings)) {
            foreach ($listings as $l) {
                if (is_array($l)) $all[] = $l;
            }
        }

        if (empty($data['_metadata']['links']['next'])) break;
        $offset += $limit;
    }

    return $all;
}

$piRentals = readCache($cacheFile, $cacheTtl);
if (!is_array($piRentals) || count($piRentals) === 0) {
    $piRentals = fetchPirentals($api);
    writeCache($cacheFile, $piRentals);
}

/* --------------------
   MARKET COMPS LOGIC
-------------------- */
function percentile(array $values, float $p): int {
    $values = array_values(array_filter($values, fn($v) => is_int($v) || ctype_digit((string)$v)));
    $values = array_map('intval', $values);
    sort($values);

    $n = count($values);
    if ($n === 0) return 0;
    if ($n === 1) return (int)$values[0];

    $idx = ($n - 1) * $p;
    $lo = (int)floor($idx);
    $hi = (int)ceil($idx);
    if ($lo === $hi) return (int)$values[$lo];

    $w = $idx - $lo;
    return (int)round($values[$lo] * (1 - $w) + $values[$hi] * $w);
}

function findComps(array $rentals, int $happy, int $band = 100, int $limit = 12): array {
    $exact = [];
    $near  = [];

    foreach ($rentals as $r) {
        $h = (int)($r['happy'] ?? 0);
        $perDay = (int)($r['cost_per_day'] ?? 0);
        if ($h <= 0 || $perDay <= 0) continue;

        $entry = [
            'happy'   => $h,
            'per_day' => $perDay,
            'days'    => (int)($r['rental_period'] ?? 0),
            'diff'    => abs($h - $happy),
        ];

        if ($h === $happy) $exact[] = $entry;
        elseif ($entry['diff'] <= $band) $near[] = $entry;
    }

    usort($exact, fn($a,$b) => $a['per_day'] <=> $b['per_day']);
    usort($near, fn($a,$b) => ($a['diff'] <=> $b['diff']) ?: ($a['per_day'] <=> $b['per_day']));

    $picked = array_slice(array_merge($exact, $near), 0, $limit);
    $daily  = array_map(fn($x) => (int)$x['per_day'], $picked);
    sort($daily);

    return [
        'count'   => count($picked),
        'median'  => percentile($daily, 0.50),
        'p25'     => percentile($daily, 0.25),
        'p75'     => percentile($daily, 0.75),
        'samples' => array_slice($picked, 0, 6),
    ];
}

/* --------------------
   UI HELPERS
-------------------- */
function money(int $v): string { return '$' . number_format($v); }

function formatCurrency($amount): string {
    return '$' . number_format((int)$amount);
}

function getStatusClass(string $status): string {
    return match($status) {
        'none', 'for_rent' => 'status-available',
        'in_use' => 'status-occupied',
        'rented' => 'status-rented',
        default  => 'status-unknown',
    };
}

function getStatusLabel(string $status): string {
    return match($status) {
        'none'     => 'Available to Rent',
        'for_rent' => 'Listed for Rent',
        'in_use'   => 'In Use',
        'rented' => 'Rented',
        default  => ucfirst($status),
    };
}

/* --------------------
   SPLIT INTO SECTIONS
-------------------- */
$availableProperties = array_values(array_filter($privateIslands, fn($p) => in_array($p['status'] ?? '', ['none', 'for_rent'], true)));
$rentedProperties    = array_values(array_filter($privateIslands, fn($p) => ($p['status'] ?? '') === 'rented'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Private Island Properties</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,Cantarell,sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height:100vh;
    padding:20px;
}
.container{max-width:1400px;margin:0 auto}
.header{
    background:#fff;border-radius:12px;padding:30px;margin-bottom:30px;
    box-shadow:0 4px 6px rgba(0,0,0,.1)
}
.header h1{color:#333;font-size:2.5em;margin-bottom:10px}
.header .count{color:#666;font-size:1.2em}

.section-header{
    background:#fff;border-radius:12px;padding:20px 30px;margin:30px 0 20px 0;
    box-shadow:0 2px 4px rgba(0,0,0,.1);
    display:flex;justify-content:space-between;align-items:center
}
.section-header h2{color:#333;font-size:1.8em;margin:0}
.section-header .section-count{color:#666;font-size:1em;font-weight:500}

.properties-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(400px,1fr));
    gap:20px;
}

.property-card{
    background:#fff;border-radius:12px;padding:25px;
    box-shadow:0 4px 6px rgba(0,0,0,.1);
    transition:transform .2s, box-shadow .2s;
}
.property-card:hover{transform:translateY(-2px);box-shadow:0 6px 12px rgba(0,0,0,.15)}

.property-header{
    display:flex;justify-content:space-between;align-items:flex-start;
    margin-bottom:20px;padding-bottom:15px;border-bottom:2px solid #f0f0f0
}
.property-id{font-size:.9em;color:#999;font-weight:500}

.status-badge{
    padding:6px 12px;border-radius:20px;font-size:.85em;font-weight:600;
    text-transform:uppercase
}
.status-available{background:#d4edda;color:#155724}
.status-occupied{background:#fff3cd;color:#856404}
.status-rented{background:#d1ecf1;color:#0c5460}
.status-unknown{background:#e9ecef;color:#495057}

.property-info{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:15px;
    margin-bottom:20px;
}
.info-item{display:flex;flex-direction:column}
.info-label{
    font-size:.85em;color:#666;margin-bottom:5px;
    text-transform:uppercase;letter-spacing:.5px
}
.info-value{font-size:1.1em;font-weight:600;color:#333}

.section-title{
    font-size:1em;font-weight:600;color:#333;margin:20px 0 10px 0;
    padding-top:15px;border-top:2px solid #f0f0f0
}

.modifications,.staff{display:flex;flex-wrap:wrap;gap:8px}
.badge{
    background:#e9ecef;color:#495057;padding:5px 10px;border-radius:15px;font-size:.85em
}

/* --- Market comps block --- */
.market-comps{
    background:#f8f9fa;
    padding:15px;
    border-radius:10px;
    margin-top:15px;
}
.market-comps summary.market-title{
    font-weight:700;color:#333;
    cursor:pointer;
    list-style:none;
    display:flex;justify-content:space-between;align-items:center;
    user-select:none;
}
.market-comps summary.market-title::after{
    content:'▸';
    font-size:.9em;color:#999;transition:transform .2s;
}
.market-comps details[open] summary.market-title::after{
    transform:rotate(90deg);
}
.market-comps details[open] summary.market-title{
    margin-bottom:10px;
}
.market-comps summary.market-title::-webkit-details-marker{display:none}
.market-comps .market-metrics{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:10px;
    margin-bottom:10px;
}
.market-comps .market-metric{
    background:#fff;border-radius:8px;padding:10px;border:1px solid #eee
}
.market-comps .market-metric .label{
    font-size:.8em;color:#666;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px
}
.market-comps .market-metric .value{font-weight:700;color:#333}
.market-comps .market-samples{display:flex;flex-wrap:wrap;gap:8px}
.market-comps .market-sample{
    background:#667eea;color:#fff;padding:6px 10px;border-radius:14px;font-size:.85em;white-space:nowrap
}

/* Pricing table */
.pricing-table{background:#fff;border-radius:10px;padding:12px;border:1px solid #eee;margin-top:15px}
.pricing-table table{width:100%;border-collapse:collapse}
.pricing-table th,.pricing-table td{padding:8px 10px;text-align:left;border-bottom:1px solid #f1f1f1}
.pricing-table th{font-size:.85em;color:#666;text-transform:uppercase}
.pricing-cta{margin-top:8px;display:flex;gap:8px}
.pricing-btn{background:#667eea;color:#fff;padding:8px 12px;border-radius:8px;text-decoration:none;font-weight:700}
</style>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="shortcut icon" href="/favicon.svg">
<link rel="apple-touch-icon" href="/favicon.svg">
<meta name="theme-color" content="#667eea">
</head>
<body>
<div class="container">

    <div class="header">
        <h1>🏝️ Private Island Properties</h1>
        <div class="count">Total: <?= count($privateIslands) ?> Private Island(s)</div>
    </div>

    <?php if (!empty($availableProperties)): ?>
        <div class="section-header">
            <h2>Available to Rent</h2>
            <span class="section-count"><?= count($availableProperties) ?> property/properties</span>
        </div>

        <div class="properties-grid">
            <?php foreach ($availableProperties as $property): ?>
                <?php
                $targetHappy = (int)($property['happy'] ?? 0);
                $comps = $targetHappy > 0 ? findComps($piRentals, $targetHappy, 100, 12) : ['count'=>0,'median'=>0,'p25'=>0,'p75'=>0,'samples'=>[]];
                ?>
                <div class="property-card">
                    <div class="property-header">
                        <?php $pid = $property['id'] ?? null; ?>
                        <div class="property-id">
                            ID:
                            <?php if (!empty($pid) && is_numeric($pid)): ?>
                                <a href="https://www.torn.com/properties.php#/p=propertyinfo&profile=1&ID=<?= (int)$pid ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)$pid) ?></a>
                            <?php else: ?>
                                <?= htmlspecialchars((string)($property['id'] ?? 'N/A')) ?>
                            <?php endif; ?>
                        </div>
                        <span class="status-badge <?= getStatusClass((string)($property['status'] ?? 'unknown')) ?>">
                            <?= getStatusLabel((string)($property['status'] ?? 'unknown')) ?>
                        </span>
                    </div>

                    <div class="property-info">
                        <div class="info-item">
                            <span class="info-label">Happy</span>
                            <span class="info-value"><?= number_format((int)($property['happy'] ?? 0)) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Property Upkeep</span>
                            <span class="info-value"><?= formatCurrency($property['upkeep']['property'] ?? 0) ?></span>
                        </div>
                    </div>

                    <?php if (!empty($property['modifications'])): ?>
                        <div class="section-title">Modifications</div>
                        <div class="modifications">
                            <?php foreach ($property['modifications'] as $mod): ?>
                                <span class="badge"><?= htmlspecialchars((string)$mod) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($comps['count'])): ?>
                        <?php
                            // Blend pricing base from market: 70% median + 30% p25 (fallbacks handled)
                            $median = (int)($comps['median'] ?? 0);
                            $p25 = (int)($comps['p25'] ?? 0);
                            if ($median === 0 && $p25 === 0) {
                                $rate = 0;
                            } elseif ($median === 0) {
                                $rate = $p25;
                            } elseif ($p25 === 0) {
                                $rate = $median;
                            } else {
                                $rate = (int)round(0.7 * $median + 0.3 * $p25);
                            }
                        ?>
                        <div class="pricing-table">
                            <div class="market-title">💰 Rent Prices</div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Duration</th>
                                        <th>Per day</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ([7,14,30,100] as $d):
                                        // Sliding discount: 5% at 7 days → 10% at 100 days
                                        $minDay = 7;
                                        $maxDay = 100;
                                        $startDiscount = 0.03;
                                        $endDiscount = 0.05;
                                        $t = ($d - $minDay) / ($maxDay - $minDay);
                                        if ($t < 0) $t = 0;
                                        if ($t > 1) $t = 1;
                                        $discount = $startDiscount + $t * ($endDiscount - $startDiscount);

                                        if ($rate > 0) {
                                            $per = (int)round($rate * (1 - $discount));
                                            $total = $per * $d;
                                        } else {
                                            $per = 0;
                                            $total = 0;
                                        }
                                    ?>
                                        <tr>
                                            <td><?= $d ?> days</td>
                                            <td><?= $per > 0 ? formatCurrency($per) : '—' ?></td>
                                            <td><?= $per > 0 ? formatCurrency($total) : '—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="pricing-cta">
                                <a class="pricing-btn" href="https://www.torn.com/messages.php#/p=compose&XID=47662" target="_blank" rel="noopener noreferrer">Contact to Rent</a>
                                <span style="color:#666;align-self:center;font-size:.9em">Prices derived from market median</span>
                            </div>
                        </div>

                        <div class="market-comps">
                            <details>
                                <summary class="market-title">📊 Market comps (similar PI rentals)</summary>

                                <div class="market-metrics">
                                    <div class="market-metric">
                                        <div class="label">Median / day</div>
                                        <div class="value"><?= formatCurrency((int)$comps['median']) ?></div>
                                    </div>
                                    <div class="market-metric">
                                        <div class="label">P25–P75 / day</div>
                                        <div class="value"><?= formatCurrency((int)$comps['p25']) ?> – <?= formatCurrency((int)$comps['p75']) ?></div>
                                    </div>
                                    <div class="market-metric">
                                        <div class="label">Listings</div>
                                        <div class="value"><?= (int)$comps['count'] ?></div>
                                    </div>
                                </div>

                                <div class="market-samples">
                                    <?php foreach ($comps['samples'] as $s): ?>
                                        <span class="market-sample">
                                            <?= number_format((int)$s['happy']) ?> happy • <?= formatCurrency((int)$s['per_day']) ?>/d • <?= (int)$s['days'] ?>d
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($rentedProperties)): ?>
        <div class="section-header">
            <h2>Currently Rented</h2>
            <span class="section-count"><?= count($rentedProperties) ?> property/properties</span>
        </div>

        <div class="properties-grid">
            <?php foreach ($rentedProperties as $property): ?>
                <div class="property-card">
                    <div class="property-header">
                        <?php $pid = $property['id'] ?? null; ?>
                        <div class="property-id">
                            ID:
                            <?php if (!empty($pid) && is_numeric($pid)): ?>
                                <a href="https://www.torn.com/properties.php#/p=propertyinfo&profile=1&ID=<?= (int)$pid ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)$pid) ?></a>
                            <?php else: ?>
                                <?= htmlspecialchars((string)($property['id'] ?? 'N/A')) ?>
                            <?php endif; ?>
                        </div>
                        <span class="status-badge <?= getStatusClass((string)($property['status'] ?? 'unknown')) ?>">
                            <?= getStatusLabel((string)($property['status'] ?? 'unknown')) ?>
                        </span>
                    </div>

                    <div class="property-info">
                        <div class="info-item">
                            <span class="info-label">Happy</span>
                            <span class="info-value"><?= number_format((int)($property['happy'] ?? 0)) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Property Upkeep</span>
                            <span class="info-value"><?= formatCurrency($property['upkeep']['property'] ?? 0) ?></span>
                        </div>
                    </div>

                    <?php if (!empty($property['modifications'])): ?>
                        <div class="section-title">Modifications</div>
                        <div class="modifications">
                            <?php foreach ($property['modifications'] as $mod): ?>
                                <span class="badge"><?= htmlspecialchars((string)$mod) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($property['rented'])): ?>
                        <div class="section-title">Rental Information</div>
                        <div class="property-info">
                            <div class="info-item">
                                <span class="info-label">Rented By</span>
                                <span class="info-value"><?= htmlspecialchars((string)($property['rented_by']['name'] ?? 'N/A')) ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Cost Per Day</span>
                                <span class="info-value"><?= formatCurrency($property['cost_per_day'] ?? 0) ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Days Remaining</span>
                                <span class="info-value"><?= (int)($property['rental_period_remaining'] ?? 0) ?> days</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Total Cost</span>
                                <span class="info-value"><?= formatCurrency($property['cost'] ?? 0) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
</body>
</html>
