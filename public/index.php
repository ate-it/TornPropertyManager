<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/TornApi.php';

$envPath = __DIR__ . '/../.env';
if (!file_exists($envPath)) {
    http_response_code(500);
    echo "Missing .env";
    exit;
}

$env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
$key = $env['TORN_API_KEY'] ?? '';

if ($key === '' || $key === 'put-your-key-here') {
    http_response_code(500);
    echo "Set TORN_API_KEY in .env";
    exit;
}

$api = new TornApi($key);

// Fetch user's owned properties
$userData = $api->get('/user/', ['selections' => 'properties']);
$allProperties = $userData['properties'] ?? [];

// Filter for Private Islands (property type ID 13) and exclude "in_use" properties
$privateIslands = [];

if (is_array($allProperties)) {
    foreach ($allProperties as $property) {
        // Check if it's a Private Island (property type ID 13)
        $propertyTypeId = $property['property']['id'] ?? null;
        $status = $property['status'] ?? '';
        
        // Only include Private Islands that are not in use
        if ((int)$propertyTypeId === 13 && $status !== 'in_use') {
            $privateIslands[] = $property;
        }
    }
}

// Sort properties: available to rent (status: "none") first, then rented
usort($privateIslands, function($a, $b) {
    $statusA = $a['status'] ?? '';
    $statusB = $b['status'] ?? '';
    
    // "none" (available to rent) comes first
    if ($statusA === 'none' && $statusB !== 'none') {
        return -1;
    }
    if ($statusA !== 'none' && $statusB === 'none') {
        return 1;
    }
    
    return 0;
});

// Helper function to format currency
function formatCurrency($amount) {
    return '$' . number_format($amount);
}

// Helper function to get status badge color
function getStatusClass($status) {
    return match($status) {
        'none' => 'status-available',
        'in_use' => 'status-occupied',
        'rented' => 'status-rented',
        default => 'status-unknown',
    };
}

// Helper function to get status label
function getStatusLabel($status) {
    return match($status) {
        'none' => 'Available to Rent',
        'in_use' => 'In Use',
        'rented' => 'Rented',
        default => ucfirst($status),
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Private Island Properties</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .header h1 {
            color: #333;
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .header .count {
            color: #666;
            font-size: 1.2em;
        }
        
        .section-header {
            background: white;
            border-radius: 12px;
            padding: 20px 30px;
            margin: 30px 0 20px 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-header h2 {
            color: #333;
            font-size: 1.8em;
            margin: 0;
        }
        
        .section-header .section-count {
            color: #666;
            font-size: 1em;
            font-weight: 500;
        }
        
        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
        }
        
        .property-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .property-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        
        .property-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .property-id {
            font-size: 0.9em;
            color: #999;
            font-weight: 500;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-available {
            background: #d4edda;
            color: #155724;
        }
        
        .status-occupied {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-rented {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .property-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 0.85em;
            color: #666;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-size: 1.1em;
            font-weight: 600;
            color: #333;
        }
        
        .section-title {
            font-size: 1em;
            font-weight: 600;
            color: #333;
            margin: 20px 0 10px 0;
            padding-top: 15px;
            border-top: 2px solid #f0f0f0;
        }
        
        .modifications, .staff {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .badge {
            background: #e9ecef;
            color: #495057;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.85em;
        }
        
        .rental-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .rental-info .info-label {
            color: #495057;
        }
        
        .used-by {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        
        .user-badge {
            background: #667eea;
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85em;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏝️ Private Island Properties</h1>
            <div class="count">Total: <?= count($privateIslands) ?> Private Island(s)</div>
        </div>
        
        <?php 
        // Separate properties into groups
        $availableProperties = array_filter($privateIslands, fn($p) => ($p['status'] ?? '') === 'none');
        $rentedProperties = array_filter($privateIslands, fn($p) => ($p['status'] ?? '') === 'rented');
        ?>
        
        <?php if (!empty($availableProperties)): ?>
            <div class="section-header">
                <h2>Available to Rent</h2>
                <span class="section-count"><?= count($availableProperties) ?> property/properties</span>
            </div>
            <div class="properties-grid">
                <?php foreach ($availableProperties as $property): ?>
                    <div class="property-card">
                    <div class="property-header">
                        <div>
                            <div class="property-id">ID: <?= htmlspecialchars((string)($property['id'] ?? 'N/A')) ?></div>
                        </div>
                        <span class="status-badge <?= getStatusClass($property['status'] ?? 'unknown') ?>">
                            <?= getStatusLabel($property['status'] ?? 'unknown') ?>
                        </span>
                    </div>
                    
                    <div class="property-info">
                        <div class="info-item">
                            <span class="info-label">Happy</span>
                            <span class="info-value"><?= number_format($property['happy'] ?? 0) ?></span>
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
                                <span class="badge"><?= htmlspecialchars($mod) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($property['staff'])): ?>
                        <div class="section-title">Staff</div>
                        <div class="staff">
                            <?php foreach ($property['staff'] as $staff): ?>
                                <span class="badge"><?= htmlspecialchars($staff['type']) ?>: <?= $staff['amount'] ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($property['used_by'])): ?>
                        <div class="section-title">Currently Rented By</div>
                        <div class="used-by">
                            <?php foreach ($property['used_by'] as $user): ?>
                                <span class="user-badge"><?= htmlspecialchars($user['name']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($property['rented'])): ?>
                        <div class="rental-info">
                            <div class="section-title">Rental Information</div>
                            <div class="property-info">
                                <div class="info-item">
                                    <span class="info-label">Rented By</span>
                                    <span class="info-value"><?= htmlspecialchars($property['rented_by']['name'] ?? 'N/A') ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Cost Per Day</span>
                                    <span class="info-value"><?= formatCurrency($property['cost_per_day'] ?? 0) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Days Remaining</span>
                                    <span class="info-value"><?= $property['rental_period_remaining'] ?? 0 ?> days</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Total Cost</span>
                                    <span class="info-value"><?= formatCurrency($property['cost'] ?? 0) ?></span>
                                </div>
                            </div>
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
                            <div>
                                <div class="property-id">ID: <?= htmlspecialchars((string)($property['id'] ?? 'N/A')) ?></div>
                            </div>
                            <span class="status-badge <?= getStatusClass($property['status'] ?? 'unknown') ?>">
                                <?= getStatusLabel($property['status'] ?? 'unknown') ?>
                            </span>
                        </div>
                        
                        <div class="property-info">
                            <div class="info-item">
                                <span class="info-label">Happy</span>
                                <span class="info-value"><?= number_format($property['happy'] ?? 0) ?></span>
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
                                    <span class="badge"><?= htmlspecialchars($mod) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($property['staff'])): ?>
                            <div class="section-title">Staff</div>
                            <div class="staff">
                                <?php foreach ($property['staff'] as $staff): ?>
                                    <span class="badge"><?= htmlspecialchars($staff['type']) ?>: <?= $staff['amount'] ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($property['used_by'])): ?>
                            <div class="section-title">Currently Rented By</div>
                            <div class="used-by">
                                <?php foreach ($property['used_by'] as $user): ?>
                                    <span class="user-badge"><?= htmlspecialchars($user['name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($property['rented'])): ?>
                            <div class="rental-info">
                                <div class="section-title">Rental Information</div>
                                <div class="property-info">
                                    <div class="info-item">
                                        <span class="info-label">Rented By</span>
                                        <span class="info-value"><?= htmlspecialchars($property['rented_by']['name'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Cost Per Day</span>
                                        <span class="info-value"><?= formatCurrency($property['cost_per_day'] ?? 0) ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Days Remaining</span>
                                        <span class="info-value"><?= $property['rental_period_remaining'] ?? 0 ?> days</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Total Cost</span>
                                        <span class="info-value"><?= formatCurrency($property['cost'] ?? 0) ?></span>
                                    </div>
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
